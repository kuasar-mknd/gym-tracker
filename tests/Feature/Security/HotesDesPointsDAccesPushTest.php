<?php

declare(strict_types=1);

use App\Models\User;
use App\Rules\PublicPushEndpoint;
use App\Services\ResolveurDns;
use Illuminate\Notifications\Notification;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/*
 * Un point d'accès push n'est admis que chez les services des navigateurs, à
 * l'enregistrement comme à l'envoi : les abonnements déjà stockés chez un autre
 * hôte ne reçoivent plus rien.
 */

dataset('hotes push admis', [
    'Chrome' => 'https://fcm.googleapis.com/fcm/send/jeton',
    'Firefox' => 'https://updates.push.services.mozilla.com/wpush/v2/jeton',
    'Safari' => 'https://web.push.apple.com/jeton',
    'Safari sans sous-domaine' => 'https://push.apple.com/jeton',
    'Edge' => 'https://wns2-par02p.notify.windows.com/w/?token=jeton',
    'majuscules' => 'https://FCM.GoogleAPIs.com/fcm/send/jeton',
    'point final' => 'https://fcm.googleapis.com./fcm/send/jeton',
]);

dataset('hotes push refuses', [
    'autre domaine' => 'https://push.example.org/jeton',
    'suffixe sans point' => 'https://evilfcm.googleapis.com/jeton',
    'service dans le sous-domaine' => 'https://fcm.googleapis.com.evil.example/jeton',
    'service dans le chemin' => 'https://evil.example/fcm.googleapis.com/jeton',
    'service dans la requête' => 'https://evil.example/?h=push.apple.com',
    'service comme identifiant' => 'https://fcm.googleapis.com@evil.example/jeton',
    'parent du service' => 'https://googleapis.com/jeton',
    'adresse littérale' => 'https://203.0.113.7/jeton',
]);

it('admet les services push des navigateurs à l’enregistrement', function (string $endpoint): void {
    $this->actingAs(User::factory()->create())
        ->postJson(route('push-subscriptions.update'), [
            'endpoint' => $endpoint,
            'keys' => ['auth' => 'a-token', 'p256dh' => 'a-key'],
        ])->assertOk();

    expect(PublicPushEndpoint::accepteLHoteDe($endpoint))->toBeTrue();
})->with('hotes push admis');

it('refuse tout autre hôte à l’enregistrement', function (string $endpoint): void {
    $this->actingAs(User::factory()->create())
        ->postJson(route('push-subscriptions.update'), [
            'endpoint' => $endpoint,
            'keys' => ['auth' => 'a-token', 'p256dh' => 'a-key'],
        ])->assertJsonValidationErrorFor('endpoint');

    $this->assertDatabaseCount('push_subscriptions', 0);

    expect(PublicPushEndpoint::accepteLHoteDe($endpoint))->toBeFalse();
})->with('hotes push refuses');

it('garde le refus d’un hôte admis qui se résout vers le réseau du serveur', function (): void {
    app()->bind(ResolveurDns::class, fn (): ResolveurDns => new class() extends ResolveurDns
    {
        #[\Override]
        public function adressesDe(string $host): array
        {
            return ['10.0.0.5'];
        }
    });

    $this->actingAs(User::factory()->create())
        ->postJson(route('push-subscriptions.update'), [
            'endpoint' => 'https://interne.push.apple.com/jeton',
            'keys' => ['auth' => 'a-token', 'p256dh' => 'a-key'],
        ])->assertJsonValidationErrorFor('endpoint');

    $this->assertDatabaseCount('push_subscriptions', 0);
});

it('garde le refus d’un hôte admis que le résolveur ne sait pas résoudre', function (): void {
    app()->bind(ResolveurDns::class, fn (): ResolveurDns => new class() extends ResolveurDns
    {
        #[\Override]
        public function adressesDe(string $host): array
        {
            return [];
        }
    });

    $this->actingAs(User::factory()->create())
        ->postJson(route('push-subscriptions.update'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/jeton',
            'keys' => ['auth' => 'a-token', 'p256dh' => 'a-key'],
        ])->assertJsonValidationErrorFor('endpoint');

    $this->assertDatabaseCount('push_subscriptions', 0);
});

it('n’envoie qu’aux abonnements dont l’hôte est admis', function (): void {
    $compte = User::factory()->create();

    foreach (['https://fcm.googleapis.com/fcm/send/admis', 'https://push.example.org/ancien', 'https://127.0.0.1/ancien'] as $endpoint) {
        $compte->updatePushSubscription($endpoint, 'p256dh-key', 'auth-token');
    }

    $envoyes = [];
    /** @var WebPush&Mockery\MockInterface $transport */
    $transport = Mockery::mock(WebPush::class);
    $transport->shouldReceive('queueNotification')
        ->andReturnUsing(function (Subscription $abonnement) use (&$envoyes): void {
            $envoyes[] = $abonnement->getEndpoint();
        });
    $transport->shouldReceive('flush')->andReturnUsing(function (): Generator {
        yield from [];
    });
    app()->when(WebPushChannel::class)->needs(WebPush::class)->give(fn (): WebPush => $transport);

    $notification = new class() extends Notification
    {
        public function toWebPush(): WebPushMessage
        {
            return new WebPushMessage();
        }
    };

    app(WebPushChannel::class)->send($compte, $notification);

    expect($envoyes)->toBe(['https://fcm.googleapis.com/fcm/send/admis'])
        ->and($compte->pushSubscriptions()->count())->toBe(3);
});
