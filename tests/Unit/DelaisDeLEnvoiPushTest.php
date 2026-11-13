<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;

uses(Tests\TestCase::class);

/**
 * Le WebPush que le canal reçoit du conteneur, et non la configuration : sous
 * Laravel 13, le paquet n'en transmettait pas les délais au client.
 *
 * @return array{0: WebPush, 1: Client}
 */
function webPushDuCanal(): array
{
    $canal = app(WebPushChannel::class);

    /** @var WebPush $webPush */
    $webPush = new ReflectionProperty($canal, 'webPush')->getValue($canal);
    /** @var Client $client */
    $client = new ReflectionProperty($webPush, 'client')->getValue($webPush);

    expect($webPush)->toBeInstanceOf(WebPush::class)
        ->and($client)->toBeInstanceOf(Client::class);

    return [$webPush, $client];
}

it('borne la durée d’un envoi push par des délais que le client porte vraiment', function (): void {
    [, $client] = webPushDuCanal();

    expect($client->getConfig('timeout'))->toBe(10)
        ->and($client->getConfig('connect_timeout'))->toBe(5);
});

it('ne suit aucune redirection d’un point d’accès push', function (): void {
    [, $client] = webPushDuCanal();

    expect($client->getConfig('allow_redirects'))->toBeFalse();
});

it('garde l’authentification VAPID de la configuration', function (): void {
    $cles = VAPID::createVapidKeys();

    config([
        'webpush.vapid.public_key' => $cles['publicKey'],
        'webpush.vapid.private_key' => $cles['privateKey'],
        'webpush.vapid.subject' => 'mailto:exploitant@example.org',
    ]);

    [$webPush] = webPushDuCanal();

    /** @var array{VAPID: array{subject: string, publicKey: string}} $authentification */
    $authentification = new ReflectionProperty($webPush, 'auth')->getValue($webPush);

    expect($authentification['VAPID']['subject'])->toBe('mailto:exploitant@example.org')
        ->and(strlen($authentification['VAPID']['publicKey']))->toBe(65);
});
