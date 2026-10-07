<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * La documentation décrivait l'application de mars : sur 24 fichiers, 21
 * affirmations de version fausses et 13 chemins cités qui n'existaient plus
 * (audit du 2026-09-02, #1671). Une documentation committee est une copie,
 * et une copie diverge : ce garde échoue dès qu'un document cite un chemin
 * mort ou une version qui n'est plus celle des manifestes.
 */

/**
 * @return list<string>
 */
function documentsSurveillesPourLaDerive(): array
{
    $fichiers = [];

    foreach (['*.md', 'docs/*.md', 'docs/*/*.md', '.ai/rules/*.md'] as $motif) {
        $trouves = glob(base_path($motif));
        $fichiers = [...$fichiers, ...($trouves === false ? [] : $trouves)];
    }

    // Le journal des modifications cite par nature des fichiers disparus.
    return array_values(array_filter($fichiers, fn (string $f): bool => basename($f) !== 'CHANGELOG.md'));
}

/**
 * @return array<string, mixed>
 */
function manifesteJson(string $chemin): array
{
    $donnees = json_decode((string) file_get_contents(base_path($chemin)), true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($donnees)) {
        throw new RuntimeException($chemin.' ne contient pas un objet JSON.');
    }

    /** @var array<string, mixed> $donnees */
    return $donnees;
}

/**
 * @param  array<string, mixed>  $composer
 * @return list<array<string, mixed>>
 */
function paquetsDuLock(array $composer): array
{
    $paquets = [];

    foreach (['packages', 'packages-dev'] as $section) {
        $liste = $composer[$section] ?? [];

        foreach (is_array($liste) ? $liste : [] as $paquet) {
            if (is_array($paquet)) {
                /** @var array<string, mixed> $paquet */
                $paquets[] = $paquet;
            }
        }
    }

    return $paquets;
}

/**
 * Le bloc que Laravel Boost régénère dans CLAUDE.md n'est pas à nous : ses
 * chemins et ses versions sont ceux du paquet, pas du dépôt.
 */
function contenuDocumentaireDe(string $fichier): string
{
    $contenu = (string) file_get_contents($fichier);
    $debut = strpos($contenu, '<laravel-boost-guidelines>');
    $fin = strpos($contenu, '</laravel-boost-guidelines>');

    if ($debut === false || $fin === false || $fin < $debut) {
        return $contenu;
    }

    return substr($contenu, 0, $debut).substr($contenu, $fin + strlen('</laravel-boost-guidelines>'));
}

/**
 * Chemins cités entre accents graves ou comme cible de lien relative :
 * `app/Models/User.php`, [texte](docs/adr/0001.md). Un chemin suffixé d'un
 * numéro de ligne, d'un joker ou d'une ancre est ramené à sa partie fixe ;
 * un nom de paquet ou de jeu de règles (`vendor/paquet`, `p/php`) est écarté
 * parce que son premier segment n'existe pas à la racine du dépôt.
 *
 * @return list<string>
 */
function cheminsCitesDans(string $contenu): array
{
    preg_match_all('/`([A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.*-]+)+\/?)(?::\d+(?:-\d+)?)?`/', $contenu, $graves);
    preg_match_all('/\]\(((?!https?:|mailto:|#)[^)\s]+)\)/', $contenu, $liens);

    $chemins = [];

    foreach ([...$graves[1], ...$liens[1]] as $brut) {
        $chemin = preg_replace('/[#?].*$/', '', $brut) ?? $brut;
        $chemin = rtrim(preg_replace('/\*.*$/', '', $chemin) ?? $chemin, '/');

        if ($chemin === '' || ! str_contains($chemin, '/') || str_starts_with($chemin, '/') || str_starts_with($chemin, '$') || str_contains($chemin, '{')) {
            continue;
        }

        if (! file_exists(base_path(explode('/', $chemin)[0]))) {
            continue;
        }

        $chemins[] = $chemin;
    }

    return array_values(array_unique($chemins));
}

it('ne cite aucun chemin qui n existe plus', function (): void {
    $morts = [];

    foreach (documentsSurveillesPourLaDerive() as $fichier) {
        foreach (cheminsCitesDans(contenuDocumentaireDe($fichier)) as $chemin) {
            if (! file_exists(base_path($chemin)) && ! file_exists(dirname($fichier).'/'.$chemin)) {
                $morts[] = str_replace(base_path().'/', '', $fichier).' → '.$chemin;
            }
        }
    }

    expect($morts)->toBe([]);
});

it('n annonce aucune version majeure qui n est pas celle des manifestes', function (): void {
    $paquetsComposer = paquetsDuLock(manifesteJson('composer.lock'));
    $paquetsNpm = manifesteJson('package.json');

    $versionComposer = function (string $nom) use ($paquetsComposer): ?string {
        foreach ($paquetsComposer as $paquet) {
            $version = $paquet['version'] ?? null;

            if (($paquet['name'] ?? null) === $nom && is_string($version)) {
                return ltrim($version, 'v');
            }
        }

        return null;
    };
    $versionNpm = function (string $nom) use ($paquetsNpm): ?string {
        foreach (['dependencies', 'devDependencies'] as $section) {
            $liste = $paquetsNpm[$section] ?? null;
            $declaree = is_array($liste) ? ($liste[$nom] ?? null) : null;

            if (is_string($declaree)) {
                return ltrim($declaree, '^~');
            }
        }

        return null;
    };
    $majeure = fn (?string $version): ?string => is_string($version) ? explode('.', $version)[0] : null;
    $phpMineure = preg_match('/^(\d+\.\d+)/', PHP_VERSION, $m) === 1 ? $m[1] : null;

    $attendues = array_filter([
        'Laravel' => $majeure($versionComposer('laravel/framework')),
        'Filament' => $majeure($versionComposer('filament/filament')),
        'Pest' => $majeure($versionComposer('pestphp/pest')),
        'PHPUnit' => $majeure($versionComposer('phpunit/phpunit')),
        'Inertia' => $majeure($versionNpm('@inertiajs/vue3')),
        'Vue' => $majeure($versionNpm('vue')),
        'Tailwind' => $majeure($versionNpm('tailwindcss')),
        'Vite' => $majeure($versionNpm('vite')),
        'PHP' => $phpMineure,
    ], fn (?string $version): bool => $version !== null);

    expect($attendues)->toHaveKeys(['Laravel', 'Inertia', 'Vue', 'Tailwind', 'PHP']);

    $fausses = [];

    foreach (documentsSurveillesPourLaDerive() as $fichier) {
        $contenu = contenuDocumentaireDe($fichier);

        foreach ($attendues as $outil => $attendue) {
            $motif = $outil === 'PHP' ? '/\bPHP\s+v?(\d+\.\d+)/i' : '/\b'.$outil.'\s+v?(\d+)(?:\.\d+)*\b/i';
            preg_match_all($motif, $contenu, $trouvees);

            foreach (array_unique($trouvees[1]) as $annoncee) {
                if ($annoncee !== $attendue) {
                    $fausses[] = str_replace(base_path().'/', '', $fichier).' → '.$outil.' '.$annoncee.' (réel : '.$attendue.')';
                }
            }
        }
    }

    expect($fausses)->toBe([]);
});

/**
 * Les clients HTTP que l'application a déjà employés et que `package.json`
 * n'installe plus.
 *
 * @return list<string>
 */
function clientsHttpAbsentsDuManifeste(): array
{
    $paquetsNpm = manifesteJson('package.json');
    $installes = [];

    foreach (['dependencies', 'devDependencies'] as $section) {
        $liste = $paquetsNpm[$section] ?? [];
        $installes = [...$installes, ...array_keys(is_array($liste) ? $liste : [])];
    }

    return array_values(array_diff(['axios'], $installes));
}

/**
 * Les lignes des fichiers donnés qui nomment un client HTTP absent du manifeste.
 *
 * @param  array<string, string>  $contenus  Fichier => contenu lu.
 * @return list<string>
 */
function citationsDeClientsHttpAbsents(array $contenus): array
{
    $absents = clientsHttpAbsentsDuManifeste();
    $citations = [];

    foreach ($contenus as $fichier => $contenu) {
        $lignes = preg_split('/\R/', $contenu);

        foreach ($lignes === false ? [] : $lignes as $index => $ligne) {
            foreach ($absents as $client) {
                if (preg_match('/\b'.preg_quote($client, '/').'\b/i', $ligne) === 1) {
                    $citations[] = sprintf('%s:%d → %s', str_replace(base_path().'/', '', $fichier), $index + 1, $client);
                }
            }
        }
    }

    return $citations;
}

/*
 * Axios a quitté le projet avec Inertia 3 (#1815, #1827) : les écritures de la
 * page de séance passent par `SyncService` et `resources/js/Utils/http.js`, qui
 * envoie `X-CSRF-TOKEN` lu dans la balise meta, et non plus `X-XSRF-TOKEN` lu
 * dans le cookie. Les règles de `.ai/rules`, que tout agent lit avant de
 * modifier un fichier, décrivaient encore l'ancien client : un 419 sur une
 * route API s'y serait cherché du mauvais côté (#1993).
 */
it('ne nomme aucun client HTTP que package.json n installe pas', function (): void {
    $contenus = [];

    foreach (documentsSurveillesPourLaDerive() as $fichier) {
        $contenus[$fichier] = contenuDocumentaireDe($fichier);
    }

    expect(citationsDeClientsHttpAbsents($contenus))->toBe([]);
});

/*
 * La règle d'api.md corrigée, le client disparu restait déclaré en global à
 * ESLint, qui acceptait donc un appel à `axios` qu'aucun script ne définit, et
 * un commentaire de `bootstrap/app.php` le donnait pour l'appelant des routes
 * web en JSON (#1993). Le code serveur et la configuration d'ESLint ne
 * nomment pas davantage un client que `package.json` n'installe pas.
 */
it('ne déclare à ESLint ni ne décrit dans le code serveur aucun client HTTP que package.json n installe pas', function (): void {
    $contenus = [base_path('eslint.config.js') => (string) file_get_contents(base_path('eslint.config.js'))];

    foreach (Finder::create()->files()->in(array_map(base_path(...), ['app', 'bootstrap', 'config', 'routes']))->exclude('cache')->name('*.php') as $fichier) {
        $contenus[(string) $fichier->getRealPath()] = $fichier->getContents();
    }

    expect(citationsDeClientsHttpAbsents($contenus))->toBe([]);
});

/*
 * Le commentaire d'une étape de `ci.yml` promettait que le job échouait si la
 * spec OpenAPI commitée différait de ce que produisaient les annotations.
 * La spec et l5-swagger sont partis avec l'ancienne API, l'étape aussi : le
 * commentaire décrivait une protection qui n'existait plus (#1993).
 */
it('ne décrit dans les workflows aucune spec d API que plus rien ne produit', function (): void {
    $outilsDeSpec = array_filter(
        paquetsDuLock(manifesteJson('composer.lock')),
        static fn (array $paquet): bool => in_array($paquet['name'] ?? null, ['darkaonline/l5-swagger', 'zircote/swagger-php'], true),
    );

    if ($outilsDeSpec !== []) {
        expect($outilsDeSpec)->not->toBeEmpty();

        return;
    }

    $mentions = [];

    $workflows = glob(base_path('.github/workflows/*.yml'));

    foreach ($workflows === false ? [] : $workflows as $workflow) {
        $lignes = file($workflow, FILE_IGNORE_NEW_LINES);

        foreach ($lignes === false ? [] : $lignes as $index => $ligne) {
            if (preg_match('/\b(spec|openapi|swagger)\b/i', $ligne) === 1) {
                $mentions[] = sprintf('%s:%d', str_replace(base_path().'/', '', $workflow), $index + 1);
            }
        }
    }

    expect($mentions)->toBe([]);
});
