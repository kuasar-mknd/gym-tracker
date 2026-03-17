<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Goal;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;

test('authenticated user can view goals index', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('goals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Goals/Index')
            ->has('goals')
            ->has('exercises')
            ->has('measurementTypes')
        );
});

test('authenticated user can create a valid goal', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    $response = actingAs($user)
        ->post(route('goals.store'), [
            'title' => 'My New Goal',
            'type' => 'weight',
            'target_value' => 100,
            'exercise_id' => $exercise->id,
            'start_value' => 50,
        ]);

    $response->assertRedirect(route('goals.index'));

    $this->assertDatabaseHas('goals', [
        'user_id' => $user->id,
        'title' => 'My New Goal',
        'type' => 'weight',
        'target_value' => 100,
    ]);
});

test('authenticated user can create a measurement goal', function (): void {
    $user = User::factory()->create();

    $response = actingAs($user)
        ->post(route('goals.store'), [
            'title' => 'Lose Weight',
            'type' => 'measurement',
            'target_value' => 70,
            'measurement_type' => 'weight',
            'start_value' => 80,
        ]);

    $response->assertRedirect(route('goals.index'));

    $this->assertDatabaseHas('goals', [
        'user_id' => $user->id,
        'type' => 'measurement',
        'measurement_type' => 'weight',
    ]);
});

test('authenticated user can update their own goal', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'title' => 'Old Title',
        'type' => 'weight',
        'target_value' => 100,
        'exercise_id' => $exercise->id,
    ]);

    $response = actingAs($user)
        ->patch(route('goals.update', $goal), [
            'title' => 'New Title',
            'type' => 'weight',
            'target_value' => 120,
            'exercise_id' => $exercise->id,
        ]);

    $response->assertRedirect(route('goals.index'));

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'title' => 'New Title',
        'target_value' => 120,
    ]);
});

test('authenticated user can delete their own goal', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->create(['user_id' => $user->id]);

    $response = actingAs($user)
        ->delete(route('goals.destroy', $goal));

    $response->assertRedirect(route('goals.index'));

    $this->assertDatabaseMissing('goals', ['id' => $goal->id]);
});

// Validation Tests

test('create goal requires title and type', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('goals.store'), [])
        ->assertSessionHasErrors(['title', 'type', 'target_value']);
});

test('weight goal requires exercise_id', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('goals.store'), [
            'title' => 'Test',
            'type' => 'weight',
            'target_value' => 100,
            // missing exercise_id
        ])
        ->assertSessionHasErrors(['exercise_id']);
});

test('measurement goal requires measurement_type', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('goals.store'), [
            'title' => 'Test',
            'type' => 'measurement',
            'target_value' => 100,
            // missing measurement_type
        ])
        ->assertSessionHasErrors(['measurement_type']);
});

test('target_value must be numeric and positive', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    actingAs($user)
        ->post(route('goals.store'), [
            'title' => 'Test',
            'type' => 'weight',
            'exercise_id' => $exercise->id,
            'target_value' => 'not-a-number',
        ])
        ->assertSessionHasErrors(['target_value']);

    actingAs($user)
        ->post(route('goals.store'), [
            'title' => 'Test',
            'type' => 'weight',
            'exercise_id' => $exercise->id,
            'target_value' => -5,
        ])
        ->assertSessionHasErrors(['target_value']);
});

test('exercise_id must exist', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('goals.store'), [
            'title' => 'Test',
            'type' => 'weight',
            'target_value' => 100,
            'exercise_id' => 999999,
        ])
        ->assertSessionHasErrors(['exercise_id']);
});

// Authorization Tests

test('unauthenticated user cannot view goals', function (): void {
    get(route('goals.index'))
        ->assertRedirect(route('login'));
});

test('unauthenticated user cannot create goal', function (): void {
    post(route('goals.store'), [])
        ->assertRedirect(route('login'));
});

test('unauthenticated user cannot update goal', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->create(['user_id' => $user->id]);

    patch(route('goals.update', $goal), [])
        ->assertRedirect(route('login'));
});

test('unauthenticated user cannot delete goal', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->create(['user_id' => $user->id]);

    delete(route('goals.destroy', $goal))
        ->assertRedirect(route('login'));
});

test('user cannot update another users goal', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $exercise = Exercise::factory()->create(); // System exercise or user's exercise
    $goal = Goal::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->patch(route('goals.update', $goal), [
            'title' => 'Hacked',
            'type' => 'weight',
            'target_value' => 100,
            'exercise_id' => $exercise->id,
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'title' => $goal->title,
    ]);
});

test('user cannot delete another users goal', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $goal = Goal::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->delete(route('goals.destroy', $goal))
        ->assertForbidden();

    $this->assertDatabaseHas('goals', ['id' => $goal->id]);
});
