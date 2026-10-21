<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use App\Support\HeureDeLAppareil;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;

/*
 * Une horloge d'appareil décalée ne déplace ni le début ni la fin d'une
 * séance (#2049, relu le 2026-10-21).
 *
 * « Séance », « Lancer » et « Terminer » envoient l'heure du geste prise sur
 * l'appareil, en ligne comme au rejeu de la file. Lue telle quelle, une
 * horloge en retard de trois heures faisait commencer la séance trois heures
 * trop tôt, et terminait à son début, d'une durée nulle, la séance qu'un autre
 * appareil, ou le serveur, avait démarrée ; sur `origin/main`, ces gestes en
 * ligne prenaient l'heure du serveur. Le serveur ne garde plus que l'âge du
 * geste au départ de la requête, lu sur la seule horloge de l'appareil
 * (l'en-tête `X-Heure-De-L-Appareil`, moins l'instant du geste), et le place
 * sur la sienne (`App\Support\HeureDeLAppareil`).
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-21 18:00:00');
});

/**
 * Trois heures, en secondes : le décalage des cas.
 */
function horlogeDecaleeTroisHeures(): int
{
    return 3 * 3600;
}

/**
 * Ce qu'affiche un appareil dont l'horloge a `$decalage` secondes d'avance
 * (négatif : de retard), `$avant` secondes avant maintenant, écrit comme
 * `toISOString()` l'écrit.
 */
function horlogeDecaleeSurLAppareil(int $decalage, int $avant = 0): string
{
    return now()->addSeconds($decalage - $avant)->utc()->format('Y-m-d\TH:i:s.v\Z');
}

/**
 * Démarre une séance par l'API, l'heure de l'appareil au départ dans son en-tête.
 *
 * @param  array<string, mixed>  $corps
 * @return TestResponse<Response>
 */
function horlogeDecaleeDemarrer(User $compte, array $corps, ?string $heureAuDepart): TestResponse
{
    $entetes = ['Idempotency-Key' => '5d4c3b2a-2049-4f1e-9a8b-0000000000a1'];

    if ($heureAuDepart !== null) {
        $entetes[HeureDeLAppareil::ENTETE] = $heureAuDepart;
    }

    return actingAs($compte)->postJson(route('api.v1.workouts.store'), $corps, $entetes);
}

/**
 * Termine une séance par l'API, l'heure de l'appareil au départ dans son en-tête.
 *
 * @param  array<string, mixed>  $corps
 * @return TestResponse<Response>
 */
function horlogeDecaleeTerminer(User $compte, array $corps, ?string $heureAuDepart): TestResponse
{
    return actingAs($compte)->patchJson(
        route('api.v1.workouts.finish'),
        $corps,
        $heureAuDepart === null ? [] : [HeureDeLAppareil::ENTETE => $heureAuDepart],
    );
}

/** Le début de la seule séance du compte. */
function horlogeDecaleeDebut(User $compte): string
{
    return Workout::query()->where('user_id', $compte->id)->sole()->started_at->format('Y-m-d H:i:s');
}

dataset('horloges d’appareil décalées', [
    'en retard de trois heures' => [-horlogeDecaleeTroisHeures()],
    'en avance de trois heures' => [horlogeDecaleeTroisHeures()],
    'à l’heure' => [0],
]);

it('démarre en ligne à l’heure du serveur, moins l’âge du geste, quelle que soit l’horloge de l’appareil', function (int $decalage): void {
    $compte = User::factory()->create();

    horlogeDecaleeDemarrer(
        $compte,
        ['started_at' => horlogeDecaleeSurLAppareil($decalage, avant: 2)],
        horlogeDecaleeSurLAppareil($decalage),
    )->assertCreated();

    expect(horlogeDecaleeDebut($compte))->toBe('2026-10-21 17:59:58');
})->with('horloges d’appareil décalées');

it('rejoue un démarrage fait deux heures plus tôt à l’heure du geste, quelle que soit l’horloge de l’appareil', function (int $decalage): void {
    $compte = User::factory()->create();

    horlogeDecaleeDemarrer(
        $compte,
        ['started_at' => horlogeDecaleeSurLAppareil($decalage, avant: 2 * 3600)],
        horlogeDecaleeSurLAppareil($decalage),
    )->assertCreated();

    expect(horlogeDecaleeDebut($compte))->toBe('2026-10-21 16:00:00');
})->with('horloges d’appareil décalées');

it('lance un modèle en ligne à l’heure du serveur depuis un appareil qui retarde', function (): void {
    $compte = User::factory()->create();
    $modele = WorkoutTemplate::factory()->create(['user_id' => $compte->id, 'name' => 'Dos']);

    horlogeDecaleeDemarrer(
        $compte,
        ['template_id' => $modele->id, 'started_at' => horlogeDecaleeSurLAppareil(-horlogeDecaleeTroisHeures(), avant: 1)],
        horlogeDecaleeSurLAppareil(-horlogeDecaleeTroisHeures()),
    )->assertCreated();

    expect(horlogeDecaleeDebut($compte))->toBe('2026-10-21 17:59:59');
});

