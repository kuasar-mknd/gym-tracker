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
