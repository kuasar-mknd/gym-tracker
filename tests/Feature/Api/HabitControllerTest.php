<?php

declare(strict_types=1);

use App\Models\Habit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('returns habits for authenticated user', function (): void {
    Habit::factory()->count(3)->create(['user_id' => $this->user->id]);
    Habit::factory()->count(2)->create(); // other user's habits

    $response = $this->actingAs($this->user)->getJson('/api/v1/habits');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data');
});

it('returns unauthenticated for guest', function (): void {
    $response = $this->getJson('/api/v1/habits');

    $response->assertStatus(401);
});

it('creates habit with valid data', function (): void {
    $data = [
        'name' => 'Read a book',
        'description' => 'Read at least 10 pages',
        'goal_times_per_week' => 5,
    ];

    $response = $this->actingAs($this->user)->postJson('/api/v1/habits', $data);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Read a book')
        ->assertJsonPath('data.goal_times_per_week', 5);

    $this->assertDatabaseHas('habits', [
        'user_id' => $this->user->id,
        'name' => 'Read a book',
    ]);
});

it('returns validation error for invalid data on store', function (): void {
    $data = [
        // 'name' is required
        'goal_times_per_week' => 8, // max is 7
    ];

    $response = $this->actingAs($this->user)->postJson('/api/v1/habits', $data);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'goal_times_per_week']);
});

it('returns habit for owner', function (): void {
    $habit = Habit::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAs($this->user)->getJson("/api/v1/habits/{$habit->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', $habit->id);
});

it('returns forbidden for other users habit on show', function (): void {
    $otherUser = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($this->user)->getJson("/api/v1/habits/{$habit->id}");

    $response->assertStatus(403);
});

it('modifies habit with valid data', function (): void {
    $habit = Habit::factory()->create(['user_id' => $this->user->id]);

    $data = [
        'name' => 'Updated Habit Name',
    ];

    $response = $this->actingAs($this->user)->putJson("/api/v1/habits/{$habit->id}", $data);

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'Updated Habit Name');

    $this->assertDatabaseHas('habits', [
        'id' => $habit->id,
        'name' => 'Updated Habit Name',
    ]);
});

it('returns validation error for invalid data on update', function (): void {
    $habit = Habit::factory()->create(['user_id' => $this->user->id]);

    $data = [
        'goal_times_per_week' => 10, // max is 7
    ];

    $response = $this->actingAs($this->user)->putJson("/api/v1/habits/{$habit->id}", $data);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['goal_times_per_week']);
});

it('returns forbidden for other users habit on update', function (): void {
    $otherUser = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $otherUser->id]);

    $data = [
        'name' => 'Nice try',
    ];

    $response = $this->actingAs($this->user)->putJson("/api/v1/habits/{$habit->id}", $data);

    $response->assertStatus(403);
});

it('deletes habit for owner', function (): void {
    $habit = Habit::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAs($this->user)->deleteJson("/api/v1/habits/{$habit->id}");

    $response->assertStatus(204);

    $this->assertDatabaseMissing('habits', [
        'id' => $habit->id,
    ]);
});

it('returns forbidden for other users habit on destroy', function (): void {
    $otherUser = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($this->user)->deleteJson("/api/v1/habits/{$habit->id}");

    $response->assertStatus(403);

    $this->assertDatabaseHas('habits', [
        'id' => $habit->id,
    ]);
});