/*
 * La séance a été démarrée par le serveur, ou par un autre appareil, une
 * heure plus tôt : l'heure du geste d'un appareil en retard de trois heures
 * précédait ce début, et la séance se fermait à lui, d'une durée nulle.
 */
it('termine en ligne une séance démarrée ailleurs à l’heure du serveur, sans la ramener à son début', function (int $decalage): void {
    $compte = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => '2026-10-21 17:00:00', 'ended_at' => null]);

    horlogeDecaleeTerminer(
        $compte,
        ['workout_id' => $seance->id, 'ended_at' => horlogeDecaleeSurLAppareil($decalage, avant: 1)],
        horlogeDecaleeSurLAppareil($decalage),
    )->assertOk();

    $seance->refresh();

    expect($seance->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-21 17:59:59')
        ->and((int) $seance->started_at->diffInSeconds($seance->ended_at))->toBe(3599);
})->with('horloges d’appareil décalées');

it('rejoue une fin faite quarante minutes plus tôt à l’heure du geste, quelle que soit l’horloge de l’appareil', function (int $decalage): void {
    $compte = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => '2026-10-21 16:00:00', 'ended_at' => null]);

    horlogeDecaleeTerminer(
        $compte,
        ['workout_id' => $seance->id, 'ended_at' => horlogeDecaleeSurLAppareil($decalage, avant: 40 * 60)],
        horlogeDecaleeSurLAppareil($decalage),
    )->assertOk();

    expect($seance->refresh()->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-21 17:20:00');
})->with('horloges d’appareil décalées');

/*
 * Sans l'heure de l'appareil au départ, l'instant du geste ne se compare à
 * rien : le serveur prend maintenant, comme sans instant. Une heure de départ
 * qui ne dit pas son fuseau, ou écrite en mots, se lirait sur l'horloge du
 * serveur : elle ne compte pas davantage. Une date suivie de mots relatifs
 * non plus (relu le 2026-10-21) : Carbon en appliquait le décalage, et la
 * fin d'une séance retombait sur son début.
 */
dataset('heures de départ que le serveur ne lit pas', [
    'absente' => [null],
    'sans fuseau' => ['2026-10-21 15:00:00'],
    'en mots' => ['now'],
    'pas une date' => ['quinze heures'],
    'un 30 février' => ['2026-02-30T15:00:00Z'],
    'suivie d’un décalage relatif' => ['2026-10-21T15:00:00Z +1 day'],
    'suivie d’un décalage démesuré' => ['2026-10-21T15:00:00Z +290000000000 years'],
]);

it('démarre maintenant quand l’heure de l’appareil au départ ne se lit pas', function (?string $heureAuDepart): void {
    $compte = User::factory()->create();

    horlogeDecaleeDemarrer(
        $compte,
        ['started_at' => horlogeDecaleeSurLAppareil(-horlogeDecaleeTroisHeures(), avant: 600)],
        $heureAuDepart,
    )->assertCreated();

    expect(horlogeDecaleeDebut($compte))->toBe('2026-10-21 18:00:00');
})->with('heures de départ que le serveur ne lit pas');

it('termine maintenant quand l’heure de l’appareil au départ ne se lit pas', function (?string $heureAuDepart): void {
    $compte = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => '2026-10-21 17:00:00', 'ended_at' => null]);

    horlogeDecaleeTerminer(
        $compte,
        ['workout_id' => $seance->id, 'ended_at' => horlogeDecaleeSurLAppareil(0, avant: 600)],
        $heureAuDepart,
    )->assertOk();

    expect($seance->refresh()->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-21 18:00:00');
})->with('heures de départ que le serveur ne lit pas');

/*
 * L'âge se lit sur les instants tels que l'appareil les envoie : ramenés
 * d'abord à l'heure murale de Paris, deux instants de l'heure que le passage
 * à l'heure d'hiver répète (le 25 octobre 2026, de 2 h à 3 h) se
 * confondraient, et l'âge du geste fait dans l'une gagnerait ou perdrait une
 * heure.
 */
it('mesure l’âge d’un geste fait dans l’heure que le passage à l’heure d’hiver répète', function (string $geste, string $depart): void {
    Carbon::setTestNow(Carbon::parse('2026-10-25T02:30:00Z'));
    $compte = User::factory()->create();

    horlogeDecaleeDemarrer($compte, ['started_at' => $geste], $depart)->assertCreated();

    expect(horlogeDecaleeDebut($compte))->toBe('2026-10-25 03:25:00');
})->with([
    'à 2 h 30 de l’heure d’été' => ['2026-10-25T00:30:00.000Z', '2026-10-25T00:35:00.000Z'],
    'à 2 h 30 de l’heure d’hiver' => ['2026-10-25T01:30:00.000Z', '2026-10-25T01:35:00.000Z'],
]);

/*
 * L'instant du geste ne s'écrit nulle part : seul son âge compte. Une horloge
 * d'appareil passée au-delà du 17 janvier 2038, ou restée en 1970, sortait
 * pourtant de la plage d'un horodatage que la validation lui imposait encore,
 * et le démarrage recevait un 422 : la file tenait la séance pour refusée, et
 * ses séries avec elle (relu le 2026-10-21).
 */
