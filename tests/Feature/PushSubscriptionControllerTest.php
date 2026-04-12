<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('PushSubscriptionController', function () {
    describe('update', function () {
        it('allows a user to save a valid push subscription', function () {
            $user = User::factory()->create();

            $payload = [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint',
                'keys' => [
                    'p256dh' => 'fake-p256dh-key',
                    'auth' => 'fake-auth-key',
                ],
            ];

            $response = $this->actingAs($user)
                ->postJson(route('push-subscriptions.update'), $payload);

            $response->assertOk()
                ->assertJson(['message' => 'Abonnement enregistré avec succès.']);

            $this->assertDatabaseHas('push_subscriptions', [
                'subscribable_type' => User::class,
                'subscribable_id' => $user->id,
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint',
            ]);
        });

        it('returns validation errors for missing endpoint', function () {
            $user = User::factory()->create();

            $payload = [
                'keys' => [
                    'p256dh' => 'fake-p256dh-key',
                    'auth' => 'fake-auth-key',
                ],
            ];

            $response = $this->actingAs($user)
                ->postJson(route('push-subscriptions.update'), $payload);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['endpoint']);
        });

        it('returns validation errors for missing keys', function () {
            $user = User::factory()->create();

            $payload = [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint',
            ];

            $response = $this->actingAs($user)
                ->postJson(route('push-subscriptions.update'), $payload);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['keys.auth', 'keys.p256dh']);
        });

        it('redirects or forbids a guest user', function () {
            $payload = [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint',
                'keys' => [
                    'p256dh' => 'fake-p256dh-key',
                    'auth' => 'fake-auth-key',
                ],
            ];

            $response = $this->postJson(route('push-subscriptions.update'), $payload);

            $response->assertUnauthorized();
        });
    });

    describe('destroy', function () {
        it('allows a user to delete an existing push subscription', function () {
            $user = User::factory()->create();
            $user->updatePushSubscription(
                'https://fcm.googleapis.com/fcm/send/fake-endpoint',
                'fake-p256dh-key',
                'fake-auth-key'
            );

            $this->assertDatabaseHas('push_subscriptions', [
                'subscribable_id' => $user->id,
            ]);

            $payload = [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint',
            ];

            $response = $this->actingAs($user)
                ->postJson(route('push-subscriptions.destroy'), $payload);

            $response->assertOk()
                ->assertJson(['message' => 'Abonnement supprimé avec succès.']);

            $this->assertDatabaseMissing('push_subscriptions', [
                'subscribable_id' => $user->id,
            ]);
        });

        it('returns validation errors for missing endpoint on deletion', function () {
            $user = User::factory()->create();

            $payload = [];

            $response = $this->actingAs($user)
                ->postJson(route('push-subscriptions.destroy'), $payload);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['endpoint']);
        });

        it('returns validation error for invalid url format', function () {
            $user = User::factory()->create();

            $payload = [
                'endpoint' => 'not-a-valid-url',
            ];

            $response = $this->actingAs($user)
                ->postJson(route('push-subscriptions.destroy'), $payload);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['endpoint']);
        });

        it('redirects or forbids a guest user from deleting', function () {
            $payload = [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint',
            ];

            $response = $this->postJson(route('push-subscriptions.destroy'), $payload);

            $response->assertUnauthorized();
        });
    });
});
