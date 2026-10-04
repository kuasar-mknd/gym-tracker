<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * Le crochet de commit formate les fichiers PHP indexés avec Pint, et Pint lit
 * chaque fichier avec le PHP qui le lance. La règle de lint-staged lançait
 * `./vendor/bin/pint` avec le PHP de l'hôte (#1928) : sur un poste resté en
 * PHP 8.3, Pint échouait en erreur de syntaxe sur le code du projet, écrit
 * pour un PHP plus récent, et refusait tout commit.
 *
 * La règle passe désormais par `scripts/formater-le-php.sh`, qui prend le
 * conteneur de Sail s'il tourne pour ce dossier, sinon le PHP de l'hôte s'il
 * atteint la contrainte de `composer.json`, et sinon s'arrête en disant quoi
 * faire. Compose nomme le projet d'après le nom du dossier : une autre copie
 * du dépôt qui porte le même nom lui montre son conteneur, que le script doit
 * écarter, sans quoi Pint formaterait l'autre copie et le commit passerait
 * sans formatage.
 *
 * Le script tourne ici contre un faux `docker` et un faux PHP, qui peut aussi
 * échouer : le code d'échec de Pint doit arrêter le commit, par l'une et
 * l'autre voie. Deux tests le lancent enfin avec le PHP de la suite, sur un
 * fichier illisible, qui doit refuser le commit, et sur un fichier à formater.
 */

/**
 * Les commandes de lint-staged, rangées par motif de fichiers.
 *
 * @return array<string, list<string>>
 */
function crochetReglesDeLintStaged(): array
{
    $paquet = json_decode(File::get(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);
    $regles = is_array($paquet) ? ($paquet['lint-staged'] ?? []) : [];
    $commandes = [];

    foreach (is_array($regles) ? $regles : [] as $motif => $regle) {
        $commandes[(string) $motif] = array_values(array_filter((array) $regle, is_string(...)));
    }

    return $commandes;
}

/**
 * La contrainte « php » de `composer.json` et sa borne basse, que le script
 * lit lui aussi.
 *
 * @return array{contrainte: string, majeur: int, mineur: int}
 */
function crochetPhpDuProjet(): array
{
    $composer = json_decode(File::get(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $contrainte = data_get($composer, 'require.php');

    expect($contrainte)->toBeString('composer.json ne déclare plus de contrainte « php ».');
    assert(is_string($contrainte));
    preg_match('/(\d+)\.(\d+)/', $contrainte, $borne);

    expect($borne)->toHaveCount(3, "La contrainte « {$contrainte} » n'a pas de borne basse lisible.");
    assert(count($borne) === 3);

    return ['contrainte' => $contrainte, 'majeur' => (int) $borne[1], 'mineur' => (int) $borne[2]];
}

/**
 * Lance le script du crochet dans un dossier de faux binaires, comme
 * lint-staged le lance : depuis la racine du dépôt, avec des chemins absolus.
 *
 * `$sailTourne` décide de ce que le faux `docker` répond à `compose ps`, et
 * `$dossierDeSail` du dossier d'où Compose aurait lancé ce conteneur, qu'il
 * rend à `inspect` (par défaut, la racine réelle du dépôt) ; il note chacun de
 * ses appels dans `docker.log`. `$versionDuPhp` fait le faux PHP
 * de `PINT_PHP`, qui note dans `php.log` tout appel autre que la question de
 * sa version : c'est Pint qu'on lui aurait fait lancer. Sans version,
 * `PINT_PHP` vise un fichier absent ; avec `$phpReel`, le vrai PHP de la suite.
 * `$codeDePint` est le code que rend ce faux Pint, dans le conteneur
 * (`compose exec`) comme avec le faux PHP.
 *
 * @param  list<string>  $fichiers
 * @return array{code: int|null, sortie: string, erreurs: string, docker: string, php: string}
 */
function crochetFormater(array $fichiers, bool $sailTourne, ?string $versionDuPhp, bool $phpReel = false, int $codeDePint = 0, ?string $dossierDeSail = null): array
{
    $dossier = storage_path('framework/testing/crochet-'.uniqid());
    File::ensureDirectoryExists($dossier);
    $conteneur = $sailTourne ? 'c0ffee' : '';
    $dossierDeSail ??= crochetRacineReelle();

    try {
        File::put($dossier.'/docker', <<<BASH
            #!/usr/bin/env bash
            printf '%s\\n' "\$*" >> '{$dossier}/docker.log'
            case "\$*" in
                'compose ps'*) printf '%s\\n' '{$conteneur}' ;;
                'inspect '*) printf '%s\\n' '{$dossierDeSail}' ;;
                'compose exec'*) exit {$codeDePint} ;;
            esac
            exit 0
            BASH);

        File::put($dossier.'/php', <<<BASH
            #!/usr/bin/env bash
            if [ "\$1" = -r ]; then
                printf '%s' '{$versionDuPhp}'
                exit 0
            fi
            printf '%s\\n' "\$*" >> '{$dossier}/php.log'
            exit {$codeDePint}
            BASH);

        chmod($dossier.'/docker', 0755);
        chmod($dossier.'/php', 0755);

        $php = match (true) {
            $phpReel => PHP_BINARY,
            $versionDuPhp === null => $dossier.'/absent',
            default => $dossier.'/php',
        };

        $processus = new Process(
            ['bash', base_path('scripts/formater-le-php.sh'), ...$fichiers],
            base_path(),
            ['SAIL_DOCKER_BINARY' => $dossier.'/docker', 'PINT_PHP' => $php, 'APP_SERVICE' => false],
        );
        $processus->setTimeout(120);
        $processus->run();

        return [
            'code' => $processus->getExitCode(),
            'sortie' => $processus->getOutput(),
            'erreurs' => $processus->getErrorOutput(),
            'docker' => File::exists($dossier.'/docker.log') ? File::get($dossier.'/docker.log') : '',
            'php' => File::exists($dossier.'/php.log') ? File::get($dossier.'/php.log') : '',
        ];
    } finally {
        File::deleteDirectory($dossier);
    }
}

