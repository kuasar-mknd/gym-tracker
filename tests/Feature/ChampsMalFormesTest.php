<?php

declare(strict_types=1);

use App\Http\Requests\UpdatePushSubscriptionRequest;
use App\Models\DailyJournal;
use App\Models\Exercise;
use App\Models\Fast;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\User;
use App\Models\WaterLog;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use App\Rules\DateQueSaColonneTient;
use Illuminate\Support\Facades\Exceptions;

use function Pest\Laravel\actingAs;

/*
 * Des champs que la validation laissait atteindre ce qui ne sait pas les lire
 * (#2071) : `current_password` passait un tableau à `password_verify()`, une
 * comparaison de dates (`after:today`, `before_or_equal:today`) un nombre
 * trop grand à Carbon, même quand `date` l'avait déjà refusé, et le début
 * d'une séance, effacé, allait jusqu'à la colonne qui l'exige. Un
 * identifiant que `Rule::exists` cherche en base, reçu en tableau imbriqué,
 * partait dans `whereIn()` ou `array_unique()`, qui lèvent ; un identifiant
 * d'exercice d'un modèle, flottant démesuré, était converti en entier hors de
 * toute règle. Les clés d'un abonnement push, validées par `required` seul,
 * allaient en tableau, en nombre ou plus longues que leur colonne au paquet
 * WebPush, qui les attend en chaînes. Chacun répondait 500 ; chacun est
 * désormais refusé sur son champ, en 422, sans qu'aucune exception ne soit
 * rapportée.
 */

beforeEach(function (): void {
    Exceptions::fake();
});

/**
 * Une requête à envoyer au nom du compte : méthode, adresse, corps, et le
 * champ que la réponse doit refuser.
 *
 * @return array{0: string, 1: string, 2: array<string, mixed>, 3: string}
 */
function champsMalFormesRequete(string $cas, User $compte): array
{
    $objectif = ['title' => 'Séances', 'type' => 'frequency', 'target_value' => 12];

    return match ($cas) {
        'mot de passe en tableau, suppression du compte' => ['DELETE', route('profile.destroy'), ['password' => ['password']], 'password'],
        'mot de passe actuel en tableau, changement de mot de passe' => ['PUT', route('password.update'), [
            'current_password' => ['password'],
            'password' => 'Un-Nouveau-Mot-De-Passe-42',
            'password_confirmation' => 'Un-Nouveau-Mot-De-Passe-42',
        ], 'current_password'],
        'mot de passe actuel en tableau, changement d’adresse' => ['PATCH', route('profile.update'), [
            'name' => 'Nom',
            'email' => 'nouvelle@example.org',
            'current_password' => ['password'],
        ], 'current_password'],
        'échéance d’un nouvel objectif trop grande' => ['POST', route('goals.store'), [...$objectif, 'deadline' => PHP_INT_MAX], 'deadline'],
        'échéance d’un objectif déplacée, nombre flottant démesuré' => [
            'PUT',
            route('goals.update', Goal::factory()->create(['user_id' => $compte->id, 'type' => 'frequency'])),
            [...$objectif, 'deadline' => 1e300],
            'deadline',
        ],
        'date d’une mensuration trop grande' => ['POST', route('body-parts.store'), [
            'part' => 'Biceps',
            'value' => 35,
            'unit' => 'cm',
            'measured_at' => -PHP_INT_MAX,
        ], 'measured_at'],
        'ligne d’une série en tableau imbriqué' => ['POST', route('api.v1.sets.store'), ['workout_line_id' => [[1, 2]], 'weight' => 10, 'reps' => 5], 'workout_line_id'],
        'séance d’une ligne en tableau imbriqué' => ['POST', route('api.v1.workout-lines.store'), [
            'workout_id' => [[1, 2]],
            'exercise_id' => Exercise::factory()->create()->id,
        ], 'workout_id'],
        'exercice d’une ligne en objet à deux clefs' => ['POST', route('api.v1.workout-lines.store'), [
            'workout_id' => Workout::withoutEvents(fn (): Workout => Workout::factory()->create(['user_id' => $compte->id]))->id,
            'exercise_id' => [['a' => 1, 'b' => 2]],
        ], 'exercise_id'],
        'exercice d’un nouvel objectif en tableau imbriqué' => ['POST', route('goals.store'), [
            'title' => 'Développé couché',
            'type' => 'weight',
            'target_value' => 100,
            'exercise_id' => [[1, 2]],
        ], 'exercise_id'],
        'exercice d’un objectif modifié en tableau de tableaux' => [
            'PUT',
            route('goals.update', Goal::factory()->create(['user_id' => $compte->id, 'type' => 'frequency'])),
            ['title' => 'Développé couché', 'type' => 'weight', 'target_value' => 100, 'exercise_id' => [[1], [2]]],
            'exercise_id',
        ],
        'identifiant d’exercice flottant démesuré, nouveau modèle' => ['POST', route('templates.store'), [
            'name' => 'Modèle',
            'exercises' => [['id' => 1e30]],
        ], 'exercises.0.id'],
        'identifiant d’exercice flottant démesuré, modèle modifié' => [
            'PUT',
            route('templates.update', WorkoutTemplate::factory()->create(['user_id' => $compte->id])),
            ['name' => 'Modèle', 'exercises' => [['id' => -1e30]]],
            'exercises.0.id',
        ],
        'clef d’authentification d’un abonnement push en tableau' => ['POST', route('push-subscriptions.update'), champsMalFormesAbonnement(['a'], 'p256dh-key'), 'keys.auth'],
        'clef de chiffrement d’un abonnement push en tableau imbriqué' => ['POST', route('push-subscriptions.update'), champsMalFormesAbonnement('auth-token', [['a', 'b']]), 'keys.p256dh'],
        'clef d’authentification d’un abonnement push en nombre' => ['POST', route('push-subscriptions.update'), champsMalFormesAbonnement(12, 'p256dh-key'), 'keys.auth'],
        'clef d’authentification d’un abonnement push plus longue que sa colonne' => [
            'POST',
            route('push-subscriptions.update'),
            champsMalFormesAbonnement(str_repeat('a', UpdatePushSubscriptionRequest::CLEF_MAX + 1), 'p256dh-key'),
            'keys.auth',
        ],
        default => throw new LogicException("Cas inconnu : {$cas}"),
    };
}

