<?php

declare(strict_types=1);

use App\Models\BodyMeasurement;
use App\Models\BodyPartMeasurement;
use App\Models\DailyJournal;
use App\Models\Fast;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\User;
use App\Models\WaterLog;
use App\Models\Workout;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

/*
 * Une date envoyée avec un décalage (`Z`, `+02:00`) désigne un instant, et
 * l'application lit tout instant dans son fuseau (#1952).
 *
 * Les réglages de séance et l'ajout d'eau envoient une heure en UTC. La règle
 * `date` l'acceptait, puis le cast `datetime` n'en écrivait que l'heure murale,
 * relue ensuite comme une heure de Paris : la séance reculait de deux heures à
 * chaque enregistrement des réglages, et le verre bu à 00 h 30 comptait pour la
 * veille. Chaque champ daté que reçoit une requête est ici rejoué avec un
 * instant qui franchit minuit : 22 h 30 UTC le 4, soit 00 h 30 le 5 à Paris.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'Europe/Paris'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * L'instant d'une date relue, en UTC, pour le comparer à celui qui a été envoyé.
 */
function datesDecaleesEnUtc(mixed $date): string
{
    assert($date instanceof CarbonInterface);

    return $date->toIso8601ZuluString();
}

/**
 * Le jour d'une date relue, dans le fuseau de l'application.
 */
function datesDecaleesJour(mixed $date): string
{
    assert($date instanceof CarbonInterface);

    return $date->toDateString();
}