/**
 * La racine du dépôt, liens résolus, comme lint-staged la voit.
 */
function crochetRacineReelle(): string
{
    $racine = realpath(base_path());
    assert(is_string($racine));

    return $racine;
}

/**
 * Un fichier du dépôt, par son chemin absolu réel : celui que lint-staged
 * passe, et que le script doit ramener à la racine.
 */
function crochetCheminAbsolu(string $relatif): string
{
    return crochetRacineReelle().'/'.$relatif;
}

it('fait passer la règle PHP de lint-staged par le script, jamais par ./vendor/bin/pint directement', function (): void {
    $regles = crochetReglesDeLintStaged();
    $reglesPhp = array_filter($regles, fn (string $motif): bool => str_contains($motif, 'php'), ARRAY_FILTER_USE_KEY);

    expect($reglesPhp)->not->toBeEmpty('lint-staged ne formate plus aucun fichier PHP.')
        ->and(File::get(base_path('.husky/pre-commit')))->toContain('lint-staged');

    foreach ($regles as $motif => $commandes) {
        foreach ($commandes as $commande) {
            expect($commande)->not->toContain('vendor/bin/pint', sprintf(
                "lint-staged lance « %s » sur %s : Pint y tourne avec le PHP de l'hôte, qui peut être trop ancien pour le code du projet (#1928). Passer par scripts/formater-le-php.sh.",
                $commande,
                $motif,
            ));
        }
    }

    foreach ($reglesPhp as $commandes) {
        expect($commandes)->toContain('bash scripts/formater-le-php.sh');
    }
});

