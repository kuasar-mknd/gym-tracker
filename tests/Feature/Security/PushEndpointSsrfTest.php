<?php

declare(strict_types=1);

use App\Models\User;

/**
 * The push endpoint is client-supplied, stored verbatim, and POSTed to by the
 * WebPush channel on every notification. Before App\Rules\PublicPushEndpoint the
 * only rule was `url`, which accepts any scheme and any host — so an
 * authenticated user could aim the server at its own network.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('refuses an endpoint aimed at the server network', function (string $endpoint): void {
    $this->postJson(route('push-subscriptions.update'), [
        'endpoint' => $endpoint,
        'keys' => ['auth' => 'a-token', 'p256dh' => 'a-key'],
    ])->assertJsonValidationErrorFor('endpoint');

    $this->assertDatabaseCount('push_subscriptions', 0);
})->with([
    'loopback' => 'https://127.0.0.1/push',
    'loopback by name' => 'https://localhost/push',
    'private range' => 'https://10.0.0.5/push',
    'private range 192.168' => 'https://192.168.1.10/push',
    'link-local metadata' => 'https://169.254.169.254/latest/meta-data/',
    'IPv6 loopback' => 'https://[::1]/push',
]);

it('refuses a plaintext endpoint even on a public host', function (): void {
    $this->postJson(route('push-subscriptions.update'), [
        'endpoint' => 'http://fcm.googleapis.com/fcm/send/abc',
        'keys' => ['auth' => 'a-token', 'p256dh' => 'a-key'],
    ])->assertJsonValidationErrorFor('endpoint');

    $this->assertDatabaseCount('push_subscriptions', 0);
});

it('still accepts a real push service endpoint', function (): void {
    /*
     * Plus de pre-remplissage ici : `TestCase` remplace le resolveur pour toute
     * la suite, donc aucun test ne resout un nom sur Internet. Ce test verifie
     * qu'un hote public n'est pas ecarte, pas que Google a telle adresse.
     */
    $this->postJson(route('push-subscriptions.update'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/c2VjcmV0LXRva2Vu',
        'keys' => ['auth' => 'a-token', 'p256dh' => 'a-key'],
    ])->assertOk();

    $this->assertDatabaseHas('push_subscriptions', [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/c2VjcmV0LXRva2Vu',
    ]);
});

/*
 * Le comportement qui a change : un hote qu'on ne sait pas resoudre etait
 * ACCEPTE, au motif qu'il ne peut pas etre joint non plus.
 *
 * Le raisonnement se retournait contre lui-meme : la protection s'effaçait
 * exactement quand le reseau allait mal, et un resolveur lent ou empoisonne
 * suffisait a la faire taire. On accepte desormais ce qu'on sait resoudre, et
 * dont tout ce qu'on resout est public (#1519).
 */
it('refuses a host it cannot resolve', function (): void {
    // `.invalid` est reserve par la RFC 2606 : aucun resolveur ne le sert.
    $this->postJson(route('push-subscriptions.update'), [
        'endpoint' => 'https://rien-du-tout.invalid/fcm/send/jeton',
        'keys' => ['auth' => 'a-token', 'p256dh' => 'a-key'],
    ])->assertJsonValidationErrorFor('endpoint');

    $this->assertDatabaseCount('push_subscriptions', 0);
});
