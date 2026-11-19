<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\Subscription;
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

it('rend la main à un pair muet avant la fin du délai de la tâche', function (): void {
    [, $client] = webPushDuCanal();

    $serveur = stream_socket_server('tcp://127.0.0.1:0', $codeErreur, $messageErreur);

    if ($serveur === false) {
        throw new RuntimeException('Le pair muet n’a pas pu écouter : '.$messageErreur);
    }

    $adresse = stream_socket_get_name($serveur, false);

    $debut = microtime(true);

    try {
        $client->post('http://'.$adresse.'/push', ['body' => 'x']);
        $leve = false;
    } catch (GuzzleHttp\Exception\NetworkTimeoutException) {
        $leve = true;
    } finally {
        fclose($serveur);
    }

    expect($leve)->toBeTrue()
        ->and(microtime(true) - $debut)->toBeLessThan(15.0);
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

/*
 * Un pair muet retient l'envoi jusqu'au délai du client (10 s), et le canal
 * joint les appareils du compte l'un après l'autre : dix appareils muets
 * approchent les 60 s au bout desquelles Horizon tue la tâche, qui tient alors
 * un processus de la file `default`. L'envoi s'arrête au budget de temps.
 */
it('arrête l’envoi aux appareils restants quand le budget de temps est dépassé', function (): void {
    config(['webpush.budget_secondes' => 0.5]);

    $envoyes = 0;
    $lent = new Client([
        'handler' => static function () use (&$envoyes): FulfilledPromise {
            $envoyes++;
            usleep(300_000);

            return new FulfilledPromise(new Response(201));
        },
    ]);

    [$webPush] = webPushDuCanal();
    new ReflectionProperty($webPush, 'client')->setValue($webPush, $lent);

    $cles = VAPID::createVapidKeys();
    $clePublique = $cles['publicKey'];
    assert(is_string($clePublique));

    for ($i = 1; $i <= 6; $i++) {
        $webPush->queueNotification(new Subscription(
            "https://fcm.googleapis.com/fcm/send/appareil-{$i}",
            $clePublique,
            'jeton-auth-16-oc',
        ), '{}');
    }

    $debut = microtime(true);
    $rapports = iterator_to_array($webPush->flush(), false);

    expect($envoyes)->toBeLessThan(6)
        ->and($envoyes)->toBeGreaterThanOrEqual(1)
        ->and(count($rapports))->toBe($envoyes)
        ->and(microtime(true) - $debut)->toBeLessThan(1.5);
});

it('envoie tous les appareils quand le budget tient', function (): void {
    config(['webpush.budget_secondes' => 25.0]);

    $envoyes = 0;
    $rapide = new Client([
        'handler' => static function () use (&$envoyes): FulfilledPromise {
            $envoyes++;

            return new FulfilledPromise(new Response(201));
        },
    ]);

    [$webPush] = webPushDuCanal();
    new ReflectionProperty($webPush, 'client')->setValue($webPush, $rapide);

    $clePublique = VAPID::createVapidKeys()['publicKey'];
    assert(is_string($clePublique));

    for ($i = 1; $i <= 6; $i++) {
        $webPush->queueNotification(new Subscription("https://fcm.googleapis.com/fcm/send/appareil-{$i}", $clePublique, 'jeton-auth-16-oc'), '{}');
    }

    expect(count(iterator_to_array($webPush->flush(), false)))->toBe(6)->and($envoyes)->toBe(6);
});
