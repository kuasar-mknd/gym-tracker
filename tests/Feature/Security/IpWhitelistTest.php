<?php

declare(strict_types=1);

use App\Http\Middleware\IpWhitelist;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * La liste blanche vide laissait passer tout le monde : en production, c'est
 * desormais une porte fermee, et une adresse hors liste repond 404 pour ne
 * pas signaler l'existence du panneau.
 */
function requeteAdminDepuis(string $ip): Request
{
    return Request::create('/backoffice', 'GET', server: ['REMOTE_ADDR' => $ip]);
}

function reponseDeLaListeBlanche(string $ip): int
{
    return new IpWhitelist()->handle(requeteAdminDepuis($ip), fn (): \Illuminate\Http\Response => response('ok'))->getStatusCode();
}

it('ferme le panneau en production quand aucune adresse n est configuree', function (): void {
    config(['app.admin_allowed_ips' => []]);
    $this->app->detectEnvironment(fn (): string => 'production');

    expect(fn (): int => reponseDeLaListeBlanche('203.0.113.5'))->toThrow(HttpException::class);

    try {
        reponseDeLaListeBlanche('203.0.113.5');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(404);
    }
});

it('laisse passer une adresse de la liste en production', function (): void {
    config(['app.admin_allowed_ips' => ['203.0.113.5']]);
    $this->app->detectEnvironment(fn (): string => 'production');

    expect(reponseDeLaListeBlanche('203.0.113.5'))->toBe(200);
});

it('repond 404 en production a une adresse hors liste', function (): void {
    config(['app.admin_allowed_ips' => ['203.0.113.5']]);
    $this->app->detectEnvironment(fn (): string => 'production');

    try {
        reponseDeLaListeBlanche('198.51.100.7');
        $this->fail('La requete aurait du etre refusee.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(404);
    }
});

it('laisse la liste vide ouverte hors production, pour le poste de developpement', function (): void {
    config(['app.admin_allowed_ips' => []]);

    expect(app()->isProduction())->toBeFalse();
    expect(reponseDeLaListeBlanche('203.0.113.5'))->toBe(200);
});

it('repond 403 hors production a une adresse hors liste, en nommant l adresse', function (): void {
    config(['app.admin_allowed_ips' => ['203.0.113.5']]);

    try {
        reponseDeLaListeBlanche('198.51.100.7');
        $this->fail('La requete aurait du etre refusee.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
        expect($e->getMessage())->toContain('198.51.100.7');
    }
});

/**
 * Un réseau local ou un tailnet ne se liste pas appareil par appareil : la
 * liste accepte les plages CIDR, IPv4 et IPv6, à côté des adresses exactes.
 * Avec l'ancienne comparaison stricte, `192.168.1.0/24` ne correspondait à
 * rien et fermait le panneau à tout le réseau.
 */
it('laisse passer une adresse comprise dans une plage CIDR', function (): void {
    config(['app.admin_allowed_ips' => ['192.168.1.0/24']]);
    $this->app->detectEnvironment(fn (): string => 'production');

    expect(reponseDeLaListeBlanche('192.168.1.42'))->toBe(200);
});

it('refuse une adresse hors de la plage CIDR', function (): void {
    config(['app.admin_allowed_ips' => ['192.168.1.0/24']]);
    $this->app->detectEnvironment(fn (): string => 'production');

    try {
        reponseDeLaListeBlanche('192.168.2.42');
        $this->fail('La requete aurait du etre refusee.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(404);
    }
});

it('accepte un mélange d adresses exactes et de plages', function (): void {
    config(['app.admin_allowed_ips' => ['192.168.1.0/24', '100.76.239.32']]);
    $this->app->detectEnvironment(fn (): string => 'production');

    expect(reponseDeLaListeBlanche('100.76.239.32'))->toBe(200)
        ->and(reponseDeLaListeBlanche('192.168.1.7'))->toBe(200);

    try {
        reponseDeLaListeBlanche('100.76.239.33');
        $this->fail('La requete aurait du etre refusee.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(404);
    }
});

it('comprend aussi une plage IPv6', function (): void {
    config(['app.admin_allowed_ips' => ['fd00::/8']]);
    $this->app->detectEnvironment(fn (): string => 'production');

    expect(reponseDeLaListeBlanche('fd00::1'))->toBe(200);

    try {
        reponseDeLaListeBlanche('2001:db8::1');
        $this->fail('La requete aurait du etre refusee.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(404);
    }
});