/**
 * Le corps d'un abonnement push, sur une adresse publique, avec ces clés.
 *
 * @return array{endpoint: string, keys: array{auth: mixed, p256dh: mixed}}
 */
function champsMalFormesAbonnement(mixed $auth, mixed $p256dh): array
{
    return [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/telephone',
        'keys' => ['auth' => $auth, 'p256dh' => $p256dh],
    ];
}

it('refuse sur son champ une valeur que la validation laissait passer jusqu’à une erreur', function (string $cas): void {
    $compte = User::factory()->create();
    [$methode, $adresse, $corps, $champ] = champsMalFormesRequete($cas, $compte);

    actingAs($compte)
        ->json($methode, $adresse, $corps)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$champ]);

    Exceptions::assertNothingReported();
})->with([
    'mot de passe en tableau, suppression du compte',
    'mot de passe actuel en tableau, changement de mot de passe',
    'mot de passe actuel en tableau, changement d’adresse',
    'échéance d’un nouvel objectif trop grande',
    'échéance d’un objectif déplacée, nombre flottant démesuré',
    'date d’une mensuration trop grande',
    'ligne d’une série en tableau imbriqué',
    'séance d’une ligne en tableau imbriqué',
    'exercice d’une ligne en objet à deux clefs',
    'exercice d’un nouvel objectif en tableau imbriqué',
    'exercice d’un objectif modifié en tableau de tableaux',
    'identifiant d’exercice flottant démesuré, nouveau modèle',
    'identifiant d’exercice flottant démesuré, modèle modifié',
    'clef d’authentification d’un abonnement push en tableau',
    'clef de chiffrement d’un abonnement push en tableau imbriqué',
    'clef d’authentification d’un abonnement push en nombre',
    'clef d’authentification d’un abonnement push plus longue que sa colonne',
]);

it('refuse d’effacer le début d’une séance, et le laisse tel qu’il était', function (mixed $debut): void {
    $compte = User::factory()->create();
    $seance = Workout::withoutEvents(fn (): Workout => Workout::factory()->create([
        'user_id' => $compte->id,
        'started_at' => '2026-10-15 18:00:00',
    ]));

    actingAs($compte)
        ->patchJson(route('workouts.update', $seance), ['started_at' => $debut])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['started_at']);

    expect($seance->fresh()?->started_at?->format('Y-m-d H:i:s'))->toBe('2026-10-15 18:00:00');
    Exceptions::assertNothingReported();
})->with([
    'nul' => [null],
    'vide' => [''],
]);

it('laisse changer le nom d’une séance sans renvoyer son début', function (): void {
    $compte = User::factory()->create();
    $seance = Workout::withoutEvents(fn (): Workout => Workout::factory()->create([
        'user_id' => $compte->id,
        'started_at' => '2026-10-15 18:00:00',
    ]));

    actingAs($compte)
        ->patch(route('workouts.update', $seance), ['name' => 'Jambes'])
        ->assertSessionHasNoErrors();

    expect($seance->fresh()?->only(['name']))->toBe(['name' => 'Jambes'])
        ->and($seance->fresh()?->started_at?->format('Y-m-d H:i:s'))->toBe('2026-10-15 18:00:00');
    Exceptions::assertNothingReported();
});

