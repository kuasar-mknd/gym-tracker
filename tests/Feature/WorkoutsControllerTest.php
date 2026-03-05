<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

it('renders index correctly for authenticated user', function (): void {
    $user = User::factory()->create();
    Workout::factory(3)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('workouts.index'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page): \Inertia\Testing\AssertableInertia => $page
        ->component('Workouts/Index')
    );
});

it('renders show correctly for workout owner', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('workouts.show', $workout));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page): \Inertia\Testing\AssertableInertia => $page
        ->component('Workouts/Show')
        ->has('workout')
    );
});

it('returns forbidden on show for unauthorized user', function (): void {
    $owner = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $owner->id]);

    $otherUser = User::factory()->create();

    $response = $this->actingAs($otherUser)->get(route('workouts.show', $workout));

    $response->assertStatus(Response::HTTP_FORBIDDEN);
});

it('stores a new workout and redirects', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('workouts.store'));

    $workout = Workout::where('user_id', $user->id)->first();

    expect($workout)->not->toBeNull();
    $response->assertRedirect(route('workouts.show', $workout));
});

it('updates workout and redirects back', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'name' => 'Old Name']);

    // Set an explicit referer so we can test "redirect back" behavior
    $response = $this->actingAs($user)
        ->from(route('workouts.show', $workout))
        ->patch(route('workouts.update', $workout), [
            'name' => 'New Name',
        ]);

    expect($workout->fresh()->name)->toBe('New Name');
    $response->assertRedirect(route('workouts.show', $workout));
});

it('redirects to dashboard when finished during update', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'name' => 'Old Name']);

    $response = $this->actingAs($user)->patch(route('workouts.update', $workout), [
        'name' => 'New Name',
        'is_finished' => true,
    ]);

    expect($workout->fresh()->name)->toBe('New Name');
    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('success', 'Séance terminée !');
});

it('returns validation error on update', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->patch(route('workouts.update', $workout), [
        'name' => str_repeat('a', 256), // Assuming name has max:255
    ]);

    $response->assertSessionHasErrors('name');
});

it('returns forbidden on update for unauthorized user', function (): void {
    $owner = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $owner->id]);

    $otherUser = User::factory()->create();

    $response = $this->actingAs($otherUser)->patch(route('workouts.update', $workout), [
        'name' => 'New Name',
    ]);

    $response->assertStatus(Response::HTTP_FORBIDDEN);
});

it('destroys workout and redirects', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->delete(route('workouts.destroy', $workout));

    expect(Workout::find($workout->id))->toBeNull();
    $response->assertRedirect(route('workouts.index'));
});

it('returns forbidden on destroy for unauthorized user', function (): void {
    $owner = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $owner->id]);

    $otherUser = User::factory()->create();

    $response = $this->actingAs($otherUser)->delete(route('workouts.destroy', $workout));

    $response->assertStatus(Response::HTTP_FORBIDDEN);
    expect(Workout::find($workout->id))->not->toBeNull();
});
