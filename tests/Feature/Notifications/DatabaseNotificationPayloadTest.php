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
use Illuminate\Support\Carbon;

/*
 * toArray() is what lands in the `notifications` table and what the in-app bell
 * renders. These tests assert the shape and the values, then send the
 * notification for real (no Notification::fake) so the payload is proven to
 * survive JSON encoding and the round trip back out of the database.
 *
 * "now" is frozen at a date distinct from every fixture date, so a regression
 * that swaps a record's own timestamp for now() fails instead of coinciding.
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-08-12 18:00:00'));
});

describe('PersonalRecordAchieved::toArray', function (): void {
    it('describes the record with its exercise, label and weight', function (): void {
        $user = User::factory()->create();
        $record = PersonalRecord::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => Exercise::factory()->create(['name' => 'Développé Couché'])->id,
            'type' => PersonalRecordType::MaxWeight,
            'value' => 102.5,
            'achieved_at' => Carbon::parse('2026-03-04 09:30:00'),
        ])->fresh();

        $data = new PersonalRecordAchieved($record)->toArray($user);

        expect($data['type'])->toBe('personal_record')
            ->and($data['title'])->toBe('Nouveau Record ! 🏆')
            ->and($data['message'])->toBe(
                "Félicitations ! Tu as battu ton record de Poids Maximum sur l'exercice Développé Couché avec 102.50kg."
            )
            ->and($data['exercise_id'])->toBe($record->exercise_id)
            ->and($data['achieved_at'])->toBeInstanceOf(Carbon::class);

        expect(array_keys($data))
            ->toEqualCanonicalizing(['type', 'title', 'message', 'exercise_id', 'achieved_at']);
    });

    it('reports the moment the record was set, not the moment of sending', function (): void {
        $user = User::factory()->create();
        $record = PersonalRecord::factory()->create([
            'user_id' => $user->id,
            'achieved_at' => Carbon::parse('2026-03-04 09:30:00'),
        ])->fresh();

        $achievedAt = new PersonalRecordAchieved($record)->toArray($user)['achieved_at'];

        expect($achievedAt->format('Y-m-d H:i:s'))->toBe('2026-03-04 09:30:00')
            ->and($achievedAt->format('Y-m-d H:i:s'))->not->toBe(now()->format('Y-m-d H:i:s'));
    });

    it('survives a real round trip through the notifications table', function (): void {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'personal_record',
            'is_enabled' => true,
            'is_push_enabled' => false,
            'value' => null,
        ]);
        $record = PersonalRecord::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => Exercise::factory()->create(['name' => 'Soulevé de Terre'])->id,
            'type' => PersonalRecordType::Max1RM,
            'value' => 187.25,
            'achieved_at' => Carbon::parse('2026-03-04 09:30:00'),
        ])->fresh();

        $user->notify(new PersonalRecordAchieved($record));

        $stored = $user->notifications()->sole();

        expect($stored->type)->toBe(PersonalRecordAchieved::class)
            ->and($stored->read_at)->toBeNull()
            ->and($stored->notifiable_id)->toBe($user->id)
            ->and($stored->data['type'])->toBe('personal_record')
            ->and($stored->data['title'])->toBe('Nouveau Record ! 🏆')
            ->and($stored->data['message'])->toBe(
                "Félicitations ! Tu as battu ton record de 1RM Estimé sur l'exercice Soulevé de Terre avec 187.25kg."
            )
            ->and($stored->data['exercise_id'])->toBe($record->exercise_id);

        // The JSON payload is serialised in UTC; read it back and compare the
        // instant, so the assertion is not hostage to the app timezone.
        expect(Carbon::parse($stored->data['achieved_at'])->setTimezone(config('app.timezone'))->toDateTimeString())
            ->toBe('2026-03-04 09:30:00')
            ->and(Carbon::parse($stored->data['achieved_at'])->toDateTimeString())
            ->not->toBe(now()->utc()->toDateTimeString());

        expect($user->unreadNotifications()->count())->toBe(1);
    });
});

describe('AchievementUnlocked::toArray', function (): void {
    it('describes the unlocked achievement', function (): void {
        $user = User::factory()->create();
        $achievement = Achievement::factory()->create(['name' => 'Marathonien du Fer']);

        $data = new AchievementUnlocked($achievement)->toArray($user);

        expect($data['type'])->toBe('achievement')
            ->and($data['title'])->toBe('Succès Déverrouillé ! 🏆')
            ->and($data['message'])->toBe('Félicitations ! Tu as déverrouillé le succès : Marathonien du Fer.')
            ->and($data['achievement_id'])->toBe($achievement->id)
            ->and($data['achieved_at']->format('Y-m-d H:i:s'))->toBe('2026-08-12 18:00:00');

        expect(array_keys($data))
            ->toEqualCanonicalizing(['type', 'title', 'message', 'achievement_id', 'achieved_at']);
    });

    it('survives a real round trip and is findable by the class the bell filters on', function (): void {
        $user = User::factory()->create();
        $achievement = Achievement::factory()->create(['name' => 'Première Séance']);

        $user->notify(new AchievementUnlocked($achievement));

        $stored = $user->unreadNotifications()
            ->where('type', AchievementUnlocked::class)
            ->sole();

        expect($stored->data['type'])->toBe('achievement')
            ->and($stored->data['title'])->toBe('Succès Déverrouillé ! 🏆')
            ->and($stored->data['message'])->toBe('Félicitations ! Tu as déverrouillé le succès : Première Séance.')
            ->and($stored->data['achievement_id'])->toBe($achievement->id);

        expect(Carbon::parse($stored->data['achieved_at'])->setTimezone(config('app.timezone'))->toDateTimeString())
            ->toBe('2026-08-12 18:00:00');
    });
});

describe('TrainingReminder::toArray', function (): void {
    it('uses the default message and the sending time', function (): void {
        $user = User::factory()->create();

        $data = new TrainingReminder()->toArray($user);

        expect($data['type'])->toBe('training_reminder')
            ->and($data['title'])->toBe("Rappel d'entraînement")
            ->and($data['message'])->toBe("C'est le moment de s'entraîner ! 💪")
            ->and($data['sent_at']->format('Y-m-d H:i:s'))->toBe('2026-08-12 18:00:00');

        expect(array_keys($data))
            ->toEqualCanonicalizing(['type', 'title', 'message', 'sent_at']);
    });

    it('keeps a custom message and does not overwrite it with the default', function (): void {
        $user = User::factory()->create();

        $data = new TrainingReminder('4 jours sans séance !')->toArray($user);

        expect($data['message'])->toBe('4 jours sans séance !')
            ->and($data['title'])->toBe("Rappel d'entraînement");
    });

    it('survives a real round trip through the notifications table', function (): void {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'training_reminder',
            'is_enabled' => true,
            'is_push_enabled' => false,
            'value' => null,
        ]);

        $user->notify(new TrainingReminder('4 jours sans séance !'));

        $stored = $user->notifications()->sole();

        expect($stored->type)->toBe(TrainingReminder::class)
            ->and($stored->data['type'])->toBe('training_reminder')
            ->and($stored->data['title'])->toBe("Rappel d'entraînement")
            ->and($stored->data['message'])->toBe('4 jours sans séance !');

        expect(Carbon::parse($stored->data['sent_at'])->setTimezone(config('app.timezone'))->toDateTimeString())
            ->toBe('2026-08-12 18:00:00');
    });
});

describe('the in-app notification is stored regardless of the push preference', function (): void {
    it('stores exactly one database row when push is enabled but no subscription exists', function (): void {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'training_reminder',
            'is_enabled' => true,
            'is_push_enabled' => true,
            'value' => null,
        ]);

        $user->notify(new TrainingReminder('On y retourne !'));

        expect($user->notifications()->count())->toBe(1)
            ->and($user->notifications()->sole()->data['message'])->toBe('On y retourne !');
    });
});
