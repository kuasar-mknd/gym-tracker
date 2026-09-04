<?php

declare(strict_types=1);

/**
 * Le worker est servi depuis la racine (/sw.js) pour couvrir la portée /, et
 * son precache liste des entrées sous /build/ plus `manifest.webmanifest`
 * résolu à la racine. Une seule entrée en 404 et Workbox annule toute
 * l'installation : c'est ce qui a laissé la production sans worker de la
 * 1.5.0 à la 1.5.8 (#1683).
 */
it('sert le worker et le manifeste depuis la racine avec les bons en-têtes', function (): void {
    if (! is_file(public_path('build/sw.js'))) {
        $this->markTestSkipped('public/build absent : lancer npm run build.');
    }

    $this->get('/sw.js')
        ->assertOk()
        ->assertHeader('Service-Worker-Allowed', '/')
        ->assertHeader('Content-Type', 'application/javascript');

    $this->get('/manifest.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json');
});

it('a un precache dont chaque entrée répond 200', function (): void {
    if (! is_file(public_path('build/sw.js'))) {
        $this->markTestSkipped('public/build absent : lancer npm run build.');
    }

    preg_match_all('/"url":"([^"]+)"/', (string) file_get_contents(public_path('build/sw.js')), $trouvees);
    $urls = array_values(array_unique($trouvees[1]));

    expect($urls)->not->toBe([]);

    $muettes = [];

    foreach ($urls as $url) {
        // Résolution comme le navigateur : relative à /sw.js, donc à la racine.
        $chemin = explode('?', str_starts_with($url, '/') ? $url : '/'.$url)[0];

        // Sous /build/, c'est un fichier que le serveur web sert tel quel ; le
        // reste passe par le routeur (manifest.webmanifest, servi à la racine).
        $servie = str_starts_with($chemin, '/build/')
            ? is_file(public_path(ltrim($chemin, '/')))
            : $this->get($chemin)->getStatusCode() === 200;

        if (! $servie) {
            $muettes[] = $url;
        }
    }

    expect($muettes)->toBe([]);
});
