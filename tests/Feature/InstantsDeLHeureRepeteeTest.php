<?php

declare(strict_types=1);

use App\Models\Fast;
use App\Models\User;
use App\Models\Workout;
use App\Services\Stats\WorkoutStatsService;
use App\Support\HeureDeLAppareil;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;

/*
 * Un instant pris dans la première occurrence de l'heure que le passage à
 * l'heure d'hiver répète se relit à cet instant (#2159).
 *
 * Le fuseau de l'application (`Europe/Paris`) répète 02:00–03:00 le
 * 25 octobre 2026 : la première occurrence va de 00:00Z à 01:00Z (`+02:00`),
 * la seconde de 01:00Z à 02:00Z (`+01:00`). Les instants s'écrivent à l'heure
 * murale, que les deux occurrences partagent, et Eloquent relisait toujours la
 * seconde : une séance commencée à 00:30Z et terminée à 01:10Z était ramenée à
 * son début relu (01:30Z) et enregistrée pour 0 min, et un jeûne commencé à
 * 00:30Z se servait à 01:30Z, d'où un compteur parti de `-1:00:00` et seize
 * heures comptées quinze. Le début et la fin d'une séance et d'un jeûne
 * s'écrivent désormais avec leur décalage (`InstantAvecSonDecalage`).
 *
 * Aucun cas ne pose la première occurrence par `Carbon::setTestNow()` : Carbon
 * rebâtit le maintenant simulé depuis son heure murale, et `now()` rendrait la
 * seconde (mesuré dans l'issue). L'instant de la première vient d'un envoi qui
 * porte son décalage, d'un âge du geste retranché d'un maintenant pris dans la
 * seconde, ou d'un `Carbon` construit à cet instant.
 */

beforeEach(function (): void {
    expect(config('app.timezone'))->toBe('Europe/Paris');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Un instant écrit en UTC, dans le fuseau de l'application, construit à cet
 * instant : la première occurrence garde son décalage (`+02:00`).
 */
function heureRepeteeInstant(string $utc): Carbon
{
    return Carbon::parse($utc)->setTimezone(config()->string('app.timezone'));
}

/**
 * Arrête l'horloge à un instant écrit en UTC, et vérifie que `now()` le rend :
 * un instant de la première occurrence ne le serait pas (voir l'en-tête).
 */
function heureRepeteeHorlogeA(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc));

    expect(now()->toJSON())->toBe(Carbon::parse($utc)->toJSON(), "l'horloge simulée ne rend pas {$utc} : un instant de la première occurrence se pose autrement");
}

/**
 * La durée d'une séance relue, en minutes entières.
 */
function heureRepeteeMinutes(Workout $seance): int
{
    expect($seance->ended_at)->not->toBeNull();

    return (int) $seance->started_at->diffInMinutes($seance->ended_at);
}

/**
 * Les secondes entre deux instants servis à une page (`…Z`).
 */
function heureRepeteeSecondesEntre(mixed $debut, mixed $fin): int
{
    expect($debut)->toBeString()->and($fin)->toBeString();
    assert(is_string($debut) && is_string($fin));

    return (int) Carbon::parse($debut)->diffInSeconds(Carbon::parse($fin));
}

/**
 * Envoie un formulaire comme la page : en JSON, avec les en-têtes d'Inertia.
 *
 * @param  array<string, mixed>  $corps
 * @return TestResponse<Response>
 */
function heureRepeteeEnvoyer(User $compte, string $methode, string $adresse, array $corps): TestResponse
{
    return actingAs($compte)->json($methode, $adresse, $corps, [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ]);
}

/**
 * Ce que la page du jeûne sert à ce chemin de ses props (`activeFast.start_time`,
 * `history.data.0.end_time`…), une seule ligne d'historique au plus.
 */