dataset('horloges d’appareil hors de la plage d’un horodatage', [
    'au-delà de 2038 au geste, remise à l’heure avant l’envoi' => ['2040-01-01T10:00:00.000Z', '2026-10-21T16:00:00.000Z', '2026-10-21 18:00:00'],
    'au-delà de 2038 jusqu’à l’envoi' => ['2040-01-01T10:00:00.000Z', '2040-01-01T10:00:02.000Z', '2026-10-21 17:59:58'],
    'restée en 1970 jusqu’à l’envoi' => ['1970-01-01T00:10:00.000Z', '1970-01-01T00:10:02.000Z', '2026-10-21 17:59:58'],
]);

it('démarre à l’heure du serveur, moins l’âge du geste, une horloge d’appareil hors de la plage d’un horodatage', function (string $geste, string $depart, string $debut): void {
    $compte = User::factory()->create();

    horlogeDecaleeDemarrer($compte, ['started_at' => $geste], $depart)->assertCreated();

    expect(horlogeDecaleeDebut($compte))->toBe($debut);
})->with('horloges d’appareil hors de la plage d’un horodatage');

it('lance un modèle depuis un appareil dont l’horloge passait 2038 au geste, remise à l’heure avant l’envoi', function (): void {
    $compte = User::factory()->create();
    $modele = WorkoutTemplate::factory()->create(['user_id' => $compte->id, 'name' => 'Dos']);

    horlogeDecaleeDemarrer(
        $compte,
        ['template_id' => $modele->id, 'started_at' => '2040-01-01T10:00:00.000Z'],
        '2026-10-21T16:00:00.000Z',
    )->assertCreated();

    expect(horlogeDecaleeDebut($compte))->toBe('2026-10-21 18:00:00');
});

dataset('âges lus sur l’appareil', [
    'en UTC' => ['2026-10-21T15:00:00.000Z', '2026-10-21T15:30:00.000Z', 1800],
    'avec un décalage' => ['2026-10-21T17:00:00+02:00', '2026-10-21T15:30:00Z', 1800],
    'l’instant après le départ' => ['2026-10-21T15:00:10Z', '2026-10-21T15:00:00Z', -10],
    'l’instant sans fuseau' => ['2026-10-21 15:00:00', '2026-10-21T15:30:00Z', null],
    'l’instant en mots' => ['yesterday', '2026-10-21T15:30:00Z', null],
    'l’instant absent' => [null, '2026-10-21T15:30:00Z', null],
    'un tableau en en-tête' => ['2026-10-21T15:00:00Z', ['2026-10-21T15:30:00Z'], null],
    'l’heure de départ suivie d’un jour en mots' => ['2026-10-21T10:00:00Z', '2026-10-21 10:00:00 UTC next monday', null],
    'l’heure de départ suivie d’une heure en mots' => ['2026-10-21T10:00:00Z', '2026-10-21T10:00:00Z noon', null],
    'deux décalages démesurés' => ['2026-10-21T10:00:00Z -290000000000 years', '2026-10-21T10:00:00Z +290000000000 years', null],
]);

it('lit l’âge du geste sur la seule horloge de l’appareil', function (mixed $instant, mixed $heureAuDepart, ?int $attendu): void {
    expect(HeureDeLAppareil::ageDe($instant, $heureAuDepart))->toBe($attendu);
})->with('âges lus sur l’appareil');

/*
 * Deux heures démesurées, d'un côté et de l'autre, faisaient déborder la
 * soustraction de leurs horodatages : le démarrage et la fin répondaient 500,
 * avant même la validation, et l'exception s'enregistrait, là où le même
 * corps sans l'en-tête reçoit un refus sur son champ (#2071, relu le
 * 2026-10-21).
 */
it('refuse sur son champ, comme sans l’en-tête, un instant et une heure de départ démesurés', function (): void {
    Exceptions::fake();
    $compte = User::factory()->create();
    $geste = '2026-10-21T10:00:00Z -290000000000 years';
    $depart = '2026-10-21T10:00:00Z +290000000000 years';

    horlogeDecaleeDemarrer($compte, ['started_at' => $geste], $depart)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['started_at']);

    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => '2026-10-21 17:00:00', 'ended_at' => null]);

    horlogeDecaleeTerminer($compte, ['workout_id' => $seance->id, 'ended_at' => $geste], $depart)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ended_at']);

    Exceptions::assertNothingReported();
    expect($seance->refresh()->ended_at)->toBeNull();
});

/*
 * La file écrit l'en-tête, le serveur le lit : leurs deux noms ne doivent pas
 * se séparer.
 */
it('lit l’en-tête que la file hors ligne écrit', function (): void {
    $source = (string) file_get_contents(resource_path('js/Utils/SyncService.js'));

    preg_match("/^export const ENTETE_DE_L_HEURE_DE_L_APPAREIL = '([^']+)'$/m", $source, $trouve);

    expect($trouve[1] ?? null)->toBe(HeureDeLAppareil::ENTETE);
});
