<?php

declare(strict_types=1);

uses(Tests\TestCase::class);

/*
 * Un point d'arrivée de notification qui ne répond pas ne doit pas garder un
 * travailleur de la file partagée : le client HTTP a des délais explicites.
 */
it('borne la durée d’un envoi push par des délais explicites', function (): void {
    $options = config('webpush.client_options');

    expect($options)->toBeArray()
        ->and($options['timeout'] ?? null)->toBeInt()->toBeGreaterThan(0)
        ->and($options['connect_timeout'] ?? null)->toBeInt()->toBeGreaterThan(0);
});
