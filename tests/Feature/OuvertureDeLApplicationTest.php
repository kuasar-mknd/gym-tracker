<?php

declare(strict_types=1);

/*
 * Ce qu'ouvre l'application installée (#1969).
 *
 * Sans `id`, le navigateur déduit l'identité de l'application de `start_url`.
 * Changer l'adresse de départ sans la fixer d'abord en ferait une autre
 * application à ses yeux, et les PWA déjà installées ignoreraient la mise à
 * jour du manifeste. L'identité reste donc celle qu'elles ont toujours eue,
 * « / », l'ancienne adresse de départ.
 */

/**
 * Le manifeste de l'application, décodé.
 *
 * @return array<string, mixed>
 */
function ouvertureManifeste(): array
{
    $manifeste = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

    expect($manifeste)->toBeArray();

    /** @var array<string, mixed> $manifeste */
    return $manifeste;
}

it('garde aux applications installées l’identité qu’elles ont toujours eue', function (): void {
    expect(ouvertureManifeste())->toHaveKey('id', '/');
});
