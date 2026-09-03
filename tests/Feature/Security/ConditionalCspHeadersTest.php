<?php

declare(strict_types=1);

use App\Http\Middleware\ConditionalCspHeaders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Pulse rend ses propres balises inline, incompatibles avec une CSP à
 * nonce : sur son chemin, le middleware s'efface. Partout ailleurs il pose
 * la politique. Aucun test ne le nommait (audit du 2026-09-02).
 */
function reponseDuMiddlewareCsp(string $chemin, ?string $preset = null): Response
{
    $reponse = new ConditionalCspHeaders()->handle(
        Request::create('/'.ltrim($chemin, '/')),
        fn (): Response => new Response('<html></html>'),
        $preset,
    );

    expect($reponse)->toBeInstanceOf(Response::class);

    /** @var Response $reponse */
    return $reponse;
}

it('s efface sur le chemin de Pulse', function (): void {
    config(['pulse.path' => 'backoffice/pulse']);

    expect(reponseDuMiddlewareCsp('backoffice/pulse')->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and(reponseDuMiddlewareCsp('backoffice/pulse/usage')->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('pose la politique partout ailleurs', function (): void {
    $reponse = reponseDuMiddlewareCsp('workouts');

    expect($reponse->headers->has('Content-Security-Policy'))->toBeTrue()
        ->and((string) $reponse->headers->get('Content-Security-Policy'))->toContain("default-src 'self'");
});

it('pose la politique sur le chemin de Pulse quand un preset est demandé explicitement', function (): void {
    config(['pulse.path' => 'backoffice/pulse']);

    $reponse = reponseDuMiddlewareCsp('backoffice/pulse', \App\Support\Csp\Policies\CustomPolicy::class);

    expect($reponse->headers->has('Content-Security-Policy'))->toBeTrue();
});

it('est bien celui que la pile web applique', function (): void {
    $this->get('/login')->assertOk()->assertHeader('Content-Security-Policy');
});
