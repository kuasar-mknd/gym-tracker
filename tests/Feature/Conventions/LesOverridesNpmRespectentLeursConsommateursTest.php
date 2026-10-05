<?php

declare(strict_types=1);

/*
 * Une entrée d'`overrides` sans sélecteur de version s'applique à toutes les
 * copies du paquet. « brace-expansion » imposait ainsi sa majeure 5, posée
 * pour une faille (#1902), au minimatch 5 de `filelist`, qui attend ^2.0.1 :
 * brace-expansion 5 n'exporte plus de fonction par défaut, et ce minimatch
 * levait « expand is not a function » sur toute accolade. Rien ne rougissait :
 * npm installe ce qu'un override lui dit sans vérifier la plage du
 * consommateur, et la construction n'empruntait pas ce chemin.
 *
 * Une entrée vise désormais la majeure fautive (`brace-expansion@5`,
 * `nanoid@3`), et ces gardes lisent package.json et package-lock.json comme npm
 * et Node les lisent : chaque consommateur d'un paquet visé doit charger une
 * copie qui tient dans la plage qu'il déclare.
 */

/**
 * Une version npm découpée : majeure, mineure, correctif et identifiants de
 * préversion. Null si le texte n'est pas une version complète.
 *
 * @return array{0: int, 1: int, 2: int, 3: list<string>}|null
 */
function versionNpmDecoupee(string $texte): ?array
{
    if (preg_match('/^v?(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/', trim($texte), $morceaux) !== 1) {
        return null;
    }

    return [(int) $morceaux[1], (int) $morceaux[2], (int) $morceaux[3], ($morceaux[4] ?? '') === '' ? [] : explode('.', $morceaux[4])];
}

/**
 * Compare deux versions npm comme node-semver : une préversion précède sa
 * version, et ses identifiants numériques précèdent les autres.
 *
 * @param  array{0: int, 1: int, 2: int, 3: list<string>}  $gauche
 * @param  array{0: int, 1: int, 2: int, 3: list<string>}  $droite
 */
function versionsNpmComparees(array $gauche, array $droite): int
{
    $noyau = [$gauche[0], $gauche[1], $gauche[2]] <=> [$droite[0], $droite[1], $droite[2]];

    if ($noyau !== 0 || $gauche[3] === $droite[3]) {
        return $noyau;
    }

    if ($gauche[3] === [] || $droite[3] === []) {
        return $gauche[3] === [] ? 1 : -1;
    }

    foreach ($gauche[3] as $rang => $identifiant) {
        if (! array_key_exists($rang, $droite[3])) {
            return 1;
        }

        $autre = $droite[3][$rang];
        $comparaison = match (true) {
            ctype_digit($identifiant) && ctype_digit($autre) => (int) $identifiant <=> (int) $autre,
            ctype_digit($identifiant) => -1,
            ctype_digit($autre) => 1,
            default => strcmp($identifiant, $autre) <=> 0,
        };

        if ($comparaison !== 0) {
            return $comparaison;
        }
    }

    return -1;
}

/**
 * Les comparateurs élémentaires d'un comparateur de plage npm (`^1.2`, `~1`,
 * `1.x`, `>1.2`, `<=2`…), désucrés comme le fait node-semver : une liste vide
 * admet toute version publiée, et `-0` borne une plage avant les préversions
 * de sa borne haute.
 *
 * @return list<array{0: string, 1: array{0: int, 1: int, 2: int, 3: list<string>}}>
 */
