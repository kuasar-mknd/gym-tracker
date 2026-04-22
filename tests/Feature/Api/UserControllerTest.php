<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    Permission::firstOrCreate(['name' => 'ViewAny:User', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'View:User', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'Create:User', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'Update:User', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'Delete:User', 'guard_name' => 'web']);
});

test('user with permission can list users', function (): void {
    $admin = User::factory()->create();
    Gate::before(fn (): true => true);
    User::factory()->count(3)->create();

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/users');

    $response->assertStatus(200)
        ->assertJsonStructure(['data', 'meta', 'links']);
});

test('user without permission cannot list users', function (): void {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/users');

    $response->assertForbidden();
});

test('user can view their own profile', function (): void {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/v1/users/{$user->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', $user->id);
});

test('user with permission can view another user', function (): void {
    $admin = User::factory()->create();
    Gate::before(fn (): true => true);
    $otherUser = User::factory()->create();

    Sanctum::actingAs($admin);

    $response = $this->getJson("/api/v1/users/{$otherUser->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', $otherUser->id);
});

test('user without permission cannot view another user', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/v1/users/{$otherUser->id}");

    $response->assertForbidden();
});

test('unauthenticated user cannot view user', function (): void {
    $user = User::factory()->create();
    $response = $this->getJson("/api/v1/users/{$user->id}");

    $response->assertStatus(401);
});

test('user with permission can create a user', function (): void {
    $admin = User::factory()->create();
    Gate::before(fn (): true => true);
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/users', [
        'name' => 'New User',
        'email' => 'newuser@example.com',
        'password' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'New User')
        ->assertJsonPath('data.email', 'newuser@example.com');

    $this->assertDatabaseHas('users', ['email' => 'newuser@example.com']);
});

test('user without permission cannot create a user', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/users', [
        'name' => 'New User',
        'email' => 'newuser@example.com',
        'password' => 'password123',
    ]);

    $response->assertForbidden();
});

test('validation error on store', function (): void {
    $admin = User::factory()->create();
    Gate::before(fn (): true => true);
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/users', [
        'name' => 'New User',
        'email' => 'invalid-email',
        // missing password
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

test('user can update their own profile', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->putJson("/api/v1/users/{$user->id}", [
        'name' => 'Updated Name',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Updated Name');
});

test('user with permission can update another user', function (): void {
    $admin = User::factory()->create();
    Gate::before(fn (): true => true);
    $otherUser = User::factory()->create();
    Sanctum::actingAs($admin);

    $response = $this->putJson("/api/v1/users/{$otherUser->id}", [
        'name' => 'Updated Other User Name',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Updated Other User Name');
});

test('user without permission cannot update another user', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->putJson("/api/v1/users/{$otherUser->id}", [
        'name' => 'Hacked Name',
    ]);

    $response->assertForbidden();
});

test('validation error on update', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->putJson("/api/v1/users/{$user->id}", [
        'email' => 'invalid-email',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('user can delete their own profile', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->deleteJson("/api/v1/users/{$user->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

test('user with permission can delete another user', function (): void {
    $admin = User::factory()->create();
    Gate::before(fn (): true => true);
    $otherUser = User::factory()->create();
    Sanctum::actingAs($admin);

    $response = $this->deleteJson("/api/v1/users/{$otherUser->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('users', ['id' => $otherUser->id]);
});

test('user without permission cannot delete another user', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->deleteJson("/api/v1/users/{$otherUser->id}");

    $response->assertForbidden();
});
