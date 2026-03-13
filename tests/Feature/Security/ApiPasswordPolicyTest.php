<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('StoreUserRequest enforces password defaults', function (): void {
    $admin = User::factory()->create();
    Sanctum::actingAs($admin);

    // Mocking an admin because UserController::store requires 'create' authorization.
    // In this app, admins are usually handled by filament but here we are testing the API.
    // The UserPolicy requires 'Create:User' permission.
    // For simplicity in this test, we just want to see if the request validation fails on a weak password.

    $response = $this->postJson(route('api.v1.users.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'short',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['password']);
});

test('UpdateUserRequest enforces password defaults', function (): void {
    $admin = User::factory()->create();
    $user = User::factory()->create();
    Sanctum::actingAs($admin);

    $response = $this->patchJson(route('api.v1.users.update', $user), [
        'password' => 'short',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['password']);
});
