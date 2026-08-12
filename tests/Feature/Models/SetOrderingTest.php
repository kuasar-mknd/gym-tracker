<?php

declare(strict_types=1);

use App\Actions\Workouts\FetchWorkoutShowAction;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

use function Pest\Laravel\actingAs;

/**
 * A set has no order of its own — creation order is the only order it has, and
 * every screen renders it as such. Without an explicit ORDER BY the database
 * hands the rows back in whatever order the index it picked happens to give,
 * and this table carries sets_workout_line_id_weight_reps_index
 * (workout_line_id, weight, reps) next to the plain workout_line_id one. When
 * the optimiser chooses that one the sets come back sorted BY WEIGHT, so
 * correcting the weight of a set moved it up or down the list.
 *
 * The weights below descend as the ids ascend, so a weight-ordered result is
 * the exact reverse of the right answer.
 */
$lineWithDescendingWeights = function (): WorkoutLine {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => Exercise::factory()->create()->id,
    ]);

    foreach ([100, 80, 60, 40] as $weight) {
        Set::factory()->create([
            'workout_line_id' => $line->id,
            'weight' => $weight,
            'reps' => 5,
        ]);
    }

    return $line;
};

test('the sets relation asks the database for an order', function (): void {
    $line = WorkoutLine::factory()->make();

    expect($line->sets()->toSql())->toContain('order by');
});

test('sets come back in the order they were created', function () use ($lineWithDescendingWeights): void {
    $line = $lineWithDescendingWeights();
    $expected = Set::where('workout_line_id', $line->id)->orderBy('id')->pluck('id')->all();

    expect($line->sets()->pluck('id')->all())->toBe($expected);
    expect(WorkoutLine::findOrFail($line->id)->sets->pluck('id')->all())->toBe($expected);
});

test('eager loading a workout keeps its sets in creation order', function () use ($lineWithDescendingWeights): void {
    $line = $lineWithDescendingWeights();
    $expected = Set::where('workout_line_id', $line->id)->orderBy('id')->pluck('id')->all();

    $workout = Workout::with('workoutLines.sets')->findOrFail($line->workout_id);

    expect($workout->workoutLines->firstOrFail()->sets->pluck('id')->all())->toBe($expected);
});

test('the session screen renders sets in creation order', function () use ($lineWithDescendingWeights): void {
    $line = $lineWithDescendingWeights();
    $workout = Workout::findOrFail($line->workout_id);
    $user = User::findOrFail($workout->user_id);
    $expected = Set::where('workout_line_id', $line->id)->orderBy('id')->pluck('id')->all();

    actingAs($user);

    $data = app(FetchWorkoutShowAction::class)->execute($user, $workout);

    expect($data['workout']->workoutLines->firstOrFail()->sets->pluck('id')->all())->toBe($expected);
});

/**
 * `order` alone does not settle the exercises either: the column defaults to 0,
 * so every line written before CreateWorkoutLineAction started assigning max+1
 * shares one value and their relative order is undefined.
 */
test('exercises sharing an order value fall back to creation order', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);

    $lines = collect(range(1, 4))->map(fn (): WorkoutLine => WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => Exercise::factory()->create()->id,
        'order' => 0,
    ]));

    expect($workout->workoutLines()->pluck('id')->all())->toBe($lines->pluck('id')->all());
});

test('an explicit order still wins over the id tie-break', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);

    $second = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => Exercise::factory()->create()->id,
        'order' => 5,
    ]);

    $first = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => Exercise::factory()->create()->id,
        'order' => 1,
    ]);

    expect($workout->workoutLines()->pluck('id')->all())->toBe([$first->id, $second->id]);
});
