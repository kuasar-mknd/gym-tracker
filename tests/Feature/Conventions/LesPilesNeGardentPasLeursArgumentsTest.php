<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/*
 * Une exception garde les arguments de chaque appel de sa pile : les champs
 * reçus, les valeurs d'une requête SQL, un jeton passé à une fonction, dans le
 * code des paquets comme dans le nôtre. La table des exceptions du panneau les
 * garde tels quels, les journaux et `failed_jobs` en recopient le début (#2032).
 *
 * L'image ne charge aucun php.ini : PHP y garde son défaut, qui conserve les
 * arguments. `docker/php-prod.ini` pose donc `zend.exception_ignore_args`, et
 * `docker/php-cli/zz-cli.ini`, qui a le dernier mot dans worker et scheduler,
 * le répète. La CI, elle, part du php.ini de production de PHP, qui le pose
 * déjà : un test qui lit une pile règle ce réglage lui-même
 * (`LesErreursSqlNeRecopientPasLesValeursTest`).
 */

/**
 * Les ini de l'image qui doivent poser le réglage, en chemins du dépôt.
 *
 * @return list<string>
 */
function pilesSansArgumentsIniDeLImage(): array
{
    return ['docker/php-prod.ini', 'docker/php-cli/zz-cli.ini'];
}

/**
 * Le nom sous lequel le `Dockerfile` copie `php-prod.ini` dans le dossier
 * d'ini par défaut de l'image.
 */
function pilesSansArgumentsNomDansLImage(): ?string
{
    preg_match(
        '#^COPY docker/php-prod\.ini /usr/local/etc/php/conf\.d/(\S+\.ini)$#m',
        (string) file_get_contents(base_path('Dockerfile')),
        $copie,
    );

    return $copie[1] ?? null;
}

/**
 * La valeur de `PHP_INI_SCAN_DIR` que la composition pose dans un service.
 */
function pilesSansArgumentsDossiersDuService(string $service): ?string
{
    $composition = Yaml::parseFile(base_path('docker-compose.prod.yml'));
    $valeur = is_array($composition) ? data_get($composition, "services.{$service}.environment.PHP_INI_SCAN_DIR") : null;

    return is_string($valeur) ? $valeur : null;
}

/**
 * Lance PHP avec les ini empilés comme dans un conteneur, et rend ce que voit
 * une exception levée par une fonction qui reçoit une chaîne.
 *
 * `-c` vers un dossier vide écarte le php.ini de la machine qui fait tourner
 * la suite ; le cache fichier d'OPcache va dans un dossier temporaire, celui
 * de l'image n'existant pas ici.
 *
 * @param  list<string>  $dossiersApresLImage  en chemins du dépôt
 * @return array<string, mixed>
 */
function pilesSansArgumentsExecuter(array $dossiersApresLImage): array
{
    $racine = sys_get_temp_dir().'/piles-sans-arguments-'.bin2hex(random_bytes(6));
    $parDefaut = $racine.'/conf.d';
    mkdir($parDefaut, 0777, true);
    mkdir($racine.'/sans-php-ini');
    mkdir($racine.'/cache');
    copy(base_path('docker/php-prod.ini'), $parDefaut.'/'.pilesSansArgumentsNomDansLImage());

    $script = 'function recoit(string $valeur): never { throw new RuntimeException("boum"); }'
        .'try { recoit("canari-pile"); } catch (RuntimeException $e) {'
        .' echo json_encode(['
        .'"reglage" => ini_get("zend.exception_ignore_args"),'
        .'"arguments" => array_key_exists("args", $e->getTrace()[0]),'
        .'"texte" => $e->getTraceAsString(),'
        .' ]); }';

    try {
        $processus = new Process(
            [PHP_BINARY, '-c', $racine.'/sans-php-ini', '-d', 'opcache.file_cache='.$racine.'/cache', '-r', $script],
            base_path(),
            ['PHP_INI_SCAN_DIR' => implode(':', [$parDefaut, ...array_map(base_path(...), $dossiersApresLImage)])],
        );
        $processus->mustRun();
    } finally {
        new Filesystem()->deleteDirectory($racine);
    }

    $sortie = json_decode($processus->getOutput(), true);

    expect($sortie)->toBeArray($processus->getOutput().$processus->getErrorOutput());

    /** @var array<string, mixed> $sortie */
    return $sortie;
}

it('pose zend.exception_ignore_args dans chaque ini de l’image', function (): void {
    foreach (pilesSansArgumentsIniDeLImage() as $fichier) {
        $reglages = parse_ini_file(base_path($fichier));

        expect($reglages)->toBeArray("{$fichier} ne se lit pas comme un ini.")
            ->and(is_array($reglages) ? ($reglages['zend.exception_ignore_args'] ?? null) : null)
            ->toBe('1', "{$fichier} doit poser zend.exception_ignore_args = On : sans lui, la pile d'une exception garde les arguments de chaque appel.");
    }
});

it('charge ces ini dans l’image, et celui de la ligne de commande dans worker et scheduler', function (): void {
    expect(pilesSansArgumentsNomDansLImage())->toBeString(
        "Le Dockerfile ne copie plus docker/php-prod.ini dans le dossier d'ini par défaut de l'image.",
    );

    foreach (['worker', 'scheduler'] as $service) {
        $dossiers = explode(':', (string) pilesSansArgumentsDossiersDuService($service));

        expect($dossiers[0])->toBe('', "PHP_INI_SCAN_DIR de {$service} doit garder le dossier par défaut de l'image.")
            ->and($dossiers)->toContain('/app/docker/php-cli');
    }
});

it('retire les arguments de la pile, les ini empilés comme dans le conteneur', function (?string $dossierApresLImage): void {
    $vu = pilesSansArgumentsExecuter($dossierApresLImage === null ? [] : [$dossierApresLImage]);
    $texte = $vu['texte'] ?? null;

    expect($vu['reglage'] ?? null)->toBe('1')
        ->and($vu['arguments'] ?? null)->toBeFalse()
        ->and($texte)->toBeString()
        ->and(is_string($texte) ? $texte : '')->not->toContain('canari-pile');
})->with([
    'app' => [null],
    'worker et scheduler' => ['docker/php-cli'],
]);
