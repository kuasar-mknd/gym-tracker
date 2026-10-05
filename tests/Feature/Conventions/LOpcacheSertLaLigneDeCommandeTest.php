<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/*
 * worker et scheduler ne font tourner que PHP en ligne de commande, et
 * `docker/php-prod.ini` y laissait OPcache coupé (`opcache.enable_cli` à Off) :
 * chaque démarrage d'artisan recompilait tout le code chargé, environ cinq fois
 * par minute entre `schedule:run`, ses tâches et les sondes de santé (#1968).
 *
 * Deux pièges tiennent ce réglage. Activer OPcache en ligne de commande en
 * gardant le JIT « tracing » de `php-prod.ini` rend chaque commande plus lente
 * qu'avant, et le cache fichier n'est jamais écrit. Et le poser pour tout le
 * conteneur `app` couperait le JIT du serveur web, qui hérite de son
 * environnement. D'où un ini propre à la ligne de commande, que seuls worker et
 * scheduler chargent par `PHP_INI_SCAN_DIR`.
 */

/**
 * Les services qui ne font tourner que PHP en ligne de commande.
 *
 * @return list<string>
 */
function opcacheCliServices(): array
{
    return ['worker', 'scheduler'];
}

/**
 * Les services de `docker-compose.prod.yml`, ancres fusionnées.
 *
 * @return array<string, array<string, mixed>>
 */
function opcacheCliServicesDeLaComposition(): array
{
    $composition = Yaml::parseFile(base_path('docker-compose.prod.yml'));
    $services = is_array($composition) ? ($composition['services'] ?? null) : null;

    expect($services)->toBeArray()->toHaveKeys(['app', ...opcacheCliServices()]);

    /** @var array<string, array<string, mixed>> $services */
    return $services;
}

/**
 * La valeur de `PHP_INI_SCAN_DIR` que la composition pose dans un service.
 */
function opcacheCliDossiersDuService(string $service): ?string
{
    $environnement = opcacheCliServicesDeLaComposition()[$service]['environment'] ?? [];

    expect($environnement)->toBeArray();

    /** @var array<string, mixed> $environnement */
    $valeur = $environnement['PHP_INI_SCAN_DIR'] ?? null;

    return is_string($valeur) ? $valeur : null;
}

/**
 * Les dossiers d'ini propres à la ligne de commande, en chemins du dépôt.
 *
 * L'image copie le dépôt dans /app (`WORKDIR /app`, `COPY . .`).
 *
 * @return list<string>
 */
function opcacheCliDossiersDuDepot(): array
{
    $valeur = opcacheCliDossiersDuService('worker');

    expect($valeur)->toBeString();

    $dossiers = [];

    foreach (array_slice(explode(':', (string) $valeur), 1) as $dossier) {
        expect($dossier)->toStartWith('/app/', "« {$dossier} » n'est pas un dossier du dépôt copié dans l'image.");

        $dossiers[] = base_path(substr($dossier, strlen('/app/')));
    }

    return $dossiers;
}

/**
 * Les ini d'un dossier, dans l'ordre où PHP les lit.
 *
 * @return list<string>
 */
function opcacheCliIniDe(string $dossier): array
{
    $fichiers = glob($dossier.'/*.ini');
    $fichiers = $fichiers === false ? [] : $fichiers;
    sort($fichiers);

    return $fichiers;
}

/**
 * Les réglages des ini de la ligne de commande, dans l'ordre où PHP les lit.
 *
 * @return array<string, string>
 */
function opcacheCliReglages(): array
{
    $reglages = [];

    foreach (opcacheCliDossiersDuDepot() as $dossier) {
        foreach (opcacheCliIniDe($dossier) as $fichier) {
            $lus = parse_ini_file($fichier, false, INI_SCANNER_RAW);

            expect($lus)->toBeArray("{$fichier} ne se lit pas comme un ini.");

            /** @var array<string, string> $lus */
            $reglages = [...$reglages, ...$lus];
        }
    }

    return $reglages;
}