it('lit comme avant un identifiant envoyé en nombre ou en chiffres', function (bool $enChiffres): void {
    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $compte->id]);
    $valeur = $enChiffres ? (string) $exercice->id : $exercice->id;

    actingAs($compte)
        ->postJson(route('templates.store'), ['name' => 'Modèle', 'exercises' => [['id' => $valeur]]])
        ->assertRedirect(route('templates.index'));
    actingAs($compte)
        ->postJson(route('goals.store'), ['title' => 'Squat', 'type' => 'weight', 'target_value' => 100, 'exercise_id' => $valeur])
        ->assertRedirect();

    expect(WorkoutTemplate::query()->where('user_id', $compte->id)->sole()->workoutTemplateLines()->sole()->exercise_id)->toBe($exercice->id)
        ->and(Goal::query()->where('user_id', $compte->id)->sole()->exercise_id)->toBe($exercice->id);
    Exceptions::assertNothingReported();
})->with([
    'nombre' => [false],
    'chiffres' => [true],
]);

/**
 * Une écriture qui porte une date, sans la date : méthode, adresse, corps,
 * et le champ qui la reçoit.
 *
 * @return array{0: string, 1: string, 2: array<string, mixed>, 3: string}
 */
function champsMalFormesEcritureDatee(string $cas, User $compte): array
{
    $proprietaire = ['user_id' => $compte->id];
    $jeune = static fn (): Fast => Fast::factory()->create([...$proprietaire, 'status' => 'completed', 'end_time' => '2026-10-01 18:00:00']);
    $objectif = ['title' => 'Séances', 'type' => 'frequency', 'target_value' => 12];

    return match ($cas) {
        'prise d’eau' => ['POST', route('tools.water.store'), ['amount' => 250], 'consumed_at'],
        'début d’un nouveau jeûne' => ['POST', route('tools.fasting.store'), ['target_duration_minutes' => 960, 'type' => '16:8'], 'start_time'],
        'début d’un jeûne modifié' => ['PATCH', route('tools.fasting.update', $jeune()), [], 'start_time'],
        'fin d’un jeûne' => ['PATCH', route('tools.fasting.update', $jeune()), [], 'end_time'],
        'début d’une séance' => ['PATCH', route('workouts.update', Workout::withoutEvents(fn (): Workout => Workout::factory()->create($proprietaire))), [], 'started_at'],
        'jour d’un journal' => ['POST', route('daily-journals.store'), ['content' => 'Bonne journée'], 'date'],
        'jour d’une habitude' => ['POST', route('habits.toggle', Habit::factory()->create($proprietaire)), [], 'date'],
        'jour d’une pesée' => ['POST', route('body-measurements.store'), ['weight' => 80], 'measured_at'],
        'jour d’une mensuration' => ['POST', route('body-parts.store'), ['part' => 'Biceps', 'value' => 35, 'unit' => 'cm'], 'measured_at'],
        'échéance d’un nouvel objectif' => ['POST', route('goals.store'), $objectif, 'deadline'],
        'échéance d’un objectif déplacée' => ['PUT', route('goals.update', Goal::factory()->create([...$proprietaire, 'type' => 'frequency'])), $objectif, 'deadline'],
        default => throw new LogicException("Cas inconnu : {$cas}"),
    };
}

/*
 * La règle `date` accepte une date que sa colonne ne tient pas : avant 1970
 * ou après 2038 pour un horodatage (le début d'une séance devient la
 * dernière séance du compte, un horodatage), l'an 10000 pour une date, que
 * PHP lit même au 1er janvier 2000 quand elle s'écrit sans signe. Chacune
 * répondait 500 ; chacune est refusée sur son champ (`DateQueSaColonneTient`).
 */