it('formate dans le conteneur de Sail quand il tourne, avec des chemins relatifs au dépôt', function (): void {
    $projet = crochetPhpDuProjet();
    // Hors du dépôt : un lien vers la racine rangé dedans y ferait une boucle.
    $dossier = sys_get_temp_dir().'/crochet-lien-'.uniqid();
    File::ensureDirectoryExists($dossier);
    $lien = $dossier.'/depot';
    symlink(crochetRacineReelle(), $lien);

    try {
        // Compose peut noter le chemin logique, par un lien, plutôt que le chemin réel.
        foreach ([crochetRacineReelle(), $lien] as $dossierDeSail) {
            $resultat = crochetFormater(
                [crochetCheminAbsolu('app/Models/User.php')],
                sailTourne: true,
                versionDuPhp: "{$projet['majeur']}.{$projet['mineur']}.0",
                dossierDeSail: $dossierDeSail,
            );

            expect($resultat['code'])->toBe(0, "Sail lancé depuis {$dossierDeSail} : ".$resultat['erreurs'])
                ->and($resultat['docker'])->toContain('inspect --format {{ index .Config.Labels "com.docker.compose.project.working_dir" }} c0ffee')
                ->toMatch('#^compose exec .*\./vendor/bin/pint app/Models/User\.php$#m')
                ->and($resultat['docker'])->not->toContain(crochetRacineReelle())
                // Le PHP de l'hôte suffirait ici : Sail passe quand même en premier.
                ->and($resultat['php'])->toBe('', "Sail lancé depuis {$dossierDeSail} n'a pas été retenu.");
        }
    } finally {
        File::deleteDirectory($dossier);
    }
});

it('n’entre jamais dans le conteneur de Sail d’un autre dossier, même s’il porte le même nom', function (): void {
    $projet = crochetPhpDuProjet();
    $ancien = $projet['mineur'] > 0
        ? sprintf('%d.%d.99', $projet['majeur'], $projet['mineur'] - 1)
        : sprintf('%d.99.99', $projet['majeur'] - 1);
    $dossier = storage_path('framework/testing/crochet-autre-'.uniqid());
    $homonyme = $dossier.'/'.basename(crochetRacineReelle());
    File::ensureDirectoryExists($homonyme);

    try {
        foreach (['une autre copie du même nom' => $homonyme, 'un dossier absent de l’hôte' => $dossier.'/absent', 'aucun dossier' => ''] as $cas => $dossierDeSail) {
            $assezRecent = crochetFormater([crochetCheminAbsolu('app/Models/User.php')], sailTourne: true, versionDuPhp: "{$projet['majeur']}.{$projet['mineur']}.0", dossierDeSail: $dossierDeSail);
            $tropAncien = crochetFormater([crochetCheminAbsolu('app/Models/User.php')], sailTourne: true, versionDuPhp: $ancien, dossierDeSail: $dossierDeSail);

            // Entrer dans ce conteneur formaterait l'autre copie, et le commit passerait sans formatage.
            expect($assezRecent['docker'])->not->toContain('compose exec', "Sail de {$cas} a été retenu.")
                ->and($tropAncien['docker'])->not->toContain('compose exec', "Sail de {$cas} a été retenu.")
                ->and($assezRecent['code'])->toBe(0, "{$cas} : ".$assezRecent['erreurs'])
                ->and($assezRecent['php'])->toBe("vendor/bin/pint app/Models/User.php\n", "{$cas} : le PHP de l'hôte n'a pas pris le relais.")
                ->and($tropAncien['code'])->toBe(1, "{$cas} : le commit n'a pas été refusé.")
                ->and($tropAncien['php'])->toBe('')
                ->and($tropAncien['erreurs'])->toContain('le service « laravel.test » que voit Compose tourne')
                ->toContain($dossierDeSail === '' ? 'docker inspect ne dit pas pour quel dossier' : "pour un autre dossier (« {$dossierDeSail} »)")
                ->toContain("est en {$ancien}");
        }
    } finally {
        File::deleteDirectory($dossier);
    }
});

it('formate avec le PHP de l’hôte dès qu’il atteint la borne basse de composer.json', function (): void {
    $projet = crochetPhpDuProjet();

    foreach (["{$projet['majeur']}.{$projet['mineur']}.0", ($projet['majeur'] + 1).'.0.0'] as $version) {
        $resultat = crochetFormater([crochetCheminAbsolu('app/Models/User.php')], sailTourne: false, versionDuPhp: $version);

        expect($resultat['code'])->toBe(0, "PHP {$version} : ".$resultat['erreurs'])
            ->and($resultat['php'])->toBe("vendor/bin/pint app/Models/User.php\n", "PHP {$version} n'a pas lancé Pint.")
            ->and($resultat['docker'])->not->toContain('compose exec');
    }
});