/**
 * Le dossier par défaut de l'image, rejoué : `php-prod.ini` sous le nom que lui
 * donne le `Dockerfile`, seul dans un dossier temporaire.
 */
function opcacheCliDossierParDefautDeLImage(string $racine): string
{
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    preg_match('#^COPY docker/php-prod\.ini /usr/local/etc/php/conf\.d/(\S+\.ini)$#m', $dockerfile, $copie);
    $nom = $copie[1] ?? null;

    expect($nom)->toBeString("Le Dockerfile ne copie plus docker/php-prod.ini dans le dossier d'ini par défaut de l'image.");

    $dossier = $racine.'/conf.d';
    mkdir($dossier, 0777, true);
    copy(base_path('docker/php-prod.ini'), $dossier.'/'.$nom);

    return $dossier;
}

/**
 * Lance PHP avec les ini empilés comme dans l'image, et rend ce qu'il affiche.
 *
 * `-c` vers un dossier vide écarte le php.ini de la machine qui fait tourner
 * la suite ; le cache fichier va dans un dossier temporaire, le chemin de
 * l'image n'existant pas ici.
 *
 * @return array<string, mixed>
 */
function opcacheCliExecuter(string $racine, string $dossiersDIni, string $cache, string $script): array
{
    $processus = new Process(
        [PHP_BINARY, '-c', $racine.'/sans-php-ini', '-d', 'opcache.file_cache='.$cache, '-r', $script],
        base_path(),
        ['PHP_INI_SCAN_DIR' => $dossiersDIni],
    );
    $processus->mustRun();

    $sortie = json_decode($processus->getOutput(), true);

    expect($sortie)->toBeArray($processus->getOutput().$processus->getErrorOutput());

    /** @var array<string, mixed> $sortie */
    return $sortie;
}

/**
 * Supprime un dossier temporaire et son contenu.
 */
function opcacheCliEffacer(string $dossier): void
{
    if (! is_dir($dossier)) {
        return;
    }

    $elements = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($elements as $element) {
        if (! $element instanceof SplFileInfo) {
            continue;
        }

        if ($element->isDir()) {
            rmdir($element->getPathname());
        } else {
            unlink($element->getPathname());
        }
    }

    rmdir($dossier);
}

it('charge l’ini de la ligne de commande dans worker et scheduler, après le dossier par défaut de l’image', function (): void {
    foreach (opcacheCliServices() as $service) {
        $valeur = opcacheCliDossiersDuService($service);

        expect($valeur)->toBeString("{$service} ne pose pas PHP_INI_SCAN_DIR : OPcache y reste coupé.")
            // Sans le premier dossier vide, PHP ne lit plus le dossier par
            // défaut : ni les extensions de l'image, ni php-prod.ini.
            ->and(explode(':', (string) $valeur)[0])->toBe('', "PHP_INI_SCAN_DIR de {$service} doit commencer par « : ».")
            ->and($valeur)->toBe(opcacheCliDossiersDuService('worker'));
    }

    foreach (opcacheCliDossiersDuDepot() as $dossier) {
        expect(opcacheCliIniDe($dossier))->not->toBeEmpty("{$dossier} ne contient aucun ini.");
    }
});

it('laisse au serveur web son JIT', function (): void {
    expect(opcacheCliDossiersDuService('app'))->toBeNull(
        'app ne doit pas charger les ini de la ligne de commande : le serveur web hérite de son environnement et perdrait son JIT.',
    );

    $production = parse_ini_file(base_path('docker/php-prod.ini'), false, INI_SCANNER_RAW);

    expect($production)->toBeArray()
        ->toHaveKey('opcache.jit', 'tracing');
});

