<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    //
});

test('user can list achievements', function (): void {
    $user = User::factory()->create();
    Achievement::factory()->count(3)->create();

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/achievements');

    $response->assertStatus(200);
});

test('user can view a specific achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/v1/achievements/{$achievement->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', $achievement->id);
});

test('unauthenticated user cannot list achievements', function (): void {
    $response = $this->getJson('/api/v1/achievements');

    $response->assertStatus(401);
});

test('unauthenticated user cannot view achievement', function (): void {
    $achievement = Achievement::factory()->create();
    $response = $this->getJson("/api/v1/achievements/{$achievement->id}");

    $response->assertStatus(401);
});

test('user cannot create achievement', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/achievements', [
        'slug' => 'new-slug',
        'name' => 'New Name',
        'description' => 'Description',
        'icon' => '🏆',
        'type' => 'workout_count',
        'threshold' => 10,
        'category' => 'beginner',
    ]);

    $response->assertForbidden();
});

test('admin with permission can create achievement', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Gate::before(fn ($user, $ability): bool => true);

    $response = $this->postJson('/api/v1/achievements', [
        'slug' => 'new-achievement',
        'name' => 'New Achievement',
        'description' => 'Great achievement',
        'icon' => '🏆',
        'type' => 'streak',
        'threshold' => 5,
        'category' => 'beginner',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.slug', 'new-achievement');
});

test('validation error on store', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Gate::before(fn ($user, $ability): bool => true);

    $response = $this->postJson('/api/v1/achievements', [
        // Missing required fields
        'name' => 'New Achievement',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['slug', 'description', 'icon', 'type', 'threshold', 'category']);
});

test('user cannot update achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->putJson("/api/v1/achievements/{$achievement->id}", [
        'name' => 'Updated Name',
    ]);

    $response->assertForbidden();
});

test('admin with permission can update achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();
    Sanctum::actingAs($user);

    Gate::before(fn ($user, $ability): bool => true);

    $response = $this->putJson("/api/v1/achievements/{$achievement->id}", [
        'slug' => 'updated-slug',
        'name' => 'Updated Achievement Name',
        'description' => 'Great achievement',
        'icon' => '🏆',
        'type' => 'streak',
        'threshold' => 5,
        'category' => 'intermediate',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Updated Achievement Name');
});

test('validation error on update', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();
    Sanctum::actingAs($user);

    Gate::before(fn ($user, $ability): bool => true);

    $response = $this->putJson("/api/v1/achievements/{$achievement->id}", [
        'slug' => '', // empty slug
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['slug']);
});

test('user cannot delete achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->deleteJson("/api/v1/achievements/{$achievement->id}");

    $response->assertForbidden();
});

test('admin with permission can delete achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();
    Sanctum::actingAs($user);

    Gate::before(fn ($user, $ability): bool => true);

    $response = $this->deleteJson("/api/v1/achievements/{$achievement->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('achievements', ['id' => $achievement->id]);
});
