<?php

declare(strict_types=1);

/**
 * Les entrées du precache que la construction a injectées dans public/sw.js,
 * de leur adresse à leur révision (nulle pour un actif dont le nom porte déjà
 * l'empreinte).
 *
 * @return array<string, string|null>
 */
function entreesDuPrecacheDuWorker(): array
{
    if (preg_match('/\[\{"revision".*?\}\]/s', (string) file_get_contents(public_path('sw.js')), $manifeste) !== 1) {
        return [];
    }

    /** @var list<array{url: string, revision: string|null}> $entrees */
    $entrees = json_decode($manifeste[0], true, 512, JSON_THROW_ON_ERROR);

    return array_column($entrees, 'revision', 'url');
}

/**
 * Le worker et le manifeste sont des fichiers statiques à la racine de
 * public/ : servis sans Laravel, ils couvrent la portée / et n'ouvrent
 * aucune session. Le precache liste des entrées sous /build/ et des icônes
 * de public/ ; une seule en 404 et Workbox annule toute l'installation,
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
        // Un fichier de public/, que le serveur sert tel quel : jamais un script que PHP exécuterait (#2048).
        $statique = ! str_ends_with($chemin, '.php') && is_file(public_path(ltrim($chemin, '/')));

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

/*
 * Sans réseau, le worker sert la page « hors ligne » à toute navigation
 * (#1966). Elle doit donc être dans le precache que la construction injecte,
 * avec la feuille de style qu'elle demande : une feuille absente du precache
 * ne viendrait pas sans réseau, et la page s'afficherait nue.
 */
it('précache la page « hors ligne » avec la feuille de style qu’elle demande', function (): void {
    if (! is_file(public_path('sw.js'))) {
        $this->markTestSkipped('public/sw.js absent : lancer npm run build.');
    }

    preg_match_all('/"url":"([^"]+)"/', (string) file_get_contents(public_path('sw.js')), $trouvees);
    $precache = array_map(static fn (string $url): string => explode('?', $url)[0], $trouvees[1]);

    expect($precache)->toContain('/build/hors-ligne.html')
        ->and(is_file(public_path('build/hors-ligne.html')))->toBeTrue();

    preg_match_all('/<link rel="stylesheet" href="([^"]+)">/', (string) file_get_contents(public_path('build/hors-ligne.html')), $feuilles);

    expect($feuilles[1])->not->toBe([])
        ->and(array_diff($feuilles[1], $precache))->toBe([]);
});

/*
 * La page est servie à quiconque ouvre l'application sans réseau, après la
 * déconnexion d'un compte comme avant la connexion d'un autre : un fichier
 * statique, construit une fois, qui ne porte ni page Inertia ni jeton. Le
 * precache ne contient d'ailleurs aucun document de l'application.
 */
it('ne précache aucune page de l’application, et rien d’un compte dans la page « hors ligne »', function (): void {
    if (! is_file(public_path('sw.js'))) {
        $this->markTestSkipped('public/sw.js absent : lancer npm run build.');
    }

    preg_match_all('/"url":"([^"]+)"/', (string) file_get_contents(public_path('sw.js')), $trouvees);
    $documents = array_values(array_filter(
        $trouvees[1],
        static fn (string $url): bool => preg_match('#(\.html|/[^./]*)$#', explode('?', $url)[0]) === 1,
    ));

    expect($documents)->toBe(['/build/hors-ligne.html']);

    $page = (string) file_get_contents(public_path('build/hors-ligne.html'));

    expect($page)->not->toContain('data-page')
        ->and($page)->not->toContain('csrf')
        ->and($page)->toContain('Pas de réseau');
});

/*
 * Material Symbols, dont l'icône `cloud_off` qui marque une écriture en
 * attente, n'entrait au cache qu'à sa première utilisation (#2048) : la police
 * que la feuille de l'application demande est au precache.
 */
it('précache la police des icônes que la feuille de l’application demande', function (): void {
    $feuilles = (array) glob(public_path('build/assets/main-*.css'));

    if (! is_file(public_path('sw.js')) || $feuilles === []) {
        $this->markTestSkipped('public/sw.js absent : lancer npm run build.');
    }

    $demandees = [];

    foreach ($feuilles as $feuille) {
        preg_match_all('#/build/assets/material-symbols-outlined-[\w-]+\.woff2#', (string) file_get_contents((string) $feuille), $trouvees);
        $demandees = [...$demandees, ...$trouvees[0]];
    }

    expect($demandees)->not->toBe([])
        ->and(array_diff(array_unique($demandees), array_keys(entreesDuPrecacheDuWorker())))->toBe([]);
});

/*
 * Le favicon, les icônes de l'application installée, celles des notifications
 * et les SVG du logo vivent à la racine de public/, que les globs du precache
 * ne lisent pas : aucun ne venait sans réseau (#2048). Chacun entre au
 * precache avec l'empreinte de son contenu pour révision, pour qu'une icône
 * redessinée soit téléchargée de nouveau.
 */
it('précache chaque icône de public/ avec l’empreinte de son contenu', function (): void {
    if (! is_file(public_path('sw.js'))) {
        $this->markTestSkipped('public/sw.js absent : lancer npm run build.');
    }

    $icones = array_values(array_filter(
        (array) scandir(public_path()),
        static fn (mixed $nom): bool => is_string($nom) && is_file(public_path($nom)) && preg_match('/\.(ico|png|svg|webp)$/', $nom) === 1,
    ));
    $precache = entreesDuPrecacheDuWorker();

    expect($icones)->toContain('logo.svg')
        ->toContain('icone.svg')
        ->toContain('favicon.ico')
        ->toContain('pwa-192x192.png')
        ->toContain('badge-96x96.png');

    foreach ($icones as $icone) {
        expect($precache)->toHaveKey('/'.$icone)
            ->and($precache['/'.$icone])->toBe(hash_file('sha256', public_path($icone)));
    }
});
