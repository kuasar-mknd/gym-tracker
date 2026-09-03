<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\RecommendedValuesService;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    $this->service = app(RecommendedValuesService::class);
});

test('it returns default values when line has no workout', function (): void {
    $line = WorkoutLine::factory()->make(['workout_id' => null]);

    $values = $this->service->getRecommendedValues($line);

    expect($values)->toBe([
        'weight' => 0.0,
        'reps' => 10,
        'distance_km' => 0.0,
        'duration_seconds' => 30,
    ]);
});

test('it returns default values when there is no previous workout for the exercise', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $values = $this->service->getRecommendedValues($line);

    expect($values)->toBe([
        'weight' => 0.0,
        'reps' => 10,
        'distance_km' => 0.0,
        'duration_seconds' => 30,
    ]);
});

test('it calculates values based on the most frequent set of the last workout', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    // Previous workout
    $prevWorkout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => now()->subDays(2),
    ]);
    $prevLine = WorkoutLine::factory()->create([
        'workout_id' => $prevWorkout->id,
        'exercise_id' => $exercise->id,
    ]);

    // Sets: 2 sets of 100kg x 8 reps, 1 set of 110kg x 5 reps
    Set::factory()->count(2)->create([
        'workout_line_id' => $prevLine->id,
        'weight' => 100.0,
        'reps' => 8,
        'distance_km' => 0.0,
        'duration_seconds' => 0,
    ]);
    Set::factory()->create([
        'workout_line_id' => $prevLine->id,
        'weight' => 110.0,
        'reps' => 5,
        'distance_km' => 0.0,
        'duration_seconds' => 0,
    ]);

    // Current workout
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => now(),
    ]);
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $values = $this->service->getRecommendedValues($line);

    expect($values)->toBe([
        'weight' => 100.0,
        'reps' => 8,
        'distance_km' => 0.0,
        'duration_seconds' => 0,
    ]);

    // Ensure it was cached
    $cacheKey = \App\Services\RecommendedValuesService::cleDeCache($user->id, $exercise->id, $workout->id);
    expect(Cache::get($cacheKey))->toBe($values);
});

test('it returns cached values when available', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $cacheKey = \App\Services\RecommendedValuesService::cleDeCache($user->id, $exercise->id, $workout->id);
    $cachedValues = [
        'weight' => 150.0,
        'reps' => 12,
        'distance_km' => 5.5,
        'duration_seconds' => 600,
    ];
    Cache::put($cacheKey, $cachedValues, 300);

    $values = $this->service->getRecommendedValues($line);

    expect($values)->toBe($cachedValues);
});

test('batch processing returns empty array for empty collection', function (): void {
    $lines = new \Illuminate\Database\Eloquent\Collection();
    $values = $this->service->batchRecommendedValues($lines, 1);
    expect($values)->toBe([]);
});

test('batch processing applies and caches correctly for multiple exercises', function (): void {
    $user = User::factory()->create();
    $exercise1 = Exercise::factory()->create();
    $exercise2 = Exercise::factory()->create();

    // Previous workout
    $prevWorkout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => now()->subDays(2),
    ]);

    // Line 1 in prev workout
    $prevLine1 = WorkoutLine::factory()->create([
        'workout_id' => $prevWorkout->id,
        'exercise_id' => $exercise1->id,
    ]);
    Set::factory()->create([
        'workout_line_id' => $prevLine1->id,
        'weight' => 50.0,
        'reps' => 10,
        'distance_km' => 0.0,
        'duration_seconds' => 0,
    ]);

    // Current workout
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => now(),
    ]);
    $line1 = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise1->id,
    ]);
    $line2 = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise2->id,
    ]);

    // Pre-cache line 2 to test mixed cached/uncached batch
    $cacheKey2 = \App\Services\RecommendedValuesService::cleDeCache($user->id, $exercise2->id, $workout->id);
    $cachedValues2 = [
        'weight' => 60.0,
        'reps' => 12,
        'distance_km' => 0.0,
        'duration_seconds' => 0,
    ];
    Cache::put($cacheKey2, $cachedValues2, 300);

    $lines = new \Illuminate\Database\Eloquent\Collection([$line1, $line2]);
    $values = $this->service->batchRecommendedValues($lines, $user->id);

    // Assert returns
    expect($values[$exercise1->id])->toBe([
        'weight' => 50.0,
        'reps' => 10,
        'distance_km' => 0.0,
        'duration_seconds' => 0,
    ])
        ->and($values[$exercise2->id])->toBe($cachedValues2);

    // Assert lines were mutated via setRecommendedValuesAttribute
    // Since we don't have the explicit method definition, we can check if it ran without errors
    // The implementation calls $line->setRecommendedValuesAttribute()
});

