<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\RecommendedValuesService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Une ligne dont les identifiants arrivent en texte, comme ceux d'une ligne
 * que l'appelant n'a pas relue de la base, rattachée à une séance précédée
 * d'une autre qui a un historique.
 *
 * @return array{0: User, 1: Exercise, 2: Workout, 3: WorkoutLine}
 */
function ligneAvecDesIdentifiantsEnTexte(): array
{
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create();

    $ancienne = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subWeek()]);
    $ligneAncienne = WorkoutLine::factory()->create(['workout_id' => $ancienne->id, 'exercise_id' => $exercice->id]);
    Set::factory()->create(['workout_line_id' => $ligneAncienne->id, 'weight' => 60.0, 'reps' => 8, 'distance_km' => 0.0, 'duration_seconds' => 0]);

    $seance = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);

    $ligne = new WorkoutLine();
    $ligne->setRawAttributes(['workout_id' => (string) $seance->id, 'exercise_id' => (string) $exercice->id]);
    $ligne->setRelation('workout', $seance);

    return [$user, $exercice, $seance, $ligne];
}

it('sert une ligne dont les identifiants sont du texte, et met le résultat en cache sous les identifiants entiers', function (): void {
    [$user, $exercice, $seance, $ligne] = ligneAvecDesIdentifiantsEnTexte();

    $valeurs = app(RecommendedValuesService::class)->batchRecommendedValues(new Collection([$ligne]), $user->id);

    expect($valeurs)->toBe([$exercice->id => ['weight' => 60.0, 'reps' => 8, 'distance_km' => 0.0, 'duration_seconds' => 0]])
        ->and(Cache::has(RecommendedValuesService::cleDeCache($user->id, $exercice->id, $seance->id)))->toBeTrue();
});

it('range sous l’exercice zéro la ligne qui n’en nomme aucun', function (): void {
    $user = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);

    $ligne = new WorkoutLine();
    $ligne->setRawAttributes(['workout_id' => $seance->id, 'exercise_id' => null]);
    $ligne->setRelation('workout', $seance);

    $valeurs = app(RecommendedValuesService::class)->batchRecommendedValues(new Collection([$ligne]), $user->id);

    expect($valeurs)->toBe([0 => ['weight' => 0.0, 'reps' => 10, 'distance_km' => 0.0, 'duration_seconds' => 30]]);
});

it('ne lit rien pour une ligne sans séance', function (): void {
    $ligne = new WorkoutLine();
    $ligne->setRawAttributes(['workout_id' => null, 'exercise_id' => 5]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $valeurs = app(RecommendedValuesService::class)->batchRecommendedValues(new Collection([$ligne]), 1);
    $requetes = DB::getQueryLog();
    DB::disableQueryLog();

    expect($valeurs)->toBe([])
        ->and($requetes)->toBe([]);
});

it('ne rend rien pour une ligne dont la séance n’existe pas', function (): void {
    $ligne = new WorkoutLine();
    $ligne->setRawAttributes(['workout_id' => 987_654_321, 'exercise_id' => 5]);

    expect(app(RecommendedValuesService::class)->batchRecommendedValues(new Collection([$ligne]), 1))->toBe([]);
});

it('ramène chaque valeur sous son plafond, en gardant son type', function (): void {
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create();

    $hier = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDay()]);
    $ligneHier = WorkoutLine::factory()->create(['workout_id' => $hier->id, 'exercise_id' => $exercice->id]);
    Set::withoutEvents(fn (): Set => Set::factory()->create([
        'workout_line_id' => $ligneHier->id,
        'weight' => 150_000.5,
        'reps' => 5_000,
        'distance_km' => 99_999.5,
        'duration_seconds' => 100_000,
    ]));

    $aujourdhui = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $aujourdhui->id, 'exercise_id' => $exercice->id]);

    $attendu = [
        'weight' => (float) Set::POIDS_MAX_KG,
        'reps' => Set::REPETITIONS_MAX,
        'distance_km' => (float) Set::bornes()['distance_km'],
        'duration_seconds' => Set::DUREE_MAX_SECONDES,
    ];

    expect(app(RecommendedValuesService::class)->getRecommendedValues($ligne))->toBe($attendu);

    Cache::flush();
    $valeurs = app(RecommendedValuesService::class)->batchRecommendedValues(new Collection([$ligne]), $user->id);

    expect($valeurs[$exercice->id])->toBe($attendu);
});