function comparateursNpmDuTexte(string $operateur, string $texte): array
{
    if (preg_match('/^v?(\d+|[xX*])(?:\.(\d+|[xX*]))?(?:\.(\d+|[xX*]))?(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/', $texte, $morceaux) !== 1) {
        throw new InvalidArgumentException("comparateur npm illisible : {$operateur}{$texte}");
    }

    $parties = [];

    foreach ([1, 2, 3] as $rang) {
        $partie = $morceaux[$rang] ?? '';
        $parties[] = $partie === '' || ! ctype_digit($partie) || in_array(null, $parties, true) ? null : (int) $partie;
    }

    [$majeure, $mineure, $correctif] = $parties;
    $preversion = ($morceaux[4] ?? '') === '' ? [] : explode('.', $morceaux[4]);
    $rien = [['<', [0, 0, 0, ['0']]]];

    if ($majeure === null) {
        return in_array($operateur, ['<', '>'], true) ? $rien : [];
    }

    if ($mineure !== null && $correctif !== null) {
        $version = [$majeure, $mineure, $correctif, $preversion];

        return match ($operateur) {
            '^' => [['>=', $version], ['<', match (true) {
                $majeure > 0 => [$majeure + 1, 0, 0, ['0']],
                $mineure > 0 => [0, $mineure + 1, 0, ['0']],
                default => [0, 0, $correctif + 1, ['0']],
            }]],
            '~' => [['>=', $version], ['<', [$majeure, $mineure + 1, 0, ['0']]]],
            '', '=' => [['=', $version]],
            default => [[$operateur, $version]],
        };
    }

    $plancher = [$majeure, $mineure ?? 0, 0, []];
    $suivante = $mineure === null || ($operateur === '^' && $majeure > 0)
        ? [$majeure + 1, 0, 0, ['0']]
        : [$majeure, $mineure + 1, 0, ['0']];

    return match ($operateur) {
        '>' => [['>=', [$suivante[0], $suivante[1], 0, []]]],
        '>=' => [['>=', $plancher]],
        '<' => [['<', [$majeure, $mineure ?? 0, 0, ['0']]]],
        '<=' => [['<', $suivante]],
        default => [['>=', $plancher], ['<', $suivante]],
    };
}

/**
 * Vrai quand la version publiée tient dans la plage npm, selon node-semver :
 * `||`, plages à tiret, `^`, `~`, `x` et versions partielles, et une préversion
 * n'entre que dans une plage qui en nomme une du même correctif.
 */
function plageNpmAdmet(string $version, string $plage): bool
{
    $candidate = versionNpmDecoupee($version) ?? throw new InvalidArgumentException("version npm illisible : {$version}");

    $ensembles = preg_split('/\s*\|\|\s*/', trim($plage));

    foreach ($ensembles === false ? [] : $ensembles as $ensemble) {
        if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $ensemble, $bornes) === 1) {
            $haute = versionNpmDecoupee($bornes[2]);
            $comparateurs = [
                ...comparateursNpmDuTexte('>=', $bornes[1]),
                ...($haute === null ? comparateursNpmDuTexte('<=', $bornes[2]) : [['<=', $haute]]),
            ];
        } else {
            $comparateurs = [];
            $jetons = preg_split('/\s+/', (string) preg_replace('/(<=|>=|<|>|=|\^|~>?)\s+/', '$1', $ensemble), -1, PREG_SPLIT_NO_EMPTY);

            foreach ($jetons === false ? [] : $jetons as $jeton) {
                preg_match('/^(<=|>=|<|>|=|\^|~>?)?(.*)$/', $jeton, $morceaux);
                $operateur = ($morceaux[1] ?? '') === '~>' ? '~' : $morceaux[1] ?? '';
                $comparateurs = [...$comparateurs, ...comparateursNpmDuTexte($operateur, $morceaux[2] ?? '')];
            }
        }

        $admise = array_all($comparateurs, function (array $comparateur) use ($candidate): bool {
            $ecart = versionsNpmComparees($candidate, $comparateur[1]);

            return match ($comparateur[0]) {
                '<' => $ecart < 0,
                '<=' => $ecart <= 0,
                '>' => $ecart > 0,
                '>=' => $ecart >= 0,
                default => $ecart === 0,
            };
        });

        $preversionAdmise = $candidate[3] === [] || array_any(
            $comparateurs,
            fn (array $comparateur): bool => $comparateur[1][3] !== [] && array_slice($comparateur[1], 0, 3) === array_slice($candidate, 0, 3),
        );

        if ($admise && $preversionAdmise) {
            return true;
        }
    }

    return false;
}