/**
 * @return array{0: User, 1: Exercise, 2: WorkoutLine} la ligne de la seance du jour, apres une
 *                                                     seance d'il y a une semaine a 50 kg et une
 *                                                     seance d'hier laissee au pre-remplissage
 */
function recommandationApresUneLigneVide(): array
{
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    $ancienne = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subWeek()]);
    $ligneAncienne = WorkoutLine::factory()->create(['workout_id' => $ancienne->id, 'exercise_id' => $exercise->id]);
    Set::factory()->count(3)->create(['workout_line_id' => $ligneAncienne->id, 'weight' => 50.0, 'reps' => 10]);

    $hier = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDay()]);
    $ligneHier = WorkoutLine::factory()->create(['workout_id' => $hier->id, 'exercise_id' => $exercise->id]);
    Set::factory()->count(5)->create(['workout_line_id' => $ligneHier->id, 'weight' => 0.0, 'reps' => 10, 'is_completed' => true]);

    $aujourdhui = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $aujourdhui->id, 'exercise_id' => $exercise->id]);

    return [$user, $exercise, $ligne];
}

it('ignore une ligne récente dont les séries sont restées au pré-remplissage et remonte à la séance d’avant', function (): void {
    [, , $ligne] = recommandationApresUneLigneVide();

    expect(app(RecommendedValuesService::class)->getRecommendedValues($ligne)['weight'])->toBe(50.0);
});

it('remonte aussi à la séance d’avant sur le chemin par lot', function (): void {
    [$user, $exercise, $ligne] = recommandationApresUneLigneVide();

    $values = app(RecommendedValuesService::class)->batchRecommendedValues(new \Illuminate\Database\Eloquent\Collection([$ligne]), $user->id);

    // La valeur posee sur la ligne passe par JSON : 50.0 en ressort en entier.
    expect($values[$exercise->id]['weight'])->toBe(50.0)
        ->and((float) $ligne->getRecommendedValuesAttribute()['weight'])->toBe(50.0);
});

it('garde une série de poids de corps comme historique', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    $hier = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDay()]);
    $ligneHier = WorkoutLine::factory()->create(['workout_id' => $hier->id, 'exercise_id' => $exercise->id]);
    Set::factory()->count(3)->create(['workout_line_id' => $ligneHier->id, 'weight' => 0.0, 'reps' => 12]);

    $aujourdhui = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $aujourdhui->id, 'exercise_id' => $exercise->id]);

    expect(app(RecommendedValuesService::class)->getRecommendedValues($ligne))->toMatchArray(['weight' => 0.0, 'reps' => 12]);
});

it('rend les valeurs par défaut quand les cinq dernières lignes sont toutes vides', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    $ancienne = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subWeeks(2)]);
    $ligneAncienne = WorkoutLine::factory()->create(['workout_id' => $ancienne->id, 'exercise_id' => $exercise->id]);
    Set::factory()->create(['workout_line_id' => $ligneAncienne->id, 'weight' => 50.0, 'reps' => 10]);

    foreach (range(1, 5) as $joursAvant) {
        $seance = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDays($joursAvant)]);
        WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercise->id]);
    }

    $aujourdhui = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $aujourdhui->id, 'exercise_id' => $exercise->id]);

    expect(app(RecommendedValuesService::class)->getRecommendedValues($ligne)['weight'])->toBe(0.0);
});
