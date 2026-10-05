<?php

declare(strict_types=1);

use App\Models\ErreurNavigateur;
use App\Models\ExceptionEnregistree;
use App\Models\Exercise;
use App\Models\Fast;
use App\Models\Goal;
use App\Models\IntervalTimer;
use App\Models\Supplement;
use App\Models\User;
use App\Models\WarmupPreference;
use App\Models\WaterLog;
use App\Models\Workout;
use App\Models\WorkoutLine;

use function Pest\Laravel\actingAs;

/*
 * Un nombre hors de la plage de sa colonne passait la validation, que seul un
 * `min:` bornait : MySQL le refusait, la requête finissait en 500, et chaque
 * 500 écrivait une ligne d'exception avec le corps de la requête. Chaque
 * champ chiffré de ces requêtes porte désormais une borne haute métier, sous
 * la capacité de sa colonne : hors plage, la réponse est un 422 sur le champ,
 * et aucune exception n'est enregistrée ; au plafond, l'écriture passe.
 */

/**
 * Une requête à envoyer, au nom du compte : méthode, adresse, corps valide.
 *
 * @return array{0: string, 1: string, 2: array<string, mixed>}
 */
function horsPlageRequete(string $cas, User $compte): array
{
    $minuteur = ['name' => 'Tabata', 'work_seconds' => 20, 'rest_seconds' => 10, 'rounds' => 8, 'warmup_seconds' => 10];
    $complement = ['name' => 'Créatine', 'servings_remaining' => 30, 'low_stock_threshold' => 5];
    $echauffement = ['bar_weight' => 20, 'rounding_increment' => 2.5, 'steps' => [['percent' => 50, 'reps' => 5, 'label' => null]]];
    $objectif = ['title' => 'Séances', 'type' => 'frequency', 'target_value' => 12, 'start_value' => 0];

    return match ($cas) {
        'minuteur créé' => ['post', route('tools.interval-timer.store'), $minuteur],
        'minuteur modifié' => ['patch', route('tools.interval-timer.update', IntervalTimer::factory()->create(['user_id' => $compte->id])), $minuteur],
        'eau' => ['post', route('tools.water.store'), ['amount' => 250, 'consumed_at' => now()->toDateTimeString()]],
        'complément créé' => ['post', route('supplements.store'), $complement],
        'complément modifié' => ['put', route('supplements.update', Supplement::factory()->create(['user_id' => $compte->id])), $complement],
        'jeûne commencé' => ['post', route('tools.fasting.store'), ['start_time' => now()->toDateTimeString(), 'target_duration_minutes' => 960, 'type' => '16:8']],
        'jeûne modifié' => ['patch', route('tools.fasting.update', Fast::factory()->create(['user_id' => $compte->id, 'status' => 'active'])), ['target_duration_minutes' => 960]],
        'échauffement' => ['post', route('tools.warmup.update'), $echauffement],
        'erreur navigateur' => ['post', route('erreurs-navigateur.store'), ['type' => 'error', 'message' => 'boum', 'url' => 'https://gym.example.org/']],
        'objectif créé' => ['post', route('goals.store'), $objectif],
        'objectif modifié' => ['put', route('goals.update', Goal::factory()->create(['user_id' => $compte->id, 'type' => 'frequency'])), $objectif],
        'ligne de séance' => ['post', route('api.v1.workout-lines.store'), [
            'workout_id' => Workout::factory()->create(['user_id' => $compte->id])->id,
            'exercise_id' => Exercise::factory()->create(['user_id' => $compte->id])->id,
        ]],
        default => throw new LogicException("Cas inconnu : {$cas}"),
    };
}

/**
 * Le corps d'une requête, un champ (chemin pointé) changé.
 *
 * @param  array<string, mixed>  $corps
 * @return array<string, mixed>
 */
function horsPlageAvec(array $corps, string $champ, mixed $valeur): array
{
    $change = $corps;
    data_set($change, $champ, $valeur);

    if (! is_array($change)) {
        throw new LogicException('Le corps de la requête reste un tableau.');
    }

    /** @var array<string, mixed> $change */
    return $change;
}

/*
 * Le cas, le champ (chemin pointé), une valeur hors plage et la valeur au
 * plafond. Les six routes d'origine d'abord, avec une valeur au-delà de la
 * plage d'un `int` ou d'un `decimal(8,2)` ; puis les autres champs chiffrés
 * que la garde de convention a fait borner.
 */
