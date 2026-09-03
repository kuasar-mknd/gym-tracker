<?php

declare(strict_types=1);

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
