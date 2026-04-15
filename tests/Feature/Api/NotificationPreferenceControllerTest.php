<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('index: unauthenticated user cannot list notification preferences', function (): void {
    $response = $this->getJson(route('api.v1.notification-preferences.index'));
    $response->assertUnauthorized();
});

test('index: authenticated user can list own notification preferences', function (): void {
    NotificationPreference::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'workout_reminder',
        'value' => 60,
    ]);

    // Create preference for other user (should not be seen)
    NotificationPreference::factory()->create([
        'user_id' => $this->otherUser->id,
        'type' => 'workout_reminder',
        'value' => 120,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('api.v1.notification-preferences.index'));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'workout_reminder')
        ->assertJsonPath('data.0.value', 60);
});

test('store: unauthenticated user cannot create notification preference', function (): void {
    $response = $this->postJson(route('api.v1.notification-preferences.store'), []);
    $response->assertUnauthorized();
});

test('store: authenticated user can create notification preference', function (): void {
    $data = [
        'type' => 'daily_summary',
        'value' => 1,
        'is_enabled' => true,
        'is_push_enabled' => false,
    ];

    $response = $this->actingAs($this->user)
        ->postJson(route('api.v1.notification-preferences.store'), $data);

    $response->assertCreated()
        ->assertJsonPath('data.type', 'daily_summary')
        ->assertJsonPath('data.value', 1);

    $this->assertDatabaseHas('notification_preferences', [
        'user_id' => $this->user->id,
        'type' => 'daily_summary',
        'value' => 1,
        'is_enabled' => 1,
        'is_push_enabled' => 0,
    ]);
});

test('store: validates required fields', function (): void {
    $response = $this->actingAs($this->user)
        ->postJson(route('api.v1.notification-preferences.store'), []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);
});

test('store: validates unique type per user', function (): void {
    NotificationPreference::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'weekly_report',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('api.v1.notification-preferences.store'), [
            'type' => 'weekly_report',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);
});

test('store: allows same type for different users', function (): void {
    NotificationPreference::factory()->create([
        'user_id' => $this->otherUser->id,
        'type' => 'weekly_report',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('api.v1.notification-preferences.store'), [
            'type' => 'weekly_report',
        ]);

    $response->assertCreated();
});

test('show: unauthenticated user cannot view notification preference', function (): void {
    $preference = NotificationPreference::factory()->create();
    $response = $this->getJson(route('api.v1.notification-preferences.show', $preference));
    $response->assertUnauthorized();
});

test('show: authenticated user can view own notification preference', function (): void {
    $preference = NotificationPreference::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'goal_achieved',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('api.v1.notification-preferences.show', $preference));

    $response->assertOk()
        ->assertJsonPath('data.id', $preference->id)
        ->assertJsonPath('data.type', 'goal_achieved');
});

test('show: authenticated user cannot view other users notification preference', function (): void {
    $preference = NotificationPreference::factory()->create([
        'user_id' => $this->otherUser->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('api.v1.notification-preferences.show', $preference));

    $response->assertForbidden();
});

test('update: unauthenticated user cannot update notification preference', function (): void {
    $preference = NotificationPreference::factory()->create();
    $response = $this->putJson(route('api.v1.notification-preferences.update', $preference), []);
    $response->assertUnauthorized();
});

test('update: authenticated user can update own notification preference', function (): void {
    $preference = NotificationPreference::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'pr_alert',
        'is_enabled' => false,
    ]);

    $response = $this->actingAs($this->user)
        ->putJson(route('api.v1.notification-preferences.update', $preference), [
            'is_enabled' => true,
            'is_push_enabled' => true,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.is_enabled', true)
        ->assertJsonPath('data.is_push_enabled', true);

    $this->assertDatabaseHas('notification_preferences', [
        'id' => $preference->id,
        'is_enabled' => 1,
        'is_push_enabled' => 1,
    ]);
});

test('update: authenticated user cannot update other users notification preference', function (): void {
    $preference = NotificationPreference::factory()->create([
        'user_id' => $this->otherUser->id,
    ]);

    $response = $this->actingAs($this->user)
        ->putJson(route('api.v1.notification-preferences.update', $preference), [
            'value' => 50,
        ]);

    $response->assertForbidden();
});

test('update: validates unique type on update', function (): void {
    NotificationPreference::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'existing_type',
    ]);

    $preference = NotificationPreference::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'another_type',
    ]);

    $response = $this->actingAs($this->user)
        ->putJson(route('api.v1.notification-preferences.update', $preference), [
            'type' => 'existing_type',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);
});

test('destroy: unauthenticated user cannot delete notification preference', function (): void {
    $preference = NotificationPreference::factory()->create();
    $response = $this->deleteJson(route('api.v1.notification-preferences.destroy', $preference));
    $response->assertUnauthorized();
});

test('destroy: authenticated user can delete own notification preference', function (): void {
    $preference = NotificationPreference::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson(route('api.v1.notification-preferences.destroy', $preference));

    $response->assertNoContent();
    $this->assertDatabaseMissing('notification_preferences', ['id' => $preference->id]);
});

test('destroy: authenticated user cannot delete other users notification preference', function (): void {
    $preference = NotificationPreference::factory()->create([
        'user_id' => $this->otherUser->id,
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson(route('api.v1.notification-preferences.destroy', $preference));

    $response->assertForbidden();
    $this->assertDatabaseHas('notification_preferences', ['id' => $preference->id]);
});
