<?php

declare(strict_types=1);

use App\Http\Middleware\PulseNonceMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Pulse écrit `<script>` et `<style>` nus ; sans nonce, la CSP les bloque et
 * le tableau de bord est vide. Ce middleware les signait en réécrivant la
 * réponse. Aucun test ne le nommait (audit du 2026-09-02).
 *
 * Il n'est plus branché nulle part : il réécrivait aussi le texte `<script>`
 * que livewire.js contient, et la page de Pulse restait sans Livewire. Les
 * balises de Pulse sont désormais signées à la compilation de ses gabarits,
 * ce que tiennent `SigneLesScriptsEnLigneDesPaquetsTest` et
 * `CspDesOutilsDAdministrationTest`. Ce test ne garde que la classe ; elle et
 * lui attendent l'accord du propriétaire du dépôt pour être supprimés.
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
