<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

test('user can update push subscription', function (): void {
    $user = User::factory()->create();

    $response = actingAs($user)->post(route('push-subscriptions.update'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/12345',
        'keys' => [
            'auth' => 'auth-key',
            'p256dh' => 'p256dh-key',
        ],
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'Abonnement enregistré avec succès.']);

    // Since we can't easily assert the internal state of the PushSubscription trait's tables
    // without knowing the exact table structure managed by the library,
    // we assume success if the controller returns 200 and no exception occurred.
    // However, usually the table is 'push_subscriptions'.
    $this->assertDatabaseHas('push_subscriptions', [
        'subscribable_id' => $user->id,
        'subscribable_type' => User::class,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/12345',
    ]);
});

test('user can delete push subscription', function (): void {
    $user = User::factory()->create();

    // First create a subscription
    actingAs($user)->post(route('push-subscriptions.update'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/12345',
        'keys' => [
            'auth' => 'auth-key',
            'p256dh' => 'p256dh-key',
        ],
    ]);

    $response = actingAs($user)->post(route('push-subscriptions.destroy'), [
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

    $response = actingAs($user)->post(route('push-subscriptions.update'), []);

    $response->assertSessionHasErrors(['endpoint', 'keys.auth', 'keys.p256dh']);
});

test('delete requires endpoint', function (): void {
    $user = User::factory()->create();

    $response = actingAs($user)->post(route('push-subscriptions.destroy'), []);

    $response->assertSessionHasErrors(['endpoint']);
});

test('guest cannot update push subscription', function (): void {
    $response = post(route('push-subscriptions.update'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/12345',
        'keys' => [
            'auth' => 'auth-key',
            'p256dh' => 'p256dh-key',
        ],
    ]);

    $response->assertRedirect(route('login'));
});

test('guest cannot delete push subscription', function (): void {
    $response = post(route('push-subscriptions.destroy'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/12345',
    ]);

    $response->assertRedirect(route('login'));
});
