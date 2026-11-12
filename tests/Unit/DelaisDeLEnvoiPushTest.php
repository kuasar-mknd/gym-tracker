<?php

declare(strict_types=1);

uses(Tests\TestCase::class);

/*
 * Un point d'arrivée de notification qui ne répond pas ne doit pas garder un
 * travailleur de la file partagée : le client HTTP a des délais explicites,
 * 10 s au total (le paquet laissait 30 s) et 5 s pour se connecter.
 */
it('borne la durée d’un envoi push par des délais explicites', function (): void {
    $options = config()->array('webpush.client_options');

    expect($options['timeout'] ?? null)->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(10)
        ->and($options['connect_timeout'] ?? null)->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(5);
});
