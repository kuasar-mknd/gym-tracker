<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('dashboard route is protected', function (): void {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('profile route is protected', function (): void {
    $response = $this->get('/profile');
    $response->assertRedirect(route('login'));
});

test('workouts route is protected', function (): void {
    $response = $this->get(route('workouts.index'));
    $response->assertRedirect(route('login'));
});

test('public routes are accessible', function (): void {
    $response = $this->get(route('login'));
    $response->assertOk();

    $response = $this->get(route('register'));
    $response->assertOk();
});

test('update preferences validates input', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patch(route('profile.preferences.update'), [
        'preferences' => [
            'invalid_type' => true,
        ],
        'push_preferences' => [],
    ]);

    $response->assertSessionHasErrors(['preferences']);
});

test('update preferences updates user preferences', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patch(route('profile.preferences.update'), [
        'preferences' => [
            'daily_reminder' => true,
        ],
        'push_preferences' => [
            'daily_reminder' => false,
        ],
        'values' => [
            'daily_reminder' => 1,
        ],
    ]);

    $response->assertRedirect(route('profile.edit'));

    $this->assertDatabaseHas('notification_preferences', [
        'user_id' => $user->id,
        'type' => 'daily_reminder',
        'is_enabled' => true,
        'is_push_enabled' => false,
        'value' => 1,
    ]);
});