it('active OPcache en ligne de commande, sans JIT, avec un cache fichier que l’image crée pour www-data', function (): void {
    $reglages = opcacheCliReglages();

    expect($reglages)->toHaveKey('opcache.enable_cli', '1')
        // Avec le JIT, le cache fichier n'est jamais écrit.
        ->toHaveKey('opcache.jit', 'disable')
        // config:cache et route:cache réécrivent bootstrap/cache au démarrage.
        ->toHaveKey('opcache.validate_timestamps', '1')
        ->toHaveKey('opcache.file_cache');

    $cache = $reglages['opcache.file_cache'];
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    // OPcache refuse de démarrer sur un dossier absent ou en lecture seule :
    // toute commande PHP de worker et scheduler échouerait.
    expect($cache)->toStartWith('/')
        ->and($dockerfile)->toMatch('#mkdir -p [^\n]*'.preg_quote($cache, '#').'(\s|$)#')
        ->and($dockerfile)->toMatch('#chown -R www-data:www-data [^\n]*'.preg_quote($cache, '#').'(\s|$)#')
        ->and($dockerfile)->toMatch('/^USER www-data$/m');

    // L'ini doit arriver dans l'image : `.dockerignore` n'en écarte rien.
    $lignes = file(base_path('.dockerignore'));
    $ignores = array_filter(
        array_map(trim(...), $lignes === false ? [] : $lignes),
        static fn (string $ligne): bool => $ligne !== '' && ! str_starts_with($ligne, '#'),
    );

    $chemins = ['docker'];

    foreach (opcacheCliDossiersDuDepot() as $dossier) {
        foreach ([$dossier, ...opcacheCliIniDe($dossier)] as $chemin) {
            $chemins[] = ltrim(substr($chemin, strlen(base_path())), '/');
        }
    }

    foreach ($ignores as $motif) {
        foreach ($chemins as $chemin) {
            expect(fnmatch(ltrim($motif, '/'), $chemin))->toBeFalse(".dockerignore écarte {$chemin} de l'image ({$motif}).");
        }
    }
});

it('écrit le cache fichier et relit un fichier réécrit, les ini empilés comme dans l’image', function (): void {
    $racine = sys_get_temp_dir().'/opcache-cli-'.bin2hex(random_bytes(6));
    $parDefaut = opcacheCliDossierParDefautDeLImage($racine);
    $cache = $racine.'/cache';
    $source = $racine.'/source.php';
    mkdir($cache);
    mkdir($racine.'/sans-php-ini');

    $script = 'echo json_encode(['
        .'"enable_cli" => ini_get("opcache.enable_cli"),'
        .'"jit" => ini_get("opcache.jit"),'
        .'"active" => (opcache_get_status(false) ?: [])["opcache_enabled"] ?? false,'
        .'"valeur" => is_file('.var_export($source, true).') ? require '.var_export($source, true).' : null,'
        .']);';

    try {
        // OPcache ne met pas en cache un fichier modifié depuis moins de deux
        // secondes (opcache.file_update_protection) : on le vieillit.
        file_put_contents($source, '<?php return "avant";');
        touch($source, time() - 3600);

        $cli = $parDefaut.':'.implode(':', opcacheCliDossiersDuDepot());
        $premier = opcacheCliExecuter($racine, $cli, $cache, $script);

        expect($premier)->toMatchArray(['enable_cli' => '1', 'jit' => 'disable', 'active' => true, 'valeur' => 'avant']);

        $ecrits = iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS)));
        expect($ecrits)->not->toBeEmpty('Le cache fichier est resté vide : chaque processus recompile tout.');

        // Ce que config:cache fait au démarrage d'un conteneur.
        file_put_contents($source, '<?php return "après";');
        touch($source, time() - 1800);

        expect(opcacheCliExecuter($racine, $cli, $cache, $script))->toHaveKey('valeur', 'après');

        // Sans l'ini de la ligne de commande, ce que voit app : le JIT reste.
        expect(opcacheCliExecuter($racine, $parDefaut, $cache, $script))
            ->toMatchArray(['enable_cli' => '0', 'jit' => 'tracing']);
    } finally {
        opcacheCliEffacer($racine);
    }
});