/**
 * Le nom du paquet que vise une clé d'`overrides`, sans son sélecteur de
 * version (`brace-expansion@5` → `brace-expansion`, `@scope/nom@2` →
 * `@scope/nom`).
 */
function overrideNpmPaquetDeLaCle(string $cle): string
{
    $arobase = strrpos($cle, '@');

    return $arobase === false || $arobase === 0 ? $cle : substr($cle, 0, $arobase);
}

/**
 * Les paquets que visent les entrées d'`overrides`, imbriquées comprises.
 *
 * @param  array<mixed>  $overrides
 * @return list<string>
 */
function overridesNpmPaquetsVises(array $overrides): array
{
    $paquets = [];

    foreach ($overrides as $cle => $valeur) {
        if ($cle !== '.') {
            $paquets[] = overrideNpmPaquetDeLaCle((string) $cle);
        }

        if (is_array($valeur)) {
            $paquets = [...$paquets, ...overridesNpmPaquetsVises($valeur)];
        }
    }

    return array_values(array_unique($paquets));
}

/**
 * Les entrées d'`overrides` qui ne visent pas une seule majeure : sans
 * sélecteur `@N`, ou qui imposent une version hors de cette majeure.
 *
 * @param  array<mixed>  $overrides
 * @return list<string>
 */
function overridesNpmSansMajeure(array $overrides): array
{
    $fautives = [];

    foreach ($overrides as $cle => $valeur) {
        $cle = (string) $cle;

        if (preg_match('/^(?:@[^\/@\s]+\/)?[^@\/\s]+@(\d+)$/', $cle, $selecteur) !== 1) {
            $fautives[] = "« {$cle} » vise toutes les majeures : écrire « {$cle}@N », N la majeure fautive";
        } elseif (! is_string($valeur) || preg_match('/^[\^~]?(\d+)\.\d+\.\d+$/', $valeur, $imposee) !== 1 || $imposee[1] !== $selecteur[1]) {
            $fautives[] = "« {$cle} » doit imposer une version de sa majeure {$selecteur[1]} (^{$selecteur[1]}.x.y)";
        }
    }

    return $fautives;
}

/**
 * La copie de `$paquet` que charge le paquet installé à `$chemin`, selon la
 * résolution de Node : son propre node_modules, puis celui de chaque parent,
 * jusqu'à la racine.
 *
 * @param  array<string, array<mixed>>  $installes  les entrées `packages` du verrou
 */
function overrideNpmCopieChargee(array $installes, string $chemin, string $paquet): ?string
{
    $dossier = $chemin;

    while (true) {
        $candidat = ($dossier === '' ? '' : "{$dossier}/")."node_modules/{$paquet}";

        if (array_key_exists($candidat, $installes)) {
            return $candidat;
        }

        if ($dossier === '') {
            return null;
        }

        $coupure = strrpos($dossier, '/node_modules/');
        $dossier = $coupure === false ? '' : substr($dossier, 0, $coupure);
    }
}

/**
 * Chaque consommateur d'un paquet visé qui charge une copie hors de la plage
 * qu'il déclare, et le nombre de consommateurs vérifiés par paquet. Une
 * dépendance optionnelle ou paire absente de l'installation, et une source qui
 * n'est pas une plage (`npm:`, `file:`, `git+…`), sont laissées de côté.
 *
 * @param  array<string, array<mixed>>  $installes  les entrées `packages` du verrou
 * @param  list<string>  $paquets
 * @return array{verifies: array<string, int>, ecarts: list<string>}
 */
