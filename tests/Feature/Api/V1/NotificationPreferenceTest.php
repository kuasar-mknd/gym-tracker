<?php

use App\Models\NotificationPreference;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('users can list their notification preferences', function (): void {
    $user = User::factory()->create();
    NotificationPreference::factory()->count(3)->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/notification-preferences');

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('users cannot see others notification preferences', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    NotificationPreference::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/notification-preferences');

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});

test('users can create a notification preference', function (): void {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/notification-preferences', [
        'type' => 'daily_reminder',
        'value' => 10,
        'is_enabled' => true,
        'is_push_enabled' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.type', 'daily_reminder')
        ->assertJsonPath('data.value', 10);

    $this->assertDatabaseHas('notification_preferences', [
        'user_id' => $user->id,
        'type' => 'daily_reminder',
    ]);
});

test('users cannot create duplicate notification preference type', function (): void {
    $user = User::factory()->create();
    NotificationPreference::factory()->create(['user_id' => $user->id, 'type' => 'daily_reminder']);

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/notification-preferences', [
        'type' => 'daily_reminder',
        'is_enabled' => true,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);
});

test('users can update their notification preference', function (): void {
    $user = User::factory()->create();
    $preference = NotificationPreference::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = $this->putJson("/api/v1/notification-preferences/{$preference->id}", [
        'is_enabled' => false,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.is_enabled', false);

    $this->assertDatabaseHas('notification_preferences', [
        'id' => $preference->id,
        'is_enabled' => false,
    ]);
});

test('users cannot update others notification preference', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $preference = NotificationPreference::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = $this->putJson("/api/v1/notification-preferences/{$preference->id}", [
        'is_enabled' => false,
    ]);

    $response->assertForbidden();
});

test('users can delete their notification preference', function (): void {
    $user = User::factory()->create();
    $preference = NotificationPreference::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = $this->deleteJson("/api/v1/notification-preferences/{$preference->id}");

    $response->assertNoContent();

    $this->assertDatabaseMissing('notification_preferences', ['id' => $preference->id]);
});

test('users cannot delete others notification preference', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $preference = NotificationPreference::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = $this->deleteJson("/api/v1/notification-preferences/{$preference->id}");

    $response->assertForbidden();
});
