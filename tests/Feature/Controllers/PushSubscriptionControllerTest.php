<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

uses()->group('controllers', 'push-subscriptions');

/**
 * The account each test subscribes and unsubscribes.
 *
 * Handed back rather than parked on $this by beforeEach: Pest binds the test
 * closure at run time, so a fixture held on the test case reaches the body
 * untyped and every read of it analyses as a possible null dereference.
 */
function aPushSubscriber(): User
{
    return User::factory()->create();
}

it('allows an authenticated user to create or update a push subscription', function (): void {
    $user = aPushSubscriber();

    $payload = [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
        'keys' => [
            'auth' => 'test-auth-key',
            'p256dh' => 'test-p256dh-key',
        ],
    ];

    actingAs($user)
        ->postJson(route('push-subscriptions.update'), $payload)
        ->assertOk()
        ->assertJson(['message' => 'Abonnement enregistré avec succès.']);

    $this->assertDatabaseHas('push_subscriptions', [
        'subscribable_type' => User::class,
        'subscribable_id' => $user->id,
        'endpoint' => $payload['endpoint'],
    ]);
});

it('requires authentication to update a push subscription', function (): void {
    aPushSubscriber();

    postJson(route('push-subscriptions.update'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
        'keys' => [
            'auth' => 'test-auth-key',
            'p256dh' => 'test-p256dh-key',
        ],
    ])->assertUnauthorized();
});

it('validates required fields for updating a push subscription', function (array $payload, array $errors): void {
    actingAs(aPushSubscriber())
        ->postJson(route('push-subscriptions.update'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);
})->with([
    'missing all' => [[], ['endpoint', 'keys.auth', 'keys.p256dh']],
    'missing endpoint' => [
        [
            'keys' => [
                'auth' => 'test-auth-key',
                'p256dh' => 'test-p256dh-key',
            ],
        ],
        ['endpoint'],
    ],
    'invalid endpoint url' => [
        [
            'endpoint' => 'not-a-url',
            'keys' => [
                'auth' => 'test-auth-key',
                'p256dh' => 'test-p256dh-key',
            ],
        ],
        ['endpoint'],
    ],
    'missing auth key' => [
        [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
            'keys' => [
                'p256dh' => 'test-p256dh-key',
            ],
        ],
        ['keys.auth'],
    ],
    'missing p256dh key' => [
        [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
            'keys' => [
                'auth' => 'test-auth-key',
            ],
        ],
        ['keys.p256dh'],
    ],
]);

it('allows an authenticated user to delete a push subscription', function (): void {
    $user = aPushSubscriber();

    $endpoint = 'https://fcm.googleapis.com/fcm/send/test-endpoint-to-delete';

    // First create a subscription
    $user->updatePushSubscription(
        $endpoint,
        'test-p256dh-key',
        'test-auth-key'
    );

    $this->assertDatabaseHas('push_subscriptions', [
        'subscribable_id' => $user->id,
        'endpoint' => $endpoint,
    ]);

    actingAs($user)
        ->postJson(route('push-subscriptions.destroy'), ['endpoint' => $endpoint])
        ->assertOk()
        ->assertJson(['message' => 'Abonnement supprimé avec succès.']);

    $this->assertDatabaseMissing('push_subscriptions', [
        'subscribable_id' => $user->id,
        'endpoint' => $endpoint,
    ]);
});

it('requires authentication to delete a push subscription', function (): void {
    aPushSubscriber();

    postJson(route('push-subscriptions.destroy'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
    ])->assertUnauthorized();
});

it('validates required fields for deleting a push subscription', function (array $payload, array $errors): void {
    actingAs(aPushSubscriber())
        ->postJson(route('push-subscriptions.destroy'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);
})->with([
    'missing endpoint' => [
        [],
        ['endpoint'],
    ],
    'invalid endpoint url' => [
        [
            'endpoint' => 'not-a-url',
        ],
        ['endpoint'],
    ],
]);
