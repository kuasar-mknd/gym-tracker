<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Goal;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

it('displays the goals index page for an authenticated user', function (): void {
    $user = User::factory()->create();
    Goal::factory()->count(3)->create(['user_id' => $user->id]);
    Exercise::factory()->count(2)->create(['user_id' => $user->id]);

    actingAs($user)
        ->get('/goals')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Goals/Index')
                ->has('goals', 3)
                ->has('exercises', 2)
                ->has('measurementTypes', 5)
        );
});

it('redirects an unauthenticated user attempting to view goals index', function (): void {
    get('/goals')->assertRedirect('/login');
});

it('can store a new weight goal', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => null]);

    $data = [
        'title' => 'Squat 100kg',
        'type' => 'weight',
        'target_value' => 100,
        'start_value' => 50,
        'exercise_id' => $exercise->id,
        'deadline' => now()->addMonths(3)->format('Y-m-d'),
    ];

    actingAs($user)
        ->post('/goals', $data)
        ->assertRedirect(route('goals.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('goals', [
        'user_id' => $user->id,
        'title' => 'Squat 100kg',
        'type' => 'weight',
        'target_value' => 100,
        'start_value' => 50,
        'exercise_id' => $exercise->id,
    ]);
});

it('can store a new measurement goal', function (): void {
    $user = User::factory()->create();

    $data = [
        'title' => 'Lose 5kg',
        'type' => 'measurement',
        'target_value' => 75,
        'measurement_type' => 'weight',
        'deadline' => now()->addMonths(3)->format('Y-m-d'),
    ];

    actingAs($user)
        ->post('/goals', $data)
        ->assertRedirect(route('goals.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('goals', [
        'user_id' => $user->id,
        'title' => 'Lose 5kg',
        'type' => 'measurement',
        'target_value' => 75,
        'measurement_type' => 'weight',
        'start_value' => 0,
    ]);
});

it('can update an existing goal', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => null]);
    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => 'weight',
        'exercise_id' => $exercise->id,
        'target_value' => 100,
    ]);

    $data = [
        'title' => 'Squat 110kg',
        'type' => 'weight',
        'target_value' => 110,
        'start_value' => $goal->start_value,
        'exercise_id' => $exercise->id,
        'deadline' => now()->addMonths(6)->format('Y-m-d'),
    ];

    actingAs($user)
        ->put("/goals/{$goal->id}", $data)
        ->assertRedirect(route('goals.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'title' => 'Squat 110kg',
        'target_value' => 110,
    ]);
});

it('cannot update another users goal', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => null]);

    $goal = Goal::factory()->create([
        'user_id' => $user1->id,
        'type' => 'weight',
        'exercise_id' => $exercise->id,
        'target_value' => 100,
    ]);

    $data = [
        'title' => 'Squat 110kg',
        'type' => 'weight',
        'target_value' => 110,
        'exercise_id' => $exercise->id,
    ];

    actingAs($user2)
        ->put("/goals/{$goal->id}", $data)
        ->assertForbidden();

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'title' => $goal->title,
        'target_value' => 100,
    ]);
});

it('can delete an existing goal', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->delete("/goals/{$goal->id}")
        ->assertRedirect(route('goals.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('goals', [
        'id' => $goal->id,
    ]);
});

it('cannot delete another users goal', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $goal = Goal::factory()->create(['user_id' => $user1->id]);

    actingAs($user2)
        ->delete("/goals/{$goal->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
    ]);
});

it('validates required fields on store', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post('/goals', [])
        ->assertRedirect()
        ->assertSessionHasErrors(['title', 'type', 'target_value']);
});

it('validates required fields on update', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->put("/goals/{$goal->id}", [])
        ->assertRedirect()
        ->assertSessionHasErrors(['title', 'type', 'target_value']);
});

it('validates conditional required fields on store', function (): void {
    $user = User::factory()->create();

    // Type is weight, but missing exercise_id
    actingAs($user)
        ->post('/goals', [
            'title' => 'Weight Goal',
            'type' => 'weight',
            'target_value' => 100,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors(['exercise_id']);

    // Type is measurement, but missing measurement_type
    actingAs($user)
        ->post('/goals', [
            'title' => 'Measurement Goal',
            'type' => 'measurement',
            'target_value' => 80,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors(['measurement_type']);
});
