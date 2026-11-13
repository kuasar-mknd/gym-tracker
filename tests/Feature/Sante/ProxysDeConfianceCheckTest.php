<?php

declare(strict_types=1);

use App\Support\Sante\ProxysDeConfianceCheck;
use Illuminate\Support\Facades\Config;

/*
 * Le défaut de `TRUSTED_PROXIES` accepte trois plages privées entières : un
 * pair de l'une d'elles peut annoncer n'importe quelle adresse cliente. Le
 * contrôle juge la liste résolue, pas la variable, et seulement en
 * production : le défaut reste, et la page dit qu'il est large.
 */

/**
 * Le contrôle, jugé comme en production, sur la liste donnée.
 *
 * @param  array<mixed>  $proxys
 */
function proxysDeConfianceEnProduction(array $proxys): string
{
    app()->detectEnvironment(fn (): string => 'production');
    Config::set('trustedproxy.proxies', $proxys);

    return (string) ProxysDeConfianceCheck::new()->run()->status->value;
}

it('passe à l’orange en production avec la liste par défaut', function (): void {
    /** @var array{proxies: array<string>} $defaut */
    $defaut = require base_path('config/trustedproxy.php');

    expect(proxysDeConfianceEnProduction($defaut['proxies']))->toBe('warning');
});

it('reste verte en production avec des adresses précises', function (array $proxys): void {
    expect(proxysDeConfianceEnProduction($proxys))->toBe('ok');
})->with([
    'une adresse' => [['172.18.0.5']],
    'un /24' => [['172.18.0.0/24']],
    'un /32' => [['10.0.0.7/32']],
    'une adresse IPv6' => [['fd00::5']],
    'un /64' => [['fd00:1::/64']],
    'le pair direct' => [['REMOTE_ADDR']],
    'plusieurs' => [['127.0.0.1', '172.18.0.5', '192.168.1.0/24']],
]);

it('passe à l’orange dès qu’une entrée dépasse un /24 ou un /64, ou fait confiance à tous', function (array $proxys): void {
    expect(proxysDeConfianceEnProduction($proxys))->toBe('warning');
})->with([
    'un /23' => [['172.18.0.0/23']],
    'un /8' => [['172.18.5.5', '10.0.0.0/8']],
    'un /63' => [['fd00:1::/63']],
    'un /48' => [['fd00::/48']],
    'tout' => [['*']],
    'tout, à deux étoiles' => [['**']],
    'les plages privées par mot-clé' => [['PRIVATE_SUBNETS']],
    'une faute de frappe' => [['172.18.0.5/']],
    'un préfixe qui n’est pas un nombre' => [['172.18.0.0/x']],
    'un nom' => [['proxy.example.org']],
]);

it('ne dit que des nombres dans son message, jamais une adresse configurée', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    Config::set('trustedproxy.proxies', ['172.18.0.5', '10.0.0.0/8']);

    $resultat = ProxysDeConfianceCheck::new()->run();

    expect($resultat->getNotificationMessage())->toContain('1 trop large(s)')
        ->and($resultat->getNotificationMessage())->not->toContain('10.0.0.0')
        ->and($resultat->getNotificationMessage())->not->toContain('172.18.0.5')
        ->and($resultat->getShortSummary())->toBe('2 entrée(s)');
});

it('ne juge rien hors production', function (): void {
    Config::set('trustedproxy.proxies', ['10.0.0.0/8', '*']);

    $resultat = ProxysDeConfianceCheck::new()->run();

    expect(app()->isProduction())->toBeFalse()
        ->and($resultat->status->value)->toBe('ok')
        ->and($resultat->getShortSummary())->toBe('Non jugé hors production');
});
