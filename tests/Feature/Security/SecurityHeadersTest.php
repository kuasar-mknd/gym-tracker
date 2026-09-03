<?php

declare(strict_types=1);

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
