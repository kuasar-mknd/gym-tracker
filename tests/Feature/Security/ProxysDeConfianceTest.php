<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\withServerVariables;

/*
 * Les proxys dont l'application croit les en-têtes `X-Forwarded-*` se règlent
 * par `TRUSTED_PROXIES` (config/trustedproxy.php). Sans réglage, la liste
 * reste exactement celle d'avant la variable : la boucle locale et les trois
 * plages privées, pour que la production garde la détection de HTTPS derrière
 * son proxy inverse. Réglée sur l'adresse du proxy inverse, elle empêche tout
 * autre pair, privé compris, de choisir l'adresse cliente que lit l'application.
 */

/**
 * Pose `TRUSTED_PROXIES` comme la pile la transmettrait, ou la retire quand
 * `$valeur` est null, et rend ce qu'il y avait avant.
 *
 * @return array{serveur: mixed, env: mixed, putenv: string|false}
 */
function proxysDeConfiancePoserLaVariable(?string $valeur): array
{
    $precedentes = [
        'serveur' => $_SERVER['TRUSTED_PROXIES'] ?? null,
        'env' => $_ENV['TRUSTED_PROXIES'] ?? null,
        'putenv' => getenv('TRUSTED_PROXIES'),
    ];

    if ($valeur === null) {
        unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
        putenv('TRUSTED_PROXIES');
    } else {
        $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = $valeur;
        putenv('TRUSTED_PROXIES='.$valeur);
    }

    return $precedentes;
}

/**
 * Rend à l'environnement ce que `proxysDeConfiancePoserLaVariable()` a changé.
 *
 * @param  array{serveur: mixed, env: mixed, putenv: string|false}  $precedentes
 */
function proxysDeConfianceRendreLaVariable(array $precedentes): void
{
    unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);

    if ($precedentes['serveur'] !== null) {
        $_SERVER['TRUSTED_PROXIES'] = $precedentes['serveur'];
    }

    if ($precedentes['env'] !== null) {
        $_ENV['TRUSTED_PROXIES'] = $precedentes['env'];
    }

    putenv($precedentes['putenv'] === false ? 'TRUSTED_PROXIES' : 'TRUSTED_PROXIES='.$precedentes['putenv']);
}

/**
 * Ce que l'application lit d'une requête venue de `$pair`, qui transmet
 * `$entetes` : l'adresse cliente, HTTPS, et la racine des URL qu'elle bâtit.
 *
 * @param  array<string, string>  $entetes
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function proxysDeConfianceLireDepuis(string $pair, array $entetes = []): TestResponse
{
    Route::get('/_proxys/client', static fn (Request $requete): array => [
        'adresse' => $requete->ip(),
        'https' => $requete->isSecure(),
        'racine' => url('/'),
    ]);

    return withServerVariables(['REMOTE_ADDR' => $pair])->withHeaders($entetes)->get('/_proxys/client')->assertOk();
}

it('garde, sans réglage, exactement la liste d’avant la variable', function (?string $valeur): void {
    $precedentes = proxysDeConfiancePoserLaVariable($valeur);

    try {
        /** @var array{proxies: mixed} $configuration */
        $configuration = require config_path('trustedproxy.php');
    } finally {
        proxysDeConfianceRendreLaVariable($precedentes);
    }

    expect($configuration['proxies'])->toBe(['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);
})->with([
    'absente' => [null],
    'vide' => [''],
    'faite d’espaces et de virgules' => [' , '],
]);

it('lit dans TRUSTED_PROXIES des adresses et des sous-réseaux séparés par des virgules', function (): void {
    $precedentes = proxysDeConfiancePoserLaVariable(' 192.0.2.10 , 198.51.100.0/24,2001:db8::1 ');

    try {
        /** @var array{proxies: mixed} $configuration */
        $configuration = require config_path('trustedproxy.php');
    } finally {
        proxysDeConfianceRendreLaVariable($precedentes);
    }

    expect($configuration['proxies'])->toBe(['192.0.2.10', '198.51.100.0/24', '2001:db8::1']);
});

/*
 * Le comportement par défaut ne change pas : un pair des plages privées reste
 * cru, d'où la recommandation de régler la variable en production.
 */
it('croit, sans réglage, un pair des plages privées comme avant', function (): void {
    proxysDeConfianceLireDepuis('10.1.2.3', ['X-Forwarded-For' => '198.51.100.9', 'X-Forwarded-Proto' => 'https'])
        ->assertJson(['adresse' => '198.51.100.9', 'https' => true]);
});

it('ne croit plus un pair privé autre que le proxy déclaré', function (): void {
    config(['trustedproxy.proxies' => ['192.0.2.10']]);

    proxysDeConfianceLireDepuis('10.1.2.3', ['X-Forwarded-For' => '198.51.100.9', 'X-Forwarded-Proto' => 'https'])
        ->assertJson(['adresse' => '10.1.2.3', 'https' => false]);
});

it('lit derrière le proxy déclaré HTTPS et l’adresse que le proxy ajoute', function (): void {
    config(['trustedproxy.proxies' => ['192.0.2.10'], 'app.url' => 'https://gym.example.org']);

    proxysDeConfianceLireDepuis('192.0.2.10', [
        'X-Forwarded-For' => '203.0.113.50, 198.51.100.9',
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'gym.example.org',
        'X-Forwarded-Port' => '443',
    ])->assertJson([
        'adresse' => '198.51.100.9',
        'https' => true,
        'racine' => 'https://gym.example.org',
    ]);
});

/*
 * Un client des plages privées, à travers le proxy déclaré, ne passe plus
 * pour un proxy : l'adresse qu'il annonce est ignorée, la sienne est lue.
 */
it('lit l’adresse privée d’un client à travers le proxy déclaré, pas celle qu’il annonce', function (): void {
    proxysDeConfianceLireDepuis('10.0.0.2', ['X-Forwarded-For' => '198.51.100.9, 192.168.1.20'])
        ->assertJson(['adresse' => '198.51.100.9']);

    config(['trustedproxy.proxies' => ['10.0.0.2']]);

    proxysDeConfianceLireDepuis('10.0.0.2', ['X-Forwarded-For' => '198.51.100.9, 192.168.1.20'])
        ->assertJson(['adresse' => '192.168.1.20']);
});

it('lit un client à adresse publique sous sa vraie adresse, réglage ou non', function (): void {
    proxysDeConfianceLireDepuis('203.0.113.50', ['X-Forwarded-For' => '198.51.100.9'])
        ->assertJson(['adresse' => '203.0.113.50', 'https' => false]);

    config(['trustedproxy.proxies' => ['192.0.2.10']]);

    proxysDeConfianceLireDepuis('203.0.113.50', ['X-Forwarded-For' => '198.51.100.9'])
        ->assertJson(['adresse' => '203.0.113.50']);
});