describe('un instant', function (): void {
    it('relit le début de séance envoyé en UTC au même instant, le jour de Paris', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => Carbon::parse('2026-10-04 20:00:00'),
        ]);

        $this->actingAs($user)
            ->patch(route('workouts.update', $workout), ['started_at' => '2026-10-04T22:30:00.000Z'])
            ->assertRedirect();

        $relue = $workout->refresh()->started_at;

        expect($relue->toIso8601ZuluString())->toBe('2026-10-04T22:30:00Z')
            ->and($relue->toDateString())->toBe('2026-10-05')
            ->and($workout->getRawOriginal('started_at'))->toBe('2026-10-05 00:30:00');
    });

    it('laisse le début de séance en place quand les réglages renvoient son heure telle quelle', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => Carbon::parse('2026-10-05 10:30:00'),
        ]);

        // Ce que renvoie le formulaire des réglages pour un simple renommage.
        $this->actingAs($user)
            ->patch(route('workouts.update', $workout), [
                'name' => 'Jambes',
                'started_at' => '2026-10-05T08:30:00.000Z',
                'notes' => '',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->patch(route('workouts.update', $workout), [
                'name' => 'Jambes lourdes',
                'started_at' => '2026-10-05T08:30:00.000Z',
                'notes' => '',
            ])
            ->assertRedirect();

        expect($workout->refresh()->name)->toBe('Jambes lourdes')
            ->and($workout->started_at->format('Y-m-d H:i:s'))->toBe('2026-10-05 10:30:00');
    });

    it('accepte toujours une heure sans décalage comme une heure de Paris', function (): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->patch(route('workouts.update', $workout), ['started_at' => '2026-10-05T00:30'])
            ->assertRedirect();

        expect($workout->refresh()->started_at->toIso8601String())->toBe('2026-10-05T00:30:00+02:00');
    });

    it('compte dans le total du jour le verre bu à 00 h 30, envoyé en UTC', function (): void {
        $user = User::factory()->create();
        Carbon::setTestNow(Carbon::parse('2026-10-05 00:30:00', 'Europe/Paris'));

        $this->actingAs($user)
            ->post(route('tools.water.store'), ['amount' => 250, 'consumed_at' => '2026-10-04T22:30:00.000Z'])
            ->assertRedirect();

        $verre = WaterLog::query()->where('user_id', $user->id)->sole();

        expect(datesDecaleesEnUtc($verre->consumed_at))->toBe('2026-10-04T22:30:00Z');

        $this->actingAs($user)
            ->get(route('tools.water.index'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('todayTotal', 250)
                ->where('logs.0.id', $verre->id));
    });

    it('relit le début d’un jeûne envoyé en UTC au même instant', function (): void {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->post(route('tools.fasting.store'), [
                'start_time' => '2026-10-04T22:30:00Z',
                'target_duration_minutes' => 960,
                'type' => '16:8',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $jeune = Fast::query()->where('user_id', $user->id)->sole();

        expect(datesDecaleesEnUtc($jeune->start_time))->toBe('2026-10-04T22:30:00Z');
    });

    it('relit le début et la fin d’un jeûne corrigés en UTC au même instant, le jour de Paris', function (): void {
        $user = User::factory()->create();
        $jeune = Fast::factory()->create([
            'user_id' => $user->id,
            'start_time' => Carbon::parse('2026-10-04 12:00:00'),
        ]);

        $this->actingAs($user)
            ->patch(route('tools.fasting.update', $jeune), [
                'start_time' => '2026-10-04T22:00:00Z',
                'end_time' => '2026-10-04T23:45:00.000Z',
                'status' => 'completed',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $jeune->refresh();

        expect(datesDecaleesEnUtc($jeune->start_time))->toBe('2026-10-04T22:00:00Z')
            ->and(datesDecaleesJour($jeune->start_time))->toBe('2026-10-05')
            ->and(datesDecaleesEnUtc($jeune->end_time))->toBe('2026-10-04T23:45:00Z')
            ->and(datesDecaleesJour($jeune->end_time))->toBe('2026-10-05');
    });

    it('ramène aussi un décalage numérique ou un nom de fuseau au même instant', function (string $envoye): void {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->patch(route('workouts.update', $workout), ['started_at' => $envoye])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(datesDecaleesEnUtc($workout->refresh()->started_at))->toBe('2026-10-04T22:30:00Z');
    })->with([
        'UTC en clair' => '2026-10-04T22:30:00+00:00',
        'à l’ouest' => '2026-10-04T18:30:00-04:00',
        'sans deux-points' => '2026-10-05T00:30:00+0200',
        'un nom de fuseau' => '2026-10-04 23:30:00 Europe/London',
    ]);
});

describe('un jour', function (): void {
    it('range le journal au jour de Paris de l’instant reçu', function (): void {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->post(route('daily-journals.store'), ['date' => '2026-10-04T22:30:00Z', 'content' => 'Bien dormi'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $journal = DailyJournal::query()->where('user_id', $user->id)->sole();

        expect(datesDecaleesJour($journal->date))->toBe('2026-10-05');
    });

    it('coche l’habitude au jour de Paris de l’instant reçu', function (): void {
        $user = User::factory()->create();
        $habitude = Habit::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('habits.toggle', $habitude), ['date' => '2026-10-04T22:30:00Z'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(datesDecaleesJour($habitude->logs()->sole()->date))->toBe('2026-10-05');
    });

    it('date la pesée du jour de Paris de l’instant reçu', function (): void {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->post(route('body-measurements.store'), ['weight' => 80, 'measured_at' => '2026-10-04T22:30:00Z'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $pesee = BodyMeasurement::query()->where('user_id', $user->id)->sole();

        expect(datesDecaleesJour($pesee->measured_at))->toBe('2026-10-05');
    });

    it('date la mensuration du jour de Paris de l’instant reçu, sans la croire future', function (): void {
        $user = User::factory()->create();
        Carbon::setTestNow(Carbon::parse('2026-10-05 01:00:00', 'Europe/Paris'));

        $this->actingAs($user)
            ->post(route('body-parts.store'), [
                'part' => 'Biceps',
                'value' => 38.5,
                'unit' => 'cm',
                'measured_at' => '2026-10-04T22:30:00Z',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $mesure = BodyPartMeasurement::query()->where('user_id', $user->id)->sole();

        expect(datesDecaleesJour($mesure->measured_at))->toBe('2026-10-05');
    });

    it('fixe l’échéance d’un objectif au jour de Paris de l’instant reçu, à la création comme à la modification', function (): void {
        $user = User::factory()->create();
        $champs = ['title' => 'Courir', 'type' => 'frequency', 'target_value' => 12];

        $this->actingAs($user)
            ->post(route('goals.store'), [...$champs, 'deadline' => '2026-10-09T22:30:00Z'])
            ->assertRedirect(route('goals.index'))
            ->assertSessionHasNoErrors();

        $objectif = Goal::query()->where('user_id', $user->id)->sole();

        expect(datesDecaleesJour($objectif->deadline))->toBe('2026-10-10');

        $this->actingAs($user)
            ->patch(route('goals.update', $objectif), [...$champs, 'deadline' => '2026-10-19T23:15:00+00:00'])
            ->assertRedirect(route('goals.index'))
            ->assertSessionHasNoErrors();

        expect(datesDecaleesJour($objectif->refresh()->deadline))->toBe('2026-10-20');
    });
});

it('laisse à la règle de validation une valeur qu’elle refuse, même suivie d’un décalage', function (string $envoye): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-10-05 08:00:00'),
    ]);

    $this->actingAs($user)
        ->patch(route('workouts.update', $workout), ['started_at' => $envoye])
        ->assertSessionHasErrors('started_at');

    expect($workout->refresh()->started_at->format('Y-m-d H:i:s'))->toBe('2026-10-05 08:00:00');
})->with([
    'du texte' => 'pas une date Z',
    'un jour qui n’existe pas' => '2026-02-30T22:30:00Z',
    'un instant relatif' => 'now Z',
]);
