<?php

declare(strict_types=1);

use App\Actions\Exercises\FetchExerciseHistoryAction;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Carbon;

it('fetches exercise history correctly', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    // Workout 1 (Older)
    $workout1 = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays(5),
    ]);
    $line1 = WorkoutLine::factory()->create([
        'workout_id' => $workout1->id,
        'exercise_id' => $exercise->id,
    ]);
    Set::factory()->create([
        'workout_line_id' => $line1->id,
        'weight' => 100,
        'reps' => 10,
    ]);
    Set::factory()->create([
        'workout_line_id' => $line1->id,
        'weight' => 105,
        'reps' => 5,
    ]);

    // Workout 2 (Newer)
    $workout2 = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays(2),
    ]);
    $line2 = WorkoutLine::factory()->create([
        'workout_id' => $workout2->id,
        'exercise_id' => $exercise->id,
    ]);
    Set::factory()->create([
        'workout_line_id' => $line2->id,
        'weight' => 110,
        'reps' => 8,
    ]);

    $action = app(FetchExerciseHistoryAction::class);
    $history = $action->execute($user, $exercise);

    expect($history)->toHaveCount(2);

    // Assert sorting (descending by started_at)
    expect($history[0]['workout_id'])->toBe($workout2->id);
    expect($history[1]['workout_id'])->toBe($workout1->id);

    // Assert Epley 1RM calculation: 100 * (1 + 10 / 30) = 133.33
    // 105 * (1 + 5 / 30) = 122.5
    // Max is 133.33 for workout 1
    // 110 * (1 + 8 / 30) = 139.33 for workout 2
    expect($history[0]['best_1rm'])->toBe(139.33);
    expect($history[1]['best_1rm'])->toBe(133.33);

    expect($history[0]['formatted_date'])->toBe($workout2->started_at->locale('fr')->isoFormat('ddd D MMM'));
});

it('only includes workouts for the given user', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $exercise = Exercise::factory()->create();

    // User 1 Workout
    $workout1 = Workout::factory()->create([
        'user_id' => $user1->id,
        'started_at' => Carbon::now()->subDays(5),
    ]);
    WorkoutLine::factory()->create([
        'workout_id' => $workout1->id,
        'exercise_id' => $exercise->id,
    ]);

    // User 2 Workout
    $workout2 = Workout::factory()->create([
        'user_id' => $user2->id,
        'started_at' => Carbon::now()->subDays(2),
    ]);
    WorkoutLine::factory()->create([
        'workout_id' => $workout2->id,
        'exercise_id' => $exercise->id,
    ]);

    $action = app(FetchExerciseHistoryAction::class);
    $history = $action->execute($user1, $exercise);

    expect($history)->toHaveCount(1)
        ->and($history[0]['workout_id'])->toBe($workout1->id);
});
