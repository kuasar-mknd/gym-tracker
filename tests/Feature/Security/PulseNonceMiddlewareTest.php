<?php

declare(strict_types=1);

use App\Http\Middleware\PulseNonceMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Pulse écrit `<script>` et `<style>` nus ; sans nonce, la CSP les bloque et
 * le tableau de bord est vide. Le middleware les signe avec le nonce de la
 * requête, quand il existe. Aucun test ne le nommait (audit du 2026-09-02).
 */
function reponseSigneeParPulse(string $html): string
{
    $reponse = new PulseNonceMiddleware()->handle(
        Request::create('/backoffice/pulse'),
        fn (): Response => new Response($html),
    );

    return (string) $reponse->getContent();
}

it('signe les balises script et style avec le nonce de la requête', function (): void {
    app()->instance('csp-nonce', 'abc123');

    $html = reponseSigneeParPulse('<script>a()</script><style>b{}</style><script src="x.js"></script>');

    expect($html)->toBe('<script nonce="abc123">a()</script><style nonce="abc123">b{}</style><script src="x.js"></script>');
});

it('laisse la réponse intacte quand le nonce n est pas une chaîne', function (): void {
    app()->instance('csp-nonce', 42);

    expect(reponseSigneeParPulse('<script>a()</script>'))->toBe('<script>a()</script>');
});
