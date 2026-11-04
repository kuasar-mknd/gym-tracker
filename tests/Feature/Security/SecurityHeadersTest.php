<?php

declare(strict_types=1);

use function Pest\Laravel\get;

/**
 * Le middleware est prépendu à toute la pile HTTP : chaque réponse, page ou
 * JSON, porte ces en-têtes. Il n'avait aucun test qui le nomme (audit du
 * 2026-09-02) ; celui-ci fige les valeurs, en-tête par en-tête.
 */
it('pose les en-têtes de sécurité sur chaque réponse', function (): void {
    $reponse = $this->get('/login');

    $reponse->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-XSS-Protection', '0')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload')
        ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), xr-spatial-tracking=()')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
});

it('les pose aussi sur une réponse d erreur', function (): void {
    $this->get('/une-page-qui-n-existe-pas')
        ->assertNotFound()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

/**
 * Le minuteur d'intervalles garde l'écran allumé tant qu'il tourne
 * (`navigator.wakeLock`, #2113). La capacité `screen-wake-lock` est permise à
 * la page elle-même par défaut (`self`) : une politique qui la refuserait
 * laisserait de nouveau l'écran se verrouiller, la page suspendue, sans aucune
 * erreur visible, la demande du verrou étant refusée en silence.
 */
it('laisse au minuteur d’intervalles le verrou d’écran', function (): void {
    $politique = (string) get('/login')->headers->get('Permissions-Policy');

    expect($politique)->not->toBe('')
        ->and($politique)->not->toContain('screen-wake-lock');
});