function heureRepeteeJeuneServi(User $compte, string $chemin): mixed
{
    $servi = null;

    actingAs($compte)->get(route('tools.fasting.index'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($chemin, &$servi): AssertableInertia {
            $servi = data_get($page->toArray(), "props.{$chemin}");

            return $page->has('history.data', fn (AssertableInertia $historique): AssertableInertia => $historique->etc());
        });

    return $servi;
}

/**
 * Une séance faite, sans passer par les écouteurs : par défaut commencée dans
 * la première occurrence et terminée quarante minutes plus tard.
 */
function heureRepeteeSeanceFaite(User $compte, string $debut = '2026-10-25T00:30:00Z', string $fin = '2026-10-25T01:10:00Z'): Workout
{
    return Workout::withoutEvents(fn (): Workout => Workout::factory()->create([
        'user_id' => $compte->id,
        'started_at' => heureRepeteeInstant($debut),
        'ended_at' => heureRepeteeInstant($fin),
    ]));
}

describe('une séance', function (): void {
    /*
     * La séance est posée par un `Carbon` construit à son début, comme
     * `now()` le rendrait à cet instant : le démarrage par la page ne passe
     * que par lui.
     */
    it('dure ce qui sépare son début de sa fin, terminée par la file', function (string $debut, string $fin, int $minutes): void {
        $compte = User::factory()->create();
        $seance = Workout::factory()->create([
            'user_id' => $compte->id,
            'started_at' => heureRepeteeInstant($debut),
            'ended_at' => null,
        ]);
        heureRepeteeHorlogeA($fin);

        actingAs($compte)->patchJson(route('api.v1.workouts.finish'), ['workout_id' => $seance->id])
            ->assertOk()
            ->assertJsonPath('data.started_at', Carbon::parse($debut)->toJSON())
            ->assertJsonPath('data.ended_at', Carbon::parse($fin)->toJSON());

        expect(heureRepeteeMinutes($seance->refresh()))->toBe($minutes)
            ->and($seance->started_at->toJSON())->toBe(Carbon::parse($debut)->toJSON())
            ->and($seance->ended_at?->toJSON())->toBe(Carbon::parse($fin)->toJSON());
    })->with([
        'commencée dans la première occurrence, terminée quarante minutes plus tard' => ['2026-10-25T00:30:00Z', '2026-10-25T01:10:00Z', 40],
        'commencée dans la première occurrence, terminée dans la seconde' => ['2026-10-25T00:30:00Z', '2026-10-25T01:45:00Z', 75],
        'commencée dans la première occurrence, terminée le matin' => ['2026-10-25T00:30:00Z', '2026-10-25T08:00:00Z', 450],
        'contre-épreuve : commencée dans la seconde occurrence' => ['2026-10-25T01:30:00Z', '2026-10-25T02:10:00Z', 40],
        'contre-épreuve : hors de l’heure répétée' => ['2026-10-24T22:30:00Z', '2026-10-24T23:10:00Z', 40],
    ]);

    /*
     * Démarrée sur l'appareil à 00:30Z et envoyée à 01:10Z, au retour du
     * réseau : le serveur place le début sur sa propre horloge, moins l'âge
     * du geste (`Workout::debutBorne()`), dans la première occurrence.
     */
    it('commence et finit par les routes de la file à l’instant du geste, dans la première occurrence', function (): void {
        $compte = User::factory()->create();
        heureRepeteeHorlogeA('2026-10-25T01:10:00Z');

        actingAs($compte)->postJson(
            route('api.v1.workouts.store'),
            ['started_at' => '2026-10-25T00:30:00.000Z'],
            ['Idempotency-Key' => '5d4c3b2a-2159-4f1e-9a8b-0000000000a1', HeureDeLAppareil::ENTETE => '2026-10-25T01:10:00.000Z'],
        )
            ->assertCreated()
            ->assertJsonPath('data.started_at', '2026-10-25T00:30:00.000000Z');

        $seance = Workout::query()->where('user_id', $compte->id)->sole();

        actingAs($compte)->patchJson(route('api.v1.workouts.finish'), ['workout_id' => $seance->id])
            ->assertOk()
            ->assertJsonPath('data.started_at', '2026-10-25T00:30:00.000000Z')
            ->assertJsonPath('data.ended_at', '2026-10-25T01:10:00.000000Z');

        expect(heureRepeteeMinutes($seance->refresh()))->toBe(40)
            ->and($seance->getRawOriginal('started_at'))->toBe('2026-10-25 02:30:00')
            ->and($seance->getRawOriginal('ended_at'))->toBe('2026-10-25 02:10:00');
    });

    it('sert à cet instant un début réglé dans la première occurrence', function (string $envoye, string $servi): void {
        $compte = User::factory()->create();
        $seance = Workout::factory()->create([
            'user_id' => $compte->id,
            'started_at' => heureRepeteeInstant('2026-10-24T20:00:00Z'),
            'ended_at' => null,
        ]);
        heureRepeteeHorlogeA('2026-10-25T03:00:00Z');

        heureRepeteeEnvoyer($compte, 'PATCH', route('workouts.update', $seance), ['started_at' => $envoye])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        actingAs($compte)->get(route('workouts.show', $seance))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('workout.started_at', $servi));
    })->with([
        'dans la première occurrence' => ['2026-10-25T00:30:00.000Z', '2026-10-25T00:30:00.000000Z'],
        'contre-épreuve : dans la seconde occurrence' => ['2026-10-25T01:30:00.000Z', '2026-10-25T01:30:00.000000Z'],
        'contre-épreuve : hors de l’heure répétée' => ['2026-10-24T22:30:00.000Z', '2026-10-24T22:30:00.000000Z'],
    ]);

    /*
     * Une ligne écrite avant le correctif n'a pas de décalage : rien ne dit
     * quelle occurrence elle désignait, et elle se lit comme avant, dans la
     * seconde.
     */
    it('lit comme avant une séance écrite sans décalage', function (): void {
        $compte = User::factory()->create();
        $id = DB::table('workouts')->insertGetId([
            'user_id' => $compte->id,
            'name' => 'Ancienne',
            'started_at' => '2026-10-25 02:30:00',
            'created_at' => '2026-10-25 02:30:00',
            'updated_at' => '2026-10-25 02:30:00',
        ]);
        heureRepeteeHorlogeA('2026-10-25T01:45:00Z');

        actingAs($compte)->patchJson(route('api.v1.workouts.finish'), ['workout_id' => $id])
            ->assertOk()
            ->assertJsonPath('data.started_at', '2026-10-25T01:30:00.000000Z')
            ->assertJsonPath('data.ended_at', '2026-10-25T01:45:00.000000Z');

        expect(heureRepeteeMinutes(Workout::query()->findOrFail($id)))->toBe(15);
    });
});

