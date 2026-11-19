<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\withServerVariables;

/*
 * Les proxys dont l'application croit les en-têtes `X-Forwarded-*` se règlent
 * par `TRUSTED_PROXIES` (config/trustedproxy.php). Sans réglage, la liste
 * est la boucle locale et les ponts Docker, pour que la production garde la
 * détection de HTTPS derrière son proxy inverse, sans croire les réseaux locaux. Réglée, elle ne fait plus croire que les pairs qu'elle
 * nomme.
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

it('ne fait confiance, sans réglage, qu’à la boucle locale et aux ponts Docker', function (?string $valeur): void {
    $precedentes = proxysDeConfiancePoserLaVariable($valeur);

    try {
        /** @var array{proxies: mixed} $configuration */
        $configuration = require config_path('trustedproxy.php');
    } finally {
        proxysDeConfianceRendreLaVariable($precedentes);
    }

    expect($configuration['proxies'])->toBe(['127.0.0.1', '172.16.0.0/12']);
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
it('croit, sans réglage, la passerelle d’un pont Docker', function (): void {
    proxysDeConfianceLireDepuis('172.18.0.1', ['X-Forwarded-For' => '198.51.100.9', 'X-Forwarded-Proto' => 'https'])
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
    proxysDeConfianceLireDepuis('172.18.0.2', ['X-Forwarded-For' => '198.51.100.9, 172.20.0.5'])
        ->assertJson(['adresse' => '198.51.100.9']);

    config(['trustedproxy.proxies' => ['172.18.0.2']]);

    proxysDeConfianceLireDepuis('172.18.0.2', ['X-Forwarded-For' => '198.51.100.9, 192.168.1.20'])
        ->assertJson(['adresse' => '192.168.1.20']);
});

it('lit un client à adresse publique sous sa vraie adresse, réglage ou non', function (): void {
    proxysDeConfianceLireDepuis('203.0.113.50', ['X-Forwarded-For' => '198.51.100.9'])
        ->assertJson(['adresse' => '203.0.113.50', 'https' => false]);

    config(['trustedproxy.proxies' => ['192.0.2.10']]);

    proxysDeConfianceLireDepuis('203.0.113.50', ['X-Forwarded-For' => '198.51.100.9'])
        ->assertJson(['adresse' => '203.0.113.50']);
});

/*
 * #2309 : le limiteur de connexion ne se contourne pas en changeant
 * X-Forwarded-For quand le pair n'est pas un proxy de confiance. Avec la
 * liste par défaut, un pair privé qui atteint le port serait cru : c'est la
 * topologie (port publié sur la boucle locale seule, docker-compose.prod.yml)
 * qui lui ferme le chemin, pas ce test.
 */
it('ne laisse pas un pair qui n’est pas le proxy changer d’adresse devant le limiteur de connexion', function (string $pair): void {
    config(['trustedproxy.proxies' => ['192.0.2.10']]);

    $statuts = [];

    foreach (range(1, 7) as $essai) {
        $statuts[] = withServerVariables(['REMOTE_ADDR' => $pair])
            ->withHeaders(['X-Forwarded-For' => "198.51.100.{$essai}"])
            ->post('/login', ['email' => 'personne@example.org', 'password' => 'mauvais'])
            ->getStatusCode();
    }

    expect($statuts[6])->toBe(429);
})->with([
    'pair du réseau local' => ['192.168.1.50'],
    'pair public' => ['203.0.113.50'],
]);

/*
 * Audit des proxys de confiance : derrière la passerelle du pont, un appareil
 * du réseau local qui forge `X-Forwarded-For` ne devient plus l'adresse
 * cliente, et la liste blanche du panneau ne s'en laisse pas ouvrir.
 */
it('lit l’adresse du client du réseau local, pas l’entrée qu’il a forgée à sa gauche', function (string $client): void {
    proxysDeConfianceLireDepuis('172.18.0.1', ['X-Forwarded-For' => "203.0.113.99, {$client}", 'X-Forwarded-Proto' => 'https'])
        ->assertJson(['adresse' => $client, 'https' => true]);
})->with([
    'réseau en 192.168' => ['192.168.1.20'],
    'réseau en 10' => ['10.0.0.20'],
]);

it('lit toujours l’adresse publique que le proxy ajoute derrière la passerelle', function (): void {
    proxysDeConfianceLireDepuis('172.18.0.1', ['X-Forwarded-For' => '203.0.113.99, 198.51.100.9', 'X-Forwarded-Proto' => 'https'])
        ->assertJson(['adresse' => '198.51.100.9', 'https' => true]);
});

it('garde la liste blanche fermée à l’entrée forgée et ouverte à l’adresse légitime', function (): void {
    config(['app.admin_allowed_ips' => ['203.0.113.99', '198.51.100.9']]);

    $forgee = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '172.18.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.99, 192.168.1.20']);
    $legitime = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '172.18.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9']);
    Request::setTrustedProxies(array_values(array_filter((array) config('trustedproxy.proxies'), is_string(...))), Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

    try {
        expect(\App\Http\Middleware\IpWhitelist::admet($forgee))->toBeFalse()
            ->and(\App\Http\Middleware\IpWhitelist::admet($legitime))->toBeTrue();
    } finally {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }
});
