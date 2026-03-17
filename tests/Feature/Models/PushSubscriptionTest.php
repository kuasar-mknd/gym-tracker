<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

test('user can update push subscription', function (): void {
    $user = User::factory()->create();

    $response = actingAs($user)->postJson(route('push-subscriptions.update'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/12345',
        'keys' => [
            'auth' => 'auth-key',
            'p256dh' => 'p256dh-key',
        ],
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'Abonnement enregistré avec succès.']);

    $this->assertDatabaseHas('push_subscriptions', [
        'subscribable_id' => $user->id,
        'subscribable_type' => User::class,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/12345',
    ]);
});

test('user can delete push subscription', function (): void {
    $user = User::factory()->create();

    // First create a subscription
    actingAs($user)->postJson(route('push-subscriptions.update'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/12345',
        'keys' => [
            'auth' => 'auth-key',
            'p256dh' => 'p256dh-key',
        ],
    ]);

    $response = actingAs($user)->postJson(route('push-subscriptions.destroy'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/12345',
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'Abonnement supprimé avec succès.']);

    $this->assertDatabaseMissing('push_subscriptions', [
        'subscribable_id' => $user->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/12345',
    ]);
});

test('update requires endpoint and keys', function (): void {
    $user = User::factory()->create();

    $response = actingAs($user)->postJson(route('push-subscriptions.update'), []);

    $response->assertJsonValidationErrors(['endpoint', 'keys.auth', 'keys.p256dh']);
});

test('delete requires endpoint', function (): void {
    $user = User::factory()->create();

    $response = actingAs($user)->postJson(route('push-subscriptions.destroy'), []);

    $response->assertJsonValidationErrors(['endpoint']);
});

test('guest cannot update push subscription', function (): void {
    $response = postJson(route('push-subscriptions.update'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/12345',
        'keys' => [
            'auth' => 'auth-key',
            'p256dh' => 'p256dh-key',
        ],
    ]);

    $response->assertUnauthorized();
});

test('guest cannot delete push subscription', function (): void {
    $response = postJson(route('push-subscriptions.destroy'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/12345',
    ]);

    $response->assertUnauthorized();
});
