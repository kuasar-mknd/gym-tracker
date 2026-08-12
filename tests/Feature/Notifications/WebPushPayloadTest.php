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
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;

/*
 * toWebPush() builds the payload the phone actually renders: title, body, icon
 * and the action button. Nothing renders it in production before delivery, so a
 * formatting regression (wrong label, wrong unit, wrong deep link) ships
 * unnoticed. These tests build the payload from fixed fixtures and assert every
 * key, then drive one payload end to end through the real WebPushChannel with
 * only the HTTP transport (Minishlink\WebPush) faked.
 */

/**
 * Builds a persisted personal record with fully known content.
 */
function makePersonalRecordFixture(PersonalRecordType $type, string $exerciseName, float $value): PersonalRecord
{
    $user = User::factory()->create();

    return PersonalRecord::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => Exercise::factory()->create(['name' => $exerciseName])->id,
        'type' => $type,
        'value' => $value,
        'achieved_at' => Carbon\CarbonImmutable::parse('2026-03-04 09:30:00'),
    ])->refresh();
}

describe('PersonalRecordAchieved::toWebPush', function (): void {
    it('renders the full push payload from the record', function (): void {
        $record = makePersonalRecordFixture(PersonalRecordType::MaxWeight, 'Développé Couché', 102.5);

        $payload = new PersonalRecordAchieved($record)->toWebPush($record->user, null)->toArray();

        expect($payload)->toEqual([
            'title' => 'Nouveau Record ! 🏆',
            'actions' => [
                ['title' => 'Voir mes stats', 'action' => url('/stats')],
            ],
            'body' => "Félicitations ! Tu as battu ton record de Poids Maximum sur l'exercice Développé Couché avec 102.50kg.",
            'icon' => '/logo.svg',
        ]);
    });

    it('puts the exercise name, the weight and the two decimals in the body', function (): void {
        $record = makePersonalRecordFixture(PersonalRecordType::MaxWeight, 'Soulevé de Terre', 187.25);

        $body = new PersonalRecordAchieved($record)->toWebPush($record->user, null)->toArray()['body'];

        expect($body)
            ->toContain('Soulevé de Terre')
            ->toContain('187.25kg');
        expect($body)->not->toContain('187.3');
        expect($body)->not->toContain('{');
    });

    it('labels every record type it knows about', function (PersonalRecordType $type, string $expectedLabel): void {
        $record = makePersonalRecordFixture($type, 'Rowing Barre', 60.0);

        $body = new PersonalRecordAchieved($record)->toWebPush($record->user, null)->toArray()['body'];

        expect($body)->toBe(
            "Félicitations ! Tu as battu ton record de {$expectedLabel} sur l'exercice Rowing Barre avec 60.00kg."
        );
    })->with([
        'max weight' => [PersonalRecordType::MaxWeight, 'Poids Maximum'],
        'estimated 1RM' => [PersonalRecordType::Max1RM, '1RM Estimé'],
        'best set volume' => [PersonalRecordType::MaxVolumeSet, 'Volume par Série'],
        'legacy strength falls back' => [PersonalRecordType::Strength, 'Record Personnel'],
        'legacy 1RM falls back' => [PersonalRecordType::OneRM, 'Record Personnel'],
        'legacy volume falls back' => [PersonalRecordType::Volume, 'Record Personnel'],
    ]);

    it('links the action to the stats page and nothing else', function (): void {
        $record = makePersonalRecordFixture(PersonalRecordType::Max1RM, 'Squat', 140.0);

        $actions = new PersonalRecordAchieved($record)->toWebPush($record->user, null)->toArray()['actions'];

        expect($actions)->toHaveCount(1)
            ->and($actions[0]['action'])->toBe(url('/stats'))
            ->and($actions[0]['action'])->toEndWith('/stats')
            ->and($actions[0]['title'])->toBe('Voir mes stats');
    });
});

describe('AchievementUnlocked::toWebPush', function (): void {
    it('renders the full push payload from the achievement', function (): void {
        $user = User::factory()->create();
        $achievement = Achievement::factory()->create(['name' => 'Marathonien du Fer']);

        $payload = new AchievementUnlocked($achievement)->toWebPush($user, null)->toArray();

        expect($payload)->toEqual([
            'title' => 'Succès Déverrouillé ! 🏆',
            'actions' => [
                ['title' => 'Voir mes succès', 'action' => url('/achievements')],
            ],
            'body' => 'Félicitations ! Tu as déverrouillé le succès : Marathonien du Fer.',
            'icon' => '/logo.svg',
        ]);
    });

    it('carries the achievement name through to the body', function (): void {
        $user = User::factory()->create();
        $achievement = Achievement::factory()->create(['name' => 'Première Séance']);

        $body = new AchievementUnlocked($achievement)->toWebPush($user, null)->toArray()['body'];

        expect($body)->toBe('Félicitations ! Tu as déverrouillé le succès : Première Séance.')
            ->and($body)->not->toContain('achievement');
    });
});

