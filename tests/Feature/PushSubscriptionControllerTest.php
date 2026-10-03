<?php

declare(strict_types=1);

use App\Models\User;

describe('PushSubscriptionController', function (): void {
    describe('update', function (): void {
        it('allows a user to save a valid push subscription', function (): void {
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

        it('returns validation errors for missing endpoint', function (): void {
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

        it('returns validation errors for missing keys', function (): void {
            $user = User::factory()->create();

            $payload = [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint',
            ];

            $response = $this->actingAs($user)
                ->postJson(route('push-subscriptions.update'), $payload);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['keys.auth', 'keys.p256dh']);
        });

        it('redirects or forbids a guest user', function (): void {
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

    describe('destroy', function (): void {
        it('allows a user to delete an existing push subscription', function (): void {
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

        it('returns validation errors for missing endpoint on deletion', function (): void {
            $user = User::factory()->create();

            $payload = [];

            $response = $this->actingAs($user)
                ->postJson(route('push-subscriptions.destroy'), $payload);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['endpoint']);
        });

        it('returns validation error for invalid url format', function (): void {
            $user = User::factory()->create();

            $payload = [
                'endpoint' => 'not-a-valid-url',
            ];

            $response = $this->actingAs($user)
                ->postJson(route('push-subscriptions.destroy'), $payload);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['endpoint']);
        });

        it('redirects or forbids a guest user from deleting', function (): void {
            $payload = [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint',
            ];

            $response = $this->postJson(route('push-subscriptions.destroy'), $payload);

            $response->assertUnauthorized();
        });
    });

    /*
     * Le service worker renvoie lui-même un abonnement que le navigateur a
     * remplacé (#1847). Il n'a pas Ziggy : ses deux adresses sont écrites en
     * dur dans resources/js/sw/renouvellementDAbonnement.js, et rien d'autre
     * ne les relie aux routes. Une route renommée laisserait le worker poster
     * dans le vide, sans une erreur visible nulle part.
     */
    describe('le renouvellement depuis le service worker', function (): void {
        it('poste aux adresses que le serveur expose', function (string $constante, string $nomDeRoute): void {
            $source = (string) file_get_contents(resource_path('js/sw/renouvellementDAbonnement.js'));

            expect(preg_match("/export const {$constante} = '([^']+)'/", $source, $correspondance))
                ->toBe(1, "`{$constante}` est introuvable dans le module du worker : si sa forme a changé, ce contrôle doit suivre.");

            expect($correspondance[1])->toBe(route($nomDeRoute, absolute: false));
        })->with([
            'enregistrement' => ['URL_D_ENREGISTREMENT', 'push-subscriptions.update'],
            'oubli' => ['URL_D_OUBLI', 'push-subscriptions.destroy'],
        ]);

        it('accepte l’abonnement tel que toJSON() le rend', function (): void {
            $utilisateur = User::factory()->create();

            // `expirationTime` en plus des clefs : le worker envoie l'objet du
            // navigateur sans le retailler.
            $this->actingAs($utilisateur)
                ->postJson(route('push-subscriptions.update'), [
                    'endpoint' => 'https://fcm.googleapis.com/fcm/send/renouvele',
                    'expirationTime' => null,
                    'keys' => ['p256dh' => 'cle-p256dh', 'auth' => 'jeton-auth'],
                ])
                ->assertOk();

            $this->assertDatabaseHas('push_subscriptions', [
                'subscribable_id' => $utilisateur->id,
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/renouvele',
            ]);
        });

        it('répond 401 en JSON à un worker dont la session a expiré', function (): void {
            // Une redirection vers la page de connexion serait suivie par
            // `fetch` et finirait en 200 : le worker croirait avoir réussi.
            $this->withHeader('Sec-Fetch-Site', 'same-origin')
                ->postJson(route('push-subscriptions.destroy'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/ancien'])
                ->assertUnauthorized()
                ->assertHeader('Content-Type', 'application/json');
        });
    });
});
