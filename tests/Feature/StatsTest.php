<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('authenticated user can view stats page', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('stats.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Stats/Index')
            ->has('volumeTrend')
            ->has('muscleDistribution')
            ->has('monthlyComparison')
            ->has('exercises')
        );
});

test('stats page calculates volume trend correctly', function (): void {
    $user = User::factory()->create();

    // Create a workout 2 days ago
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => now()->subDays(2),
    ]);

    $exercise = Exercise::factory()->create();

    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    // Set 1: 100kg * 10 reps = 1000 volume
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 10,
    ]);

    // Set 2: 50kg * 10 reps = 500 volume
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 50,
        'reps' => 10,
    ]);

    // Total volume should be 1500

    actingAs($user)
        ->get(route('stats.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Stats/Index')
            ->where('volumeTrend.0.volume', 1500)
            ->where('volumeTrend.0.name', $workout->name)
        );
});

test('stats page calculates muscle distribution correctly', function (): void {
    $user = User::factory()->create();

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => now(),
    ]);

    // Chest Exercise
    $chestExercise = Exercise::factory()->create(['category' => 'Pectoraux']);
    $chestLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $chestExercise->id,
    ]);
    Set::factory()->create([
        'workout_line_id' => $chestLine->id,
        'weight' => 100,
        'reps' => 10,
    ]); // 1000 volume

    // Back Exercise
    $backExercise = Exercise::factory()->create(['category' => 'Dos']);
    $backLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $backExercise->id,
    ]);
    Set::factory()->create([
        'workout_line_id' => $backLine->id,
        'weight' => 50,
        'reps' => 10,
    ]); // 500 volume

    actingAs($user)
        ->get(route('stats.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Stats/Index')
            ->has('muscleDistribution', 2)
            ->where('muscleDistribution', function ($distribution): bool {
                $chest = collect($distribution)->firstWhere('category', 'Pectoraux');
                $back = collect($distribution)->firstWhere('category', 'Dos');

                return $chest['volume'] == 1000 && $back['volume'] == 500;
            })
        );
});

test('stats page calculates monthly comparison correctly', function (): void {
    $user = User::factory()->create();

    // Current Month Workout
    $currentWorkout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => now()->startOfMonth()->addDay(),
    ]);
    $currentLine = WorkoutLine::factory()->create(['workout_id' => $currentWorkout->id]);
    Set::factory()->create([
        'workout_line_id' => $currentLine->id,
        'weight' => 100,
        'reps' => 10,
    ]); // 1000 volume

    // Previous Month Workout
    $prevWorkout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => now()->subMonth()->startOfMonth()->addDay(),
    ]);
    $prevLine = WorkoutLine::factory()->create(['workout_id' => $prevWorkout->id]);
    Set::factory()->create([
        'workout_line_id' => $prevLine->id,
        'weight' => 50,
        'reps' => 10,
    ]); // 500 volume

    // Difference: 1000 - 500 = 500.
    // Percentage: (500 / 500) * 100 = 100% increase.

    actingAs($user)
        ->get(route('stats.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Stats/Index')
            ->where('monthlyComparison.current_month_volume', fn ($val): bool => $val == 1000)
            ->where('monthlyComparison.previous_month_volume', fn ($val): bool => $val == 500)
            ->where('monthlyComparison.percentage', fn ($val): bool => $val == 100)
        );
});

test('can retrieve exercise progress (1RM)', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => now()->subDays(5),
    ]);

    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    // 100kg x 30 reps. Epley: 100 * (1 + 30/30) = 200.
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 30,
    ]);

    actingAs($user)
        ->get(route('stats.exercise', $exercise))
        ->assertOk()
        ->assertJsonStructure(['progress'])
        ->assertJsonPath('progress.0.one_rep_max', fn ($val): bool => $val == 200);
});

test('unauthenticated user cannot access stats', function (): void {
    get(route('stats.index'))
        ->assertRedirect(route('login'));

    $exercise = Exercise::factory()->create();
    get(route('stats.exercise', $exercise))
        ->assertRedirect(route('login'));
});

test('cannot view stats for non-existent exercise', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->get('/stats/exercise/999999') // Invalid ID
        ->assertNotFound();
});

test('stats do not include other users data', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    // Other user's workout
    $workout = Workout::factory()->create([
        'user_id' => $otherUser->id,
        'started_at' => now(),
    ]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 1000,
        'reps' => 10,
    ]);

    actingAs($user)
        ->get(route('stats.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Stats/Index')
            ->where('volumeTrend', []) // Should be empty for this user
        );
});