describe('TrainingReminder::toWebPush', function (): void {
    it('renders the default reminder payload', function (): void {
        $user = User::factory()->create();

        $payload = new TrainingReminder()->toWebPush($user, null)->toArray();

        expect($payload)->toEqual([
            'title' => 'Prêt pour ta séance ? 💪',
            'actions' => [
                ['title' => 'Ouvrir Gym Tracker', 'action' => url('/')],
            ],
            'body' => "C'est le moment de s'entraîner ! 💪",
            'icon' => '/logo.svg',
        ]);
    });

    it('uses the custom message as the body when one is given', function (): void {
        $user = User::factory()->create();

        $payload = new TrainingReminder('3 jours sans séance, on y retourne ?')->toWebPush($user, null)->toArray();

        expect($payload['body'])->toBe('3 jours sans séance, on y retourne ?')
            ->and($payload['title'])->toBe('Prêt pour ta séance ? 💪');
    });

    it('falls back to the default message when null is passed explicitly', function (): void {
        $user = User::factory()->create();

        expect(new TrainingReminder()->toWebPush($user, null)->toArray()['body'])
            ->toBe("C'est le moment de s'entraîner ! 💪");
    });
});

describe('end-to-end delivery through the real WebPushChannel', function (): void {
    it('queues the rendered JSON payload against the user\'s subscription', function (): void {
        $record = makePersonalRecordFixture(PersonalRecordType::MaxWeight, 'Développé Couché', 102.5);
        $user = $record->user;

        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'personal_record',
            'is_enabled' => true,
            'is_push_enabled' => true,
            'value' => null,
        ]);

        $user->updatePushSubscription(
            'https://fcm.googleapis.com/fcm/send/endpoint-e2e',
            'p256dh-key',
            'auth-token',
        );

        // Only the HTTP transport to the push service is faked; via(), the
        // channel and toWebPush() all run for real.
        $queued = [];
        $transport = Mockery::mock(WebPush::class);
        $transport->shouldReceive('queueNotification')
            ->andReturnUsing(function (Subscription $subscription, ?string $payload = null) use (&$queued): void {
                $queued[] = ['endpoint' => $subscription->getEndpoint(), 'payload' => $payload];
            });
        $transport->shouldReceive('flush')->andReturnUsing(function (): Generator {
            yield from [];
        });

        app()->when(WebPushChannel::class)->needs(WebPush::class)->give(fn (): WebPush => $transport);

        $user->notify(new PersonalRecordAchieved($record));

        expect($queued)->toHaveCount(1)
            ->and($queued[0]['endpoint'])->toBe('https://fcm.googleapis.com/fcm/send/endpoint-e2e');

        $decoded = json_decode((string) $queued[0]['payload'], true, 512, JSON_THROW_ON_ERROR);

        expect($decoded)->toEqual([
            'title' => 'Nouveau Record ! 🏆',
            'actions' => [
                ['title' => 'Voir mes stats', 'action' => url('/stats')],
            ],
            'body' => "Félicitations ! Tu as battu ton record de Poids Maximum sur l'exercice Développé Couché avec 102.50kg.",
            'icon' => '/logo.svg',
        ]);
    });

    it('never reaches the push transport when the user turned push off', function (): void {
        $record = makePersonalRecordFixture(PersonalRecordType::MaxWeight, 'Développé Couché', 102.5);
        $user = $record->user;

        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'personal_record',
            'is_enabled' => true,
            'is_push_enabled' => false,
            'value' => null,
        ]);

        $user->updatePushSubscription(
            'https://fcm.googleapis.com/fcm/send/endpoint-optout',
            'p256dh-key',
            'auth-token',
        );

        $transport = Mockery::mock(WebPush::class);
        $transport->shouldNotReceive('queueNotification');
        $transport->shouldNotReceive('flush');

        app()->when(WebPushChannel::class)->needs(WebPush::class)->give(fn (): WebPush => $transport);

        $user->notify(new PersonalRecordAchieved($record));

        // The in-app notification is still stored: only the push leg is skipped.
        expect($user->notifications()->count())->toBe(1);
    });
});
