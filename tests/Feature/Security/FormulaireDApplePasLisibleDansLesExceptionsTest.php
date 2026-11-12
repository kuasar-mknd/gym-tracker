<?php

declare(strict_types=1);

use App\Models\ExceptionEnregistree;

/*
 * Le retour d'Apple à la connexion (`form_post`) : ses champs `user`,
 * `id_token` et `code` sont masqués dans le corps enregistré.
 */
it('masque le champ user que porte le retour d’Apple', function (): void {
    ExceptionEnregistree::query()->create([
        'type' => RuntimeException::class,
        'code' => '0',
        'message' => 'Échec',
        'file' => '/app/x.php',
        'line' => 1,
        'trace' => [],
        'method' => 'POST',
        'path' => 'auth/apple/callback',
        'ip' => '203.0.113.7',
        'headers' => [],
        'body' => ['user' => '{"name":{"firstName":"A"},"email":"a@example.org"}', 'id_token' => 'eyJhbGciOiJub25lIn0.eyJlbWFpbCI6ImFAZXhhbXBsZS5vcmcifQ.sig', 'code' => 'c-apple-1', 'state' => 'x'],
    ]);

    $corps = ExceptionEnregistree::query()->sole()->body;

    expect($corps['user'] ?? null)->toBe('[masqué]')
        ->and($corps['id_token'] ?? null)->toBe('[masqué]')
        ->and($corps['code'] ?? null)->toBe('[masqué]')
        ->and($corps['state'] ?? null)->toBe('x')
        ->and(json_encode($corps))->not->toContain('a@example.org')
        ->and(json_encode($corps))->not->toContain('eyJlbWFpbCI6');
});