it('s’arrête en échec en disant de lancer Sail quand le PHP de l’hôte est plus ancien que le projet', function (): void {
    $projet = crochetPhpDuProjet();
    $ancien = $projet['mineur'] > 0
        ? sprintf('%d.%d.99', $projet['majeur'], $projet['mineur'] - 1)
        : sprintf('%d.99.99', $projet['majeur'] - 1);

    $resultat = crochetFormater([crochetCheminAbsolu('app/Models/User.php')], sailTourne: false, versionDuPhp: $ancien);

    // Un succès ici laisserait passer le commit sans que rien n'ait été formaté.
    expect($resultat['code'])->toBe(1)
        ->and($resultat['php'])->toBe('')
        ->and($resultat['docker'])->not->toContain('compose exec')
        ->and($resultat['erreurs'])->toContain('Sail ne tourne pas pour ce dossier (service « laravel.test »)')
        ->toContain("est en {$ancien}")
        ->toContain($projet['contrainte'])
        ->toContain('./vendor/bin/sail up -d')
        ->toContain('PINT_PHP=');
});

it('s’arrête en échec en disant de lancer Sail quand aucun PHP ne répond', function (): void {
    $resultat = crochetFormater([crochetCheminAbsolu('app/Models/User.php')], sailTourne: false, versionDuPhp: null);

    expect($resultat['code'])->toBe(1)
        ->and($resultat['erreurs'])->toContain('aucun PHP ne répond')
        ->toContain('./vendor/bin/sail up -d');
});

it('rend le code d’échec de Pint, sans jamais le changer en succès', function (bool $sailTourne): void {
    $projet = crochetPhpDuProjet();
    $resultat = crochetFormater(
        [crochetCheminAbsolu('app/Models/User.php')],
        sailTourne: $sailTourne,
        versionDuPhp: "{$projet['majeur']}.{$projet['mineur']}.0",
        codeDePint: 3,
    );

    // Pint a échoué : un succès laisserait passer le commit sans formatage.
    expect($resultat['code'])->toBe(3, 'Le script a avalé le code de sortie de Pint. '.$resultat['erreurs'])
        ->and($sailTourne ? $resultat['docker'] : $resultat['php'])->toContain('vendor/bin/pint app/Models/User.php');
})->with([
    'dans le conteneur de Sail' => [true],
    'avec le PHP de l’hôte' => [false],
]);

it('refuse vraiment le commit quand Pint ne peut pas lire un fichier', function (): void {
    $dossier = storage_path('framework/testing/crochet-pint-'.uniqid());
    File::ensureDirectoryExists($dossier);
    $fichier = $dossier.'/Illisible.php';
    File::put($fichier, "<?php\n\nfunction (\n");

    try {
        $resultat = crochetFormater([$fichier], sailTourne: false, versionDuPhp: null, phpReel: true);

        expect($resultat['code'])->toBeGreaterThan(0, 'Pint a échoué sur une erreur de syntaxe, et le script a rendu un succès.')
            ->and(File::get($fichier))->toBe("<?php\n\nfunction (\n");
    } finally {
        File::deleteDirectory($dossier);
    }
});

it('formate vraiment avec un PHP assez récent', function (): void {
    $dossier = storage_path('framework/testing/crochet-pint-'.uniqid());
    File::ensureDirectoryExists($dossier);
    $fichier = $dossier.'/Exemple.php';
    File::put($fichier, "<?php\n\n\$valeurs=[1,2];\nif(\$valeurs){echo \"oui\";}\n");

    try {
        $resultat = crochetFormater([$fichier], sailTourne: false, versionDuPhp: null, phpReel: true);

        expect($resultat['code'])->toBe(0, $resultat['sortie'].$resultat['erreurs'])
            ->and(File::get($fichier))->toBe("<?php\n\n\$valeurs = [1, 2];\nif (\$valeurs) {\n    echo 'oui';\n}\n");
    } finally {
        File::deleteDirectory($dossier);
    }
});
