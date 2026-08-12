<?php

declare(strict_types=1);

use App\Enums\PersonalRecordType;
use App\Models\Achievement;
use App\Models\Exercise;
use App\Models\NotificationPreference;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Notifications\AchievementUnlocked;
use App\Notifications\PersonalRecordAchieved;
use App\Notifications\TrainingReminder;
use NotificationChannels\WebPush\WebPushChannel;
use PHPUnit\Framework\Assert;

/*
 * via() decides whether the user's phone buzzes. It is gated on the
 * `is_push_enabled` column of the NotificationPreference row matching the
 * notification's own type key. Both branches are asserted for each
 * notification: a regression in either direction is silent — too few channels
 * means no push ever, too many means pushing to users who opted out.
 */

describe('PersonalRecordAchieved::via', function (): void {
    it('sends to the database only when the user has no preference row at all', function (): void {
        $user = User::factory()->create();
        $record = PersonalRecord::factory()->create(['user_id' => $user->id]);

        $channels = new PersonalRecordAchieved($record)->via($user);

        expect($channels)->toBe(['database'])
            ->and($channels)->not->toContain(WebPushChannel::class);
    });

    it('adds the web push channel when push is enabled for personal records', function (): void {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'personal_record',
            'is_enabled' => true,
            'is_push_enabled' => true,
            'value' => null,
        ]);
        $record = PersonalRecord::factory()->create(['user_id' => $user->id]);

        expect(new PersonalRecordAchieved($record)->via($user))
            ->toBe(['database', WebPushChannel::class]);
    });

    it('withholds the web push channel when the in-app preference is on but push is off', function (): void {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'personal_record',
            'is_enabled' => true,
            'is_push_enabled' => false,
            'value' => null,
        ]);
        $record = PersonalRecord::factory()->create(['user_id' => $user->id]);

        $channels = new PersonalRecordAchieved($record)->via($user);

        expect($channels)->toBe(['database'])
            ->and($channels)->not->toContain(WebPushChannel::class);
    });

    it('gates push on is_push_enabled alone, independently of the in-app preference', function (): void {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'personal_record',
            'is_enabled' => false,
            'is_push_enabled' => true,
            'value' => null,
        ]);
        $record = PersonalRecord::factory()->create(['user_id' => $user->id]);

        expect(new PersonalRecordAchieved($record)->via($user))
            ->toBe(['database', WebPushChannel::class]);
    });

    it('does not let another type\'s push opt-in turn on personal record push', function (): void {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'training_reminder',
            'is_enabled' => true,
            'is_push_enabled' => true,
            'value' => null,
        ]);
        $record = PersonalRecord::factory()->create(['user_id' => $user->id]);

        expect(new PersonalRecordAchieved($record)->via($user))->toBe(['database']);
    });

    it('does not let another user\'s push opt-in turn on push for this user', function (): void {
        $user = User::factory()->create();
        $other = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $other->id,
            'type' => 'personal_record',
            'is_enabled' => true,
            'is_push_enabled' => true,
            'value' => null,
        ]);
        $record = PersonalRecord::factory()->create(['user_id' => $user->id]);

        expect(new PersonalRecordAchieved($record)->via($user))->toBe(['database']);
    });

    it('reads an eager-loaded preference relation the same way it reads the database', function (): void {
        // TrainingReminderCommand hydrates notificationPreferences to avoid an N+1,
        // which takes the in-memory branch of User::isPushEnabled(). Both branches
        // must agree or the scheduled command silently stops pushing.
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'personal_record',
            'is_enabled' => true,
            'is_push_enabled' => true,
            'value' => null,
        ]);
        $record = PersonalRecord::factory()->create(['user_id' => $user->id]);

        $hydrated = User::query()->with('notificationPreferences')->findOrFail($user->id);
        expect($hydrated->relationLoaded('notificationPreferences'))->toBeTrue();

        expect(new PersonalRecordAchieved($record)->via($hydrated))
            ->toBe(['database', WebPushChannel::class]);
    });

    it('withholds push from an eager-loaded relation whose push flag is off', function (): void {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'personal_record',
            'is_enabled' => true,
            'is_push_enabled' => false,
            'value' => null,
        ]);
        $record = PersonalRecord::factory()->create(['user_id' => $user->id]);

        $hydrated = User::query()->with('notificationPreferences')->findOrFail($user->id);

        expect(new PersonalRecordAchieved($record)->via($hydrated))->toBe(['database']);
    });
});

describe('TrainingReminder::via', function (): void {
    it('sends to the database only when push is disabled', function (): void {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'training_reminder',
            'is_enabled' => true,
            'is_push_enabled' => false,
            'value' => null,
        ]);

        expect(new TrainingReminder()->via($user))->toBe(['database']);
    });

    it('adds the web push channel when push is enabled for training reminders', function (): void {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'training_reminder',
            'is_enabled' => true,
            'is_push_enabled' => true,
            'value' => null,
        ]);

        expect(new TrainingReminder()->via($user))->toBe(['database', WebPushChannel::class]);
    });

    it('reads an eager-loaded preference relation, as the reminder command hydrates it', function (): void {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'training_reminder',
            'is_enabled' => true,
            'is_push_enabled' => true,
            'value' => null,
        ]);

        $hydrated = User::query()->with('notificationPreferences')->findOrFail($user->id);

        expect(new TrainingReminder()->via($hydrated))->toBe(['database', WebPushChannel::class]);
    });
});

describe('AchievementUnlocked::via', function (): void {
    it('sends to the database only when push is disabled', function (): void {
        $user = User::factory()->create();
        $achievement = Achievement::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'achievement',
            'is_enabled' => true,
            'is_push_enabled' => false,
            'value' => null,
        ]);

        expect(new AchievementUnlocked($achievement)->via($user))->toBe(['database']);
    });

    it('adds the web push channel when push is enabled for achievements', function (): void {
        $user = User::factory()->create();
        $achievement = Achievement::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'achievement',
            'is_enabled' => true,
            'is_push_enabled' => true,
            'value' => null,
        ]);

        expect(new AchievementUnlocked($achievement)->via($user))
            ->toBe(['database', WebPushChannel::class]);
    });

    it('is gated on the "achievement" key, not the "achievement_unlocked" key the preferences form accepts', function (): void {
        // Characterisation test for a real mismatch: UpdateNotificationPreferencesRequest
        // whitelists "achievement_unlocked", while AchievementUnlocked::via() asks for
        // "achievement". A row saved through the profile form therefore never turns
        // achievement push on. Change the key on one side and this test tells you.
        $user = User::factory()->create();
        $achievement = Achievement::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'achievement_unlocked',
            'is_enabled' => true,
            'is_push_enabled' => true,
            'value' => null,
        ]);

        expect(new AchievementUnlocked($achievement)->via($user))->toBe(['database']);
    });
});

describe('the enum-typed record survives the round trip into via()', function (): void {
    it('routes a max weight record exactly like any other type', function (): void {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'personal_record',
            'is_enabled' => true,
            'is_push_enabled' => true,
            'value' => null,
        ]);
        $record = PersonalRecord::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => Exercise::factory()->create(['name' => 'Squat'])->id,
            'type' => PersonalRecordType::MaxWeight,
        ]);

        $reloaded = $record->fresh();
        Assert::assertNotNull($reloaded, 'the personal record was not persisted');

        expect($reloaded->type)->toBe(PersonalRecordType::MaxWeight)
            ->and(new PersonalRecordAchieved($record)->via($user))
            ->toBe(['database', WebPushChannel::class]);
    });
});
