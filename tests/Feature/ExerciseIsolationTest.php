<?php

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

test('users can only see system exercises and their own custom exercises in stats', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    // System Exercise
    $systemExercise = Exercise::factory()->create(['name' => 'System Exercise', 'user_id' => null]);

    // User A Custom Exercise
    $userAExercise = Exercise::factory()->create(['name' => 'User A Exercise', 'user_id' => $userA->id]);

    // User B Custom Exercise
    $userBExercise = Exercise::factory()->create(['name' => 'User B Exercise', 'user_id' => $userB->id]);

    actingAs($userA)
        ->get(route('stats.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Stats/Index')
            ->has('exercises', 2) // Should only have System + User A
            ->where('exercises.0.name', 'System Exercise') // Assuming order by name
            ->where('exercises.1.name', 'User A Exercise')
        );
});

test('users can only see system exercises and their own custom exercises in workout show', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $workout = Workout::factory()->create(['user_id' => $userA->id]);

    // System Exercise
    $systemExercise = Exercise::factory()->create(['name' => 'System Exercise', 'user_id' => null]);

    // User A Custom Exercise
    $userAExercise = Exercise::factory()->create(['name' => 'User A Exercise', 'user_id' => $userA->id]);

    // User B Custom Exercise
    $userBExercise = Exercise::factory()->create(['name' => 'User B Exercise', 'user_id' => $userB->id]);

    actingAs($userA)
        ->get(route('workouts.show', $workout))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Workouts/Show')
            ->has('exercises', 2) // Should only have System + User A
            ->where('exercises.0.name', 'System Exercise')
            ->where('exercises.1.name', 'User A Exercise')
        );
});
