<?php

declare(strict_types=1);

/**
 * FrankenPHP sert les actifs hachés de /build/assets avec un an de cache
 * immuable, par un Caddyfile à nous. Ce fichier est le gabarit d'Octane plus
 * un bloc : si le gabarit change avec une mise à jour d'Octane, le nôtre doit
 * suivre, sinon il ne sert plus ce qu'Octane sert.
 */
it('lance FrankenPHP avec notre Caddyfile', function (): void {
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    expect($dockerfile)->toContain('"--caddyfile=/app/docker/octane/Caddyfile"');
});

it('rend les actifs hachés immuables, et rien d’autre', function (): void {
    $caddyfile = (string) file_get_contents(base_path('docker/octane/Caddyfile'));

    expect($caddyfile)
        ->toContain("\t\t@actifs {\n\t\t\tpath /build/assets/*\n\t\t\tfile\n\t\t}\n")
        ->toContain('header @actifs Cache-Control "public, max-age=31536000, immutable"')
        ->and(substr_count($caddyfile, 'Cache-Control'))->toBe(1);
});

it('reste le gabarit d’Octane, à notre bloc près', function (): void {
    $notre = (string) file_get_contents(base_path('docker/octane/Caddyfile'));
    $gabarit = (string) file_get_contents(base_path('vendor/laravel/octane/src/Commands/stubs/Caddyfile'));

    $sansNotreBloc = (string) preg_replace("/\n\t\t# Les actifs de \/build\/assets.*?immutable\"\n/s", '', $notre);

    expect($sansNotreBloc)->toBe($gabarit);
});