it('refuse sur son champ une date que sa colonne ne tient pas', function (string $cas, string $date): void {
    $compte = User::factory()->create();
    [$methode, $adresse, $corps, $champ] = champsMalFormesEcritureDatee($cas, $compte);

    actingAs($compte)
        ->json($methode, $adresse, [...$corps, $champ => $date])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$champ]);

    Exceptions::assertNothingReported();
})->with([
    'prise d’eau après 2038' => ['prise d’eau', '2099-01-01 10:00:00'],
    'prise d’eau avant 1970' => ['prise d’eau', '1960-01-01 10:00:00'],
    'prise d’eau en l’an 10000' => ['prise d’eau', '+10000-01-01 10:00:00'],
    'début d’un nouveau jeûne après 2038' => ['début d’un nouveau jeûne', '2099-01-01 10:00:00'],
    'début d’un nouveau jeûne avant 1970' => ['début d’un nouveau jeûne', '1960-01-01 10:00:00'],
    'début d’un nouveau jeûne en l’an 10000' => ['début d’un nouveau jeûne', '+10000-01-01 10:00:00'],
    'début d’un jeûne modifié après 2038' => ['début d’un jeûne modifié', '2099-01-01 10:00:00'],
    'début d’un jeûne modifié avant 1970' => ['début d’un jeûne modifié', '1960-01-01 10:00:00'],
    'début d’un jeûne modifié en l’an 10000' => ['début d’un jeûne modifié', '+10000-01-01 10:00:00'],
    'fin d’un jeûne après 2038' => ['fin d’un jeûne', '2099-01-01 10:00:00'],
    'fin d’un jeûne avant 1970' => ['fin d’un jeûne', '1960-01-01 10:00:00'],
    'fin d’un jeûne en l’an 10000' => ['fin d’un jeûne', '+10000-01-01 10:00:00'],
    'début d’une séance après 2038' => ['début d’une séance', '2099-01-01 10:00:00'],
    'début d’une séance avant 1970' => ['début d’une séance', '1960-01-01 10:00:00'],
    'début d’une séance en l’an 10000' => ['début d’une séance', '+10000-01-01 10:00:00'],
    'jour d’un journal en l’an 10000' => ['jour d’un journal', '10000-01-01'],
    'jour d’un journal en l’an 10000, signé' => ['jour d’un journal', '+10000-01-01'],
    'jour d’une habitude en l’an 10000, signé' => ['jour d’une habitude', '+10000-01-01'],
    'jour d’une pesée en l’an 10000' => ['jour d’une pesée', '10000-01-01'],
    'jour d’une pesée en l’an 10000, signé' => ['jour d’une pesée', '+10000-01-01'],
    'jour d’une mensuration en l’an 10000' => ['jour d’une mensuration', '10000-01-01'],
    'échéance d’un nouvel objectif en l’an 10000, signée' => ['échéance d’un nouvel objectif', '+10000-01-01'],
    'échéance d’un objectif déplacée en l’an 10000, signée' => ['échéance d’un objectif déplacée', '+10000-01-01'],
]);

it('écrit encore une date au bord de la plage de sa colonne', function (string $cas, string $date): void {
    $compte = User::factory()->create();
    [$methode, $adresse, $corps, $champ] = champsMalFormesEcritureDatee($cas, $compte);

    actingAs($compte)
        ->json($methode, $adresse, [...$corps, $champ => $date])
        ->assertValid();

    Exceptions::assertNothingReported();
    expect(match ($cas) {
        'prise d’eau' => WaterLog::query()->where('user_id', $compte->id)->sole()->consumed_at?->format('Y-m-d H:i:s'),
        'jour d’un journal' => DailyJournal::query()->where('user_id', $compte->id)->sole()->date->format('Y-m-d'),
        default => throw new LogicException("Cas inconnu : {$cas}"),
    })->toBe($date);
})->with([
    'premier instant d’une prise d’eau' => ['prise d’eau', DateQueSaColonneTient::horodatage()->premierInstant()],
    'dernier instant d’une prise d’eau' => ['prise d’eau', DateQueSaColonneTient::horodatage()->dernierInstant()],
    'premier jour d’un journal' => ['jour d’un journal', DateQueSaColonneTient::PREMIER_JOUR_D_UNE_DATE],
    'dernier jour d’un journal' => ['jour d’un journal', DateQueSaColonneTient::DERNIER_JOUR_D_UNE_DATE],
]);

it('dit la plage de la colonne dans le refus, en français', function (string $cas, string $date, string $message): void {
    $compte = User::factory()->create();
    [$methode, $adresse, $corps, $champ] = champsMalFormesEcritureDatee($cas, $compte);

    actingAs($compte)
        ->json($methode, $adresse, [...$corps, $champ => $date])
        ->assertJsonValidationErrors([$champ => $message]);
})->with([
    'horodatage' => ['prise d’eau', '2099-01-01 10:00:00', 'Le champ heure de consommation doit être une date comprise entre le 2 janvier 1970 et le 17 janvier 2038.'],
    'date' => ['jour d’un journal', '10000-01-01', 'Le champ date doit être une date comprise entre le 1er janvier 1000 et le 31 décembre 9999.'],
]);