describe('un jeûne', function (): void {
    it('se sert à l’instant de son début, et dure seize heures, terminé seize heures plus tard', function (string $debut, string $fin): void {
        $compte = User::factory()->create();
        heureRepeteeHorlogeA('2026-10-25T02:00:00Z');

        heureRepeteeEnvoyer($compte, 'POST', route('tools.fasting.store'), [
            'start_time' => $debut,
            'target_duration_minutes' => 960,
            'type' => '16:8',
        ])->assertRedirect()->assertSessionHasNoErrors();

        expect(heureRepeteeJeuneServi($compte, 'activeFast.start_time'))->toBe(Carbon::parse($debut)->toJSON());

        heureRepeteeEnvoyer($compte, 'PATCH', route('tools.fasting.update', Fast::query()->where('user_id', $compte->id)->sole()), [
            'end_time' => $fin,
            'status' => 'completed',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $debutServi = heureRepeteeJeuneServi($compte, 'history.data.0.start_time');
        $finServie = heureRepeteeJeuneServi($compte, 'history.data.0.end_time');

        expect(heureRepeteeJeuneServi($compte, 'history.data.1'))->toBeNull()
            ->and($debutServi)->toBe(Carbon::parse($debut)->toJSON())
            ->and($finServie)->toBe(Carbon::parse($fin)->toJSON())
            ->and(heureRepeteeSecondesEntre($debutServi, $finServie))->toBe(16 * 3600);
    })->with([
        'commencé dans la première occurrence' => ['2026-10-25T00:30:00.000Z', '2026-10-25T16:30:00.000Z'],
        'commencé la veille, terminé dans la première occurrence' => ['2026-10-24T08:30:00.000Z', '2026-10-25T00:30:00.000Z'],
        'contre-épreuve : commencé dans la seconde occurrence' => ['2026-10-25T01:30:00.000Z', '2026-10-25T17:30:00.000Z'],
        'contre-épreuve : hors de l’heure répétée' => ['2026-10-20T06:00:00.000Z', '2026-10-20T22:00:00.000Z'],
    ]);

    it('lit comme avant un jeûne écrit sans décalage', function (): void {
        $compte = User::factory()->create();
        DB::table('fasts')->insert([
            'user_id' => $compte->id,
            'start_time' => '2026-10-25 02:30:00',
            'end_time' => '2026-10-25 18:30:00',
            'target_duration_minutes' => 960,
            'type' => '16:8',
            'status' => 'completed',
        ]);

        expect(heureRepeteeJeuneServi($compte, 'history.data.0.start_time'))->toBe('2026-10-25T01:30:00.000000Z')
            ->and(heureRepeteeJeuneServi($compte, 'history.data.0.end_time'))->toBe('2026-10-25T17:30:00.000000Z');
    });
});

describe('ce que les pages servent d’une séance', function (): void {
    it('compte quarante minutes dans l’historique des durées', function (): void {
        $compte = User::factory()->create();
        heureRepeteeSeanceFaite($compte);

        $historique = app(WorkoutStatsService::class)->getDurationHistory($compte);

        expect($historique)->toHaveCount(1)
            ->and($historique[0]->duration)->toBe(40);
    });

    it('range la séance entre trente et soixante minutes dans la répartition des durées', function (): void {
        $compte = User::factory()->create();
        heureRepeteeSeanceFaite($compte);
        heureRepeteeHorlogeA('2026-10-25T09:00:00Z');

        $durees = app(WorkoutStatsService::class)->repartitionsDesSeances($compte)['duration'];

        expect(array_map(fn (\App\DTOs\Stats\DistributionStat $tranche): int => $tranche->count, $durees))->toBe([0, 1, 0, 0]);
    });

    /*
     * Commencée hors de l'heure répétée (01:50 à Paris, `+02:00`) et finie
     * dans sa première occurrence (02:50, `+02:00`) : seul le décalage de la
     * fin dit laquelle. Relue sans lui, la fin revenait à 01:50Z, et la
     * séance d'une heure en durait deux.
     */
    it('compte soixante minutes dans l’historique des durées d’une séance finie dans la première occurrence', function (): void {
        $compte = User::factory()->create();
        heureRepeteeSeanceFaite($compte, '2026-10-24T23:50:00Z', '2026-10-25T00:50:00Z');

        $historique = app(WorkoutStatsService::class)->getDurationHistory($compte);

        expect($historique)->toHaveCount(1)
            ->and($historique[0]->duration)->toBe(60);
    });

    it('range entre soixante et quatre-vingt-dix minutes une séance finie dans la première occurrence', function (): void {
        $compte = User::factory()->create();
        heureRepeteeSeanceFaite($compte, '2026-10-24T23:50:00Z', '2026-10-25T00:50:00Z');
        heureRepeteeHorlogeA('2026-10-25T09:00:00Z');

        $durees = app(WorkoutStatsService::class)->repartitionsDesSeances($compte)['duration'];

        expect(array_map(fn (\App\DTOs\Stats\DistributionStat $tranche): string => $tranche->label, $durees))->toBe(['< 30 min', '30-60 min', '60-90 min', '90+ min'])
            ->and(array_map(fn (\App\DTOs\Stats\DistributionStat $tranche): int => $tranche->count, $durees))->toBe([0, 0, 1, 0]);
    });

    it('place la séance du calendrier à l’heure de son début', function (): void {
        $compte = User::factory()->create();
        heureRepeteeSeanceFaite($compte);
        heureRepeteeHorlogeA('2026-10-25T09:00:00Z');

        actingAs($compte)->get(route('calendar.index', ['year' => 2026, 'month' => 10]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('workouts.0.started_at', '2026-10-25T02:30:00+02:00'));
    });

    it('sert le début de la séance en cours à son instant, dans le bandeau de chaque page', function (): void {
        $compte = User::factory()->create();
        Workout::factory()->create([
            'user_id' => $compte->id,
            'started_at' => heureRepeteeInstant('2026-10-25T00:30:00Z'),
            'ended_at' => null,
        ]);
        heureRepeteeHorlogeA('2026-10-25T01:10:00Z');

        actingAs($compte)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('auth.user.active_workout.started_at', '2026-10-25T00:30:00.000000Z'));
    });
});
