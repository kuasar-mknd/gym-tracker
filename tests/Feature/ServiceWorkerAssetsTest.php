<?php

declare(strict_types=1);

/**
 * Le worker et le manifeste sont des fichiers statiques à la racine de
 * public/ : servis sans Laravel, ils couvrent la portée / et n'ouvrent
 * aucune session. Le precache liste des entrées sous /build/ plus le
 * manifeste ; une seule en 404 et Workbox annule toute l'installation,
 * ce qui a laissé la production sans worker de la 1.5.0 à la 1.5.8 (#1683).
 */
it('construit le worker et le manifeste à la racine de public', function (): void {
    if (! is_file(public_path('build/manifest.json'))) {
        $this->markTestSkipped('public/build absent : lancer npm run build.');
    }

    expect(is_file(public_path('sw.js')))->toBeTrue()
        ->and(is_file(public_path('manifest.webmanifest')))->toBeTrue()
        ->and(is_file(public_path('build/sw.js')))->toBeFalse();
});

it('a un precache dont chaque entrée est un fichier servi en statique', function (): void {
    if (! is_file(public_path('sw.js'))) {
        $this->markTestSkipped('public/sw.js absent : lancer npm run build.');
    }

    preg_match_all('/"url":"([^"]+)"/', (string) file_get_contents(public_path('sw.js')), $trouvees);
    $urls = array_values(array_unique($trouvees[1]));

    expect($urls)->not->toBe([]);

    $muettes = [];

    foreach ($urls as $url) {
        $chemin = explode('?', $url)[0];
        $statique = ($chemin === '/manifest.webmanifest' || str_starts_with($chemin, '/build/'))
            && is_file(public_path(ltrim($chemin, '/')));

        if (! $statique) {
            $muettes[] = $url;
        }
    }

    expect($muettes)->toBe([]);
});

/*
 * L'URL d'enregistrement est décidée par vite.config, pas par le serveur :
 * le bundle doit demander /sw.js, seul emplacement qui couvre la portée /.
 */
it('enregistre le worker depuis la racine', function (): void {
    $bundles = (array) glob(public_path('build/assets/main-*.js'));

    if ($bundles === []) {
        $this->markTestSkipped('public/build absent : lancer npm run build.');
    }

    $source = implode('', array_map(static fn (mixed $path): string => (string) file_get_contents((string) $path), $bundles));

    expect($source)->toContain('/sw.js')
        ->and($source)->not->toContain('/build/sw.js');
});