function overridesNpmEcartsDesConsommateurs(array $installes, array $paquets): array
{
    $verifies = [];
    $ecarts = [];

    foreach ($installes as $chemin => $entree) {
        $chemin = (string) $chemin;
        $sections = ['dependencies', 'optionalDependencies', 'peerDependencies', ...($chemin === '' ? ['devDependencies'] : [])];

        foreach ($sections as $section) {
            $declarees = $entree[$section] ?? [];

            foreach (is_array($declarees) ? $declarees : [] as $paquet => $plage) {
                $paquet = (string) $paquet;

                if (! in_array($paquet, $paquets, true) || ! is_string($plage) || preg_match('/[:\/]/', $plage) === 1) {
                    continue;
                }

                $copie = overrideNpmCopieChargee($installes, $chemin, $paquet);

                if ($copie === null) {
                    continue;
                }

                $version = $installes[$copie]['version'] ?? null;
                $verifies[$paquet] = ($verifies[$paquet] ?? 0) + 1;

                if (! is_string($version) || ! plageNpmAdmet($version, $plage)) {
                    $consommateur = $chemin === '' ? 'le projet' : $chemin;
                    $ecarts[] = "{$consommateur} charge {$paquet} ".(is_string($version) ? $version : '(sans version)')." ({$copie}) mais attend {$plage}";
                }
            }
        }
    }

    return ['verifies' => $verifies, 'ecarts' => $ecarts];
}

/**
 * Les `overrides` de package.json et les paquets installés de
 * package-lock.json.
 *
 * @return array{overrides: array<mixed>, installes: array<string, array<mixed>>}
 */
function overridesNpmDuDepot(): array
{
    $manifeste = json_decode((string) file_get_contents(base_path('package.json')), true, 512, JSON_THROW_ON_ERROR);
    $verrou = json_decode((string) file_get_contents(base_path('package-lock.json')), true, 512, JSON_THROW_ON_ERROR);
    $overrides = is_array($manifeste) ? ($manifeste['overrides'] ?? []) : [];
    $installes = is_array($verrou) ? ($verrou['packages'] ?? []) : [];

    /** @var array<string, array<mixed>> $installes */
    $installes = array_filter(is_array($installes) ? $installes : [], is_array(...));

    return ['overrides' => is_array($overrides) ? $overrides : [], 'installes' => $installes];
}

it('ne fait charger à aucun consommateur une copie hors de sa plage', function (): void {
    ['overrides' => $overrides, 'installes' => $installes] = overridesNpmDuDepot();
    $paquets = overridesNpmPaquetsVises($overrides);
    $resultat = overridesNpmEcartsDesConsommateurs($installes, $paquets);
    $sansConsommateur = array_values(array_diff($paquets, array_keys($resultat['verifies'])));

    expect($sansConsommateur)->toBe([], 'Aucun paquet installé ne dépend plus de ces paquets visés : retirer leur override ('.implode(', ', $sansConsommateur).')')
        ->and($resultat['ecarts'])->toBe([], "Un override impose à ces consommateurs une version hors de leur plage :\n- ".implode("\n- ", $resultat['ecarts']));
});

it('vise une seule majeure par entrée d’overrides', function (): void {
    $fautives = overridesNpmSansMajeure(overridesNpmDuDepot()['overrides']);

    expect($fautives)->toBe([], implode("\n", $fautives));
});

it('aurait refusé l’override global qui cassait le minimatch de filelist', function (): void {
    $installes = [
        '' => ['devDependencies' => ['eslint' => '^10.11.0', 'vite-plugin-pwa' => '^2.0.0']],
        'node_modules/minimatch' => ['version' => '10.2.6', 'dependencies' => ['brace-expansion' => '^5.0.8']],
        'node_modules/brace-expansion' => ['version' => '5.0.12'],
        'node_modules/filelist' => ['version' => '1.0.6', 'dependencies' => ['minimatch' => '^5.0.1']],
        'node_modules/filelist/node_modules/minimatch' => ['version' => '5.1.9', 'dependencies' => ['brace-expansion' => '^2.0.1']],
    ];

    expect(overridesNpmEcartsDesConsommateurs($installes, ['brace-expansion']))->toBe([
        'verifies' => ['brace-expansion' => 2],
        'ecarts' => ['node_modules/filelist/node_modules/minimatch charge brace-expansion 5.0.12 (node_modules/brace-expansion) mais attend ^2.0.1'],
    ])
        ->and(overridesNpmSansMajeure(['brace-expansion' => '^5.0.12']))->toHaveCount(1);

    $installes['node_modules/filelist/node_modules/brace-expansion'] = ['version' => '2.1.7'];

    expect(overridesNpmEcartsDesConsommateurs($installes, ['brace-expansion'])['ecarts'])->toBe([])
        ->and(overridesNpmSansMajeure(['brace-expansion@5' => '^5.0.12']))->toBe([]);
});