dataset('hors plage champs', [
    'travail d’un minuteur' => ['minuteur créé', 'work_seconds', 2_147_483_648, IntervalTimer::SECONDES_MAX_PAR_INTERVALLE],
    'repos d’un minuteur' => ['minuteur créé', 'rest_seconds', 2_147_483_648, IntervalTimer::SECONDES_MAX_PAR_INTERVALLE],
    'tours d’un minuteur' => ['minuteur créé', 'rounds', 2_147_483_648, IntervalTimer::TOURS_MAX],
    'échauffement d’un minuteur' => ['minuteur créé', 'warmup_seconds', 2_147_483_648, IntervalTimer::SECONDES_MAX_PAR_INTERVALLE],
    'tours d’un minuteur modifié' => ['minuteur modifié', 'rounds', 2_147_483_648, IntervalTimer::TOURS_MAX],
    'quantité d’eau' => ['eau', 'amount', 2_147_483_648, WaterLog::QUANTITE_MAX_ML],
    'doses d’un complément' => ['complément créé', 'servings_remaining', 2_147_483_648, Supplement::DOSES_MAX],
    'seuil d’un complément' => ['complément créé', 'low_stock_threshold', 2_147_483_648, Supplement::DOSES_MAX],
    'doses d’un complément modifié' => ['complément modifié', 'servings_remaining', 2_147_483_648, Supplement::DOSES_MAX],
    'durée cible d’un jeûne' => ['jeûne commencé', 'target_duration_minutes', 2_147_483_648, Fast::DUREE_CIBLE_MAX_MINUTES],
    'durée cible d’un jeûne modifié' => ['jeûne modifié', 'target_duration_minutes', 2_147_483_648, Fast::DUREE_CIBLE_MAX_MINUTES],
    'poids de la barre' => ['échauffement', 'bar_weight', 1_000_000, WarmupPreference::POIDS_DE_BARRE_MAX_KG],
    'arrondi de l’échauffement' => ['échauffement', 'rounding_increment', 1_000_000, WarmupPreference::ARRONDI_MAX_KG],
    'répétitions d’un palier' => ['échauffement', 'steps.0.reps', 2_147_483_648, WarmupPreference::REPETITIONS_MAX_PAR_PALIER],
    'ligne d’une erreur navigateur' => ['erreur navigateur', 'ligne', 4_294_967_296, ErreurNavigateur::POSITION_MAX],
    'colonne d’une erreur navigateur' => ['erreur navigateur', 'colonne', 4_294_967_296, ErreurNavigateur::POSITION_MAX],
    'cible d’un objectif' => ['objectif créé', 'target_value', 1e12, Goal::VALEUR_MAX],
    'départ d’un objectif modifié' => ['objectif modifié', 'start_value', 1e12, Goal::VALEUR_MAX],
    'rang d’une ligne de séance' => ['ligne de séance', 'order', 2_147_483_648, WorkoutLine::RANG_MAX],
]);

it('refuse en 422 une valeur hors plage, sans 500 ni exception enregistrée', function (string $cas, string $champ, int|float $horsPlage): void {
    $compte = User::factory()->create();
    [$methode, $adresse, $corps] = horsPlageRequete($cas, $compte);
    actingAs($compte)->json($methode, $adresse, horsPlageAvec($corps, $champ, $horsPlage))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$champ]);

    expect(ExceptionEnregistree::query()->count())->toBe(0);
})->with('hors plage champs');

it('accepte la valeur au plafond', function (string $cas, string $champ, int|float $horsPlage, int $plafond): void {
    $compte = User::factory()->create();
    [$methode, $adresse, $corps] = horsPlageRequete($cas, $compte);
    $reponse = actingAs($compte)->json($methode, $adresse, horsPlageAvec($corps, $champ, $plafond));

    expect($reponse->status())->toBeLessThan(400, (string) $reponse->getContent())
        ->and(ExceptionEnregistree::query()->count())->toBe(0);
})->with('hors plage champs');

it('refuse une valeur négative là où la colonne n’en a pas l’usage', function (string $cas, string $champ): void {
    $compte = User::factory()->create();
    [$methode, $adresse, $corps] = horsPlageRequete($cas, $compte);
    actingAs($compte)->json($methode, $adresse, horsPlageAvec($corps, $champ, -1))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$champ]);
})->with([
    'départ d’un objectif' => ['objectif créé', 'start_value'],
    'rang d’une ligne de séance' => ['ligne de séance', 'order'],
]);

it('compte les paliers d’une montée en charge', function (): void {
    $compte = User::factory()->create();
    [$methode, $adresse, $corps] = horsPlageRequete('échauffement', $compte);
    $corps['steps'] = array_fill(0, WarmupPreference::PALIERS_MAX + 1, ['percent' => 50, 'reps' => 5, 'label' => null]);

    actingAs($compte)->json($methode, $adresse, $corps)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['steps']);

    $corps['steps'] = array_slice($corps['steps'], 1);

    expect(actingAs($compte)->json($methode, $adresse, $corps)->status())->toBeLessThan(400);
});

it('borne l’année du calendrier', function (): void {
    $compte = User::factory()->create();

    actingAs($compte)->getJson(route('calendar.index', ['year' => 99_999, 'month' => 1]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['year']);

    actingAs($compte)->get(route('calendar.index', ['year' => 2100, 'month' => 12]))->assertOk();
    actingAs($compte)->get(route('calendar.index', ['year' => 1970, 'month' => 1]))->assertOk();
});
