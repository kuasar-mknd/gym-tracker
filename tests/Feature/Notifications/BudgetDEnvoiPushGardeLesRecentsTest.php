<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\WebPushBorne;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/*
 * Quand le budget de temps coupe l'envoi, les appareils gardés sont les plus
 * récents : les plus anciens sont ceux d'un navigateur réinstallé ou d'un
 * appareil rendu, et un pair muet, jamais retiré, retient chaque envoi.
 */
it('joint les appareils du plus récent au plus ancien, et coupe les plus anciens', function (): void {
    $cles = VAPID::createVapidKeys();
    $clePublique = $cles['publicKey'];
    assert(is_string($clePublique));

    $joints = [];
    $lent = new Client([
        'handler' => static function ($requete) use (&$joints): FulfilledPromise {
            $joints[] = basename((string) $requete->getUri());
            usleep(300_000);

            return new FulfilledPromise(new Response(201));
        },
    ]);

    $webPush = new WebPushBorne(['VAPID' => ['subject' => 'mailto:exploitant@example.org', 'publicKey' => $cles['publicKey'], 'privateKey' => $cles['privateKey']]], [], $lent, new HttpFactory(), new HttpFactory())
        ->setBudgetEnSecondes(0.5);

    $compte = User::factory()->create();

    foreach (['ancien-1', 'ancien-2', 'recent-3', 'recent-4', 'recent-5', 'recent-6'] as $nom) {
        $compte->updatePushSubscription("https://fcm.googleapis.com/fcm/send/{$nom}", $clePublique, 'jeton-auth-16-oc');
    }

    $notification = new class() extends Illuminate\Notifications\Notification
    {
        public function toWebPush(): WebPushMessage
        {
            return new WebPushMessage()->title('x');
        }
    };

    app()->when(WebPushChannel::class)->needs(Minishlink\WebPush\WebPush::class)->give(fn (): \App\Support\WebPushBorne => $webPush);
    app(WebPushChannel::class)->send($compte->fresh(), $notification);

    expect($joints)->not->toBe([])
        ->and(count($joints))->toBeLessThan(6)
        ->and($joints[0])->toBe('recent-6')
        ->and($joints)->not->toContain('ancien-1');
});