it('lit les clés d’overrides comme npm', function (array $overrides, array $paquets, int $fautives): void {
    expect(overridesNpmPaquetsVises($overrides))->toBe($paquets)
        ->and(overridesNpmSansMajeure($overrides))->toHaveCount($fautives);
})->with([
    'une majeure visée' => [['nanoid@3' => '^3.3.18'], ['nanoid'], 0],
    'un paquet à portée' => [['@scope/nom@2' => '~2.1.0'], ['@scope/nom'], 0],
    'toutes les majeures' => [['serialize-javascript' => '^7.1.0'], ['serialize-javascript'], 1],
    'un paquet à portée sans majeure' => [['@scope/nom' => '^2.1.0'], ['@scope/nom'], 1],
    'une version hors de la majeure visée' => [['brace-expansion@5' => '^6.0.0'], ['brace-expansion'], 1],
    'une entrée imbriquée' => [['workbox-build@7' => ['.' => '7.4.1', 'glob@11' => '^11.1.0']], ['workbox-build', 'glob'], 1],
]);

it('juge une plage npm comme node-semver', function (string $version, string $plage, bool $admise): void {
    expect(plageNpmAdmet($version, $plage))->toBe($admise);
})->with([
    ['2.1.7', '^2.0.1', true],
    ['5.0.12', '^2.0.1', false],
    ['5.0.12', '^5.0.8', true],
    ['1.2.9', '~1.2', true],
    ['1.3.0', '~1.2', false],
    ['1.9.0', '~1', true],
    ['2.0.0', '~1', false],
    ['1.3.0', '~1.2.3', false],
    ['0.2.5', '^0.2.3', true],
    ['0.3.0', '^0.2.3', false],
    ['0.0.4', '^0.0.3', false],
    ['1.5.0', '1.x', true],
    ['2.0.0', '1', false],
    ['1.2.7', '1.2', true],
    ['1.3.0', '1.2', false],
    ['3.0.0', '>=2.1.2 <3.0.0', false],
    ['2.5.0', '>= 2.1.2 < 3.0.0', true],
    ['1.3.0', '>1.2', true],
    ['1.2.9', '>1.2', false],
    ['1.2.9', '<=1.2', true],
    ['1.3.0', '<=1.2', false],
    ['1.2.0', '<1.2', false],
    ['2.3.9', '1.2.3 - 2.3', true],
    ['2.4.0', '1.2.3 - 2.3', false],
    ['1.2.2', '1.2.3 - 2.3', false],
    ['2.3.4', '1.2.3 - 2.3.4', true],
    ['4.0.0', '^2 || ^4', true],
    ['3.0.0', '^2 || ^4', false],
    ['7.1.2', '*', true],
    ['7.1.2', '', true],
    ['3.0.0-pre1', '^3.0.0', false],
    ['3.0.0-pre2', '^3.0.0-pre1', true],
    ['3.1.0-beta', '^3.0.0-pre1', false],
    ['3.0.0-pre1', '*', false],
    ['1.2.4', '1.2.3', false],
    ['1.2.3', 'v1.2.3', true],
    ['2.0.0', '^1.2.3-beta.2', false],
    ['1.2.3-beta.10', '^1.2.3-beta.2', true],
    ['0.0.1', '>*', false],
    ['2.0.0', '~>1.2', false],
    ['1.2.5', '~>1.2', true],
]);
