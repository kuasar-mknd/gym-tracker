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
 * copie qui tient dans la plage qu'il déclare, ou une version plus récente de
 * la même majeure. Cette seconde forme est celle d'un plancher relevé pour une
 * faille chez un consommateur qui fige la version exacte : concurrently, à sa
 * dernière version, déclare `shell-quote` « 1.9.0 », et seul un override sert
 * la 1.12.0 corrigée.
 *
 * Une majeure fautive sans correctif publié ne se corrige qu'en changeant de
 * majeure : minimatch 10 et glob 11 et 13 n'en ont pas, et leurs consommateurs
 * n'acceptent pas encore minimatch 11 ni glob 14. Au 2026-11-06, glob 13.0.7
 * reporte le correctif sur la 13, mais l'avis ne nomme que 14.0.2 : OSV et
 * `avisNpmTenusSurLeVerrou()` la tiennent pour vulnérable, et l'override reste
 * en 14 jusqu'à la mise à jour de l'avis. Un tel override n'est admis
 * que relu : chaque consommateur qui charge l'autre majeure est inscrit, à sa
 * version, dans `overridesNpmHorsDeLeurMajeureRelus()`, après relecture de ce
 * qu'il appelle contre l'API de la majeure imposée. Un consommateur absent de
 * la liste, ou monté à une autre version, refait cette relecture avant d'y
 * entrer. Ces gardes tiennent aussi les avis que ces overrides corrigent : le
 * verrou ne garde aucune copie qu'ils visent, sauf celle d'un avis sans
 * correctif publié, sous ses seuls consommateurs relus.
 */

/**
 * Les overrides qui changent de majeure, faute de correctif publié sur la
 * majeure fautive, et chaque consommateur relu contre la majeure qu'ils
 * imposent, à sa version.
 *
 * Relus le 2026-11-05, à ces versions, contre minimatch 11.0.4 et glob 14.0.3.
 * ESLint et @eslint/config-array ne construisent que `new Minimatch(motif,
 * options)` (`dot`, `flipNegate`) et n'appellent que `match()` et `pattern` ;
 * editorconfig y ajoute `matchBase` et `escape()` avec `windowsPathsNoEscape`.
 * workbox-build n'appelle que `globSync()` (`cwd`, `follow`, `ignore`, des
 * motifs sans `/` de tête), et js-beautify, dans sa seule ligne de commande,
 * `globSync()` et `hasMagic()`. Tout cela existe à l'identique dans la majeure
 * imposée ; ce que minimatch 11 retire (`makeRe()`, `AST`, `debug`,
 * `matchOne()`…) et ce que glob 14 change (ses classes internes, les motifs
 * d'`ignore` qui commencent par `/`), aucun ne s'en sert. ESLint 10.14.0,
 * relu le 2026-11-06 : `lib/eslint/eslint-helpers.js`, seul fichier qui prend
 * minimatch, est identique à celui de 10.13.0, et ESLint rend les mêmes
 * fichiers et les mêmes messages sur le dépôt.
 *
 * @return array<string, array{majeure: int, consommateurs: list<string>}>
 */
function overridesNpmHorsDeLeurMajeureRelus(): array
{
    return [
        'minimatch@10' => ['majeure' => 11, 'consommateurs' => ['@eslint/config-array@0.23.5', 'editorconfig@3.0.2', 'eslint@10.14.0']],
        'glob@11' => ['majeure' => 14, 'consommateurs' => ['workbox-build@7.4.1']],
        'glob@13' => ['majeure' => 14, 'consommateurs' => ['js-beautify@2.0.3']],
    ];
}

/**
 * Les avis de sécurité que le verrou tient : la plage vulnérable du paquet
 * visé et, pour un avis sans version corrigée publiée, les consommateurs
 * relus sous lesquels sa copie vulnérable reste admise, à leur version.
 *
 * deepmerge n'a aucune version corrigée de GHSA-gcqx-w48m-w2h4. Son seul
 * consommateur, @rollup/plugin-node-resolve, sous workbox-build, ne l'appelle
 * qu'une fois, au chargement, sur une constante écrite dans son propre code ;
 * le premier correctif publié le fera partir, et cette admission avec lui.
 *
 * @return array<string, array{avis: string, vulnerable: string, admisSous: list<string>}>
 */
function avisNpmTenusSurLeVerrou(): array
{
    return [
        'minimatch' => ['avis' => 'GHSA-g3hv-75wg-m249', 'vulnerable' => '>=7.0.0 <11.0.1', 'admisSous' => []],
        'glob' => ['avis' => 'GHSA-3g3m-2fjr-6pw4', 'vulnerable' => '>=9.0.0 <=14.0.1', 'admisSous' => []],
        'deepmerge' => ['avis' => 'GHSA-gcqx-w48m-w2h4', 'vulnerable' => '>=4.2.0 <=4.3.1', 'admisSous' => ['@rollup/plugin-node-resolve@16.0.3']],
    ];
}

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
 * Les ensembles de comparateurs élémentaires d'une plage npm, un par membre de
 * `||`, plages à tiret comprises.
 *
 * @return list<list<array{0: string, 1: array{0: int, 1: int, 2: int, 3: list<string>}}>>
 */
function plageNpmEnsembles(string $plage): array
{
    $resultat = [];
    $ensembles = preg_split('/\s*\|\|\s*/', trim($plage));

    foreach ($ensembles === false ? [] : $ensembles as $ensemble) {
        if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $ensemble, $bornes) === 1) {
            $haute = versionNpmDecoupee($bornes[2]);
            $resultat[] = [
                ...comparateursNpmDuTexte('>=', $bornes[1]),
                ...($haute === null ? comparateursNpmDuTexte('<=', $bornes[2]) : [['<=', $haute]]),
            ];

            continue;
        }

        $comparateurs = [];
        $jetons = preg_split('/\s+/', (string) preg_replace('/(<=|>=|<|>|=|\^|~>?)\s+/', '$1', $ensemble), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($jetons === false ? [] : $jetons as $jeton) {
            preg_match('/^(<=|>=|<|>|=|\^|~>?)?(.*)$/', $jeton, $morceaux);
            $operateur = ($morceaux[1] ?? '') === '~>' ? '~' : $morceaux[1] ?? '';
            $comparateurs = [...$comparateurs, ...comparateursNpmDuTexte($operateur, $morceaux[2] ?? '')];
        }

        $resultat[] = $comparateurs;
    }

    return $resultat;
}

/**
 * Vrai quand la version publiée tient dans la plage npm, selon node-semver :
 * `||`, plages à tiret, `^`, `~`, `x` et versions partielles, et une préversion
 * n'entre que dans une plage qui en nomme une du même correctif.
 */
function plageNpmAdmet(string $version, string $plage): bool
{
    $candidate = versionNpmDecoupee($version) ?? throw new InvalidArgumentException("version npm illisible : {$version}");

    foreach (plageNpmEnsembles($plage) as $comparateurs) {
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
 * La majeure d'une version au sens de `^` : son premier nombre non nul, si bien
 * que 0.2 et 0.3 sont deux majeures, comme 0.0.3 et 0.0.4.
 *
 * @param  array{0: int, 1: int, 2: int, 3: list<string>}  $version
 * @return list<int>
 */
function versionNpmMajeure(array $version): array
{
    return match (true) {
        $version[0] > 0 => [$version[0]],
        $version[1] > 0 => [0, $version[1]],
        default => [0, 0, $version[2]],
    };
}

/**
 * La plus haute version publiée qu'admet `< $borne`, PHP_INT_MAX tenant lieu
 * d'un nombre sans limite (`< 3.0.0-0` admet jusqu'à 2.∞.∞). Null quand
 * aucune version ne passe sous la borne.
 *
 * @param  array{0: int, 1: int, 2: int, 3: list<string>}  $borne
 * @return array{0: int, 1: int, 2: int, 3: list<string>}|null
 */
function versionNpmSousLaBorne(array $borne): ?array
{
    [$majeure, $mineure, $correctif] = $borne;

    return match (true) {
        $correctif > 0 => [$majeure, $mineure, $correctif - 1, []],
        $mineure > 0 => [$majeure, $mineure - 1, PHP_INT_MAX, []],
        $majeure > 0 => [$majeure - 1, PHP_INT_MAX, PHP_INT_MAX, []],
        default => null,
    };
}

/**
 * Vrai quand une version hors de la plage la dépasse sans quitter la majeure de
 * sa plus haute version admise : c'est un plancher relevé pour une faille chez
 * un consommateur qui fige sa dépendance (concurrently déclare `shell-quote`
 * « 1.9.0 », l'override sert la 1.12.0), et ce consommateur la lit comme la
 * sienne. Une autre majeure, une version plus ancienne que la plage, une
 * préversion ou une plage sans plafond ne le sont pas.
 */
function plageNpmDepasseeDansSaMajeure(string $version, string $plage): bool
{
    $candidate = versionNpmDecoupee($version) ?? throw new InvalidArgumentException("version npm illisible : {$version}");

    if ($candidate[3] !== [] || plageNpmAdmet($version, $plage)) {
        return false;
    }

    foreach (plageNpmEnsembles($plage) as $comparateurs) {
        $plafonds = array_values(array_filter(
            $comparateurs,
            fn (array $comparateur): bool => in_array($comparateur[0], ['<', '<=', '='], true),
        ));

        usort($plafonds, function (array $gauche, array $droite): int {
            $ecart = versionsNpmComparees($gauche[1], $droite[1]);

            return $ecart !== 0 ? $ecart : ($gauche[0] === '<' ? 0 : 1) <=> ($droite[0] === '<' ? 0 : 1);
        });

        if ($plafonds === []) {
            continue;
        }

        [$operateur, $borne] = $plafonds[0];
        $ecart = versionsNpmComparees($candidate, $borne);
        $auDessus = $operateur === '<' ? $ecart >= 0 : $ecart > 0;
        $plusHaute = $operateur === '<' ? versionNpmSousLaBorne($borne) : $borne;

        if ($auDessus && $plusHaute !== null && versionNpmMajeure($plusHaute) === versionNpmMajeure($candidate)) {
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
 * Le nom du paquet installé à `$chemin` (`node_modules/workbox-build/node_modules/glob`
 * → `glob`, `node_modules/@eslint/config-array` → `@eslint/config-array`).
 * Vide pour le projet lui-même.
 */
function overrideNpmPaquetDuChemin(string $chemin): string
{
    $coupure = strrpos($chemin, 'node_modules/');

    return $coupure === false ? '' : substr($chemin, $coupure + strlen('node_modules/'));
}

/**
 * Le paquet installé à `$chemin`, nommé avec sa version comme dans les listes
 * relues (`@eslint/config-array@0.23.5`). Null pour le projet lui-même.
 *
 * @param  array<mixed>  $entree  l'entrée du verrou à ce chemin
 */
function overrideNpmConsommateurNomme(string $chemin, array $entree): ?string
{
    $paquet = overrideNpmPaquetDuChemin($chemin);
    $version = $entree['version'] ?? null;

    return $paquet === '' || ! is_string($version) ? null : "{$paquet}@{$version}";
}

/**
 * Vrai quand le paquet installé, ou le projet, déclare `$paquet` dans l'une
 * des sections que npm installe pour lui.
 *
 * @param  array<mixed>  $entree  l'entrée du verrou du consommateur
 */
function overrideNpmDeclare(array $entree, string $chemin, string $paquet): bool
{
    $sections = ['dependencies', 'optionalDependencies', 'peerDependencies', ...($chemin === '' ? ['devDependencies'] : [])];

    foreach ($sections as $section) {
        $declarees = $entree[$section] ?? null;

        if (is_array($declarees) && array_key_exists($paquet, $declarees)) {
            return true;
        }
    }

    return false;
}

/**
 * Vrai quand le consommateur installé à `$chemin` est inscrit, à sa version,
 * parmi les consommateurs relus d'un override de `$paquet` dont la majeure
 * imposée est celle de la copie qu'il charge.
 *
 * @param  array<string, array{majeure: int, consommateurs: list<string>}>  $relus
 * @param  array<mixed>  $entree  l'entrée du verrou du consommateur
 */
function overrideNpmConsommateurRelu(array $relus, string $paquet, string $chemin, array $entree, string $version): bool
{
    $chargee = versionNpmDecoupee($version);
    $consommateur = overrideNpmConsommateurNomme($chemin, $entree);

    return $chargee !== null && $consommateur !== null && array_any(
        $relus,
        fn (array $relu, string $cle): bool => overrideNpmPaquetDeLaCle($cle) === $paquet
            && $relu['majeure'] === $chargee[0]
            && in_array($consommateur, $relu['consommateurs'], true),
    );
}

/**
 * Vrai quand le paquet installé que nomme `$consommateur` (`nom@version`)
 * déclare `$paquet` et en charge une copie de `$majeure` que sa plage
 * n'admet pas : celle qu'un override lui impose.
 *
 * @param  array<string, array<mixed>>  $installes  les entrées `packages` du verrou
 */
function overrideNpmConsommateurChargeLaMajeure(array $installes, string $consommateur, string $paquet, int $majeure): bool
{
    foreach ($installes as $chemin => $entree) {
        $chemin = (string) $chemin;

        if (overrideNpmConsommateurNomme($chemin, $entree) !== $consommateur) {
            continue;
        }

        $copie = overrideNpmCopieChargee($installes, $chemin, $paquet);
        $version = $copie === null ? null : $installes[$copie]['version'] ?? null;
        $chargee = is_string($version) ? versionNpmDecoupee($version) : null;

        foreach (['dependencies', 'optionalDependencies', 'peerDependencies'] as $section) {
            $declarees = $entree[$section] ?? [];
            $plage = is_array($declarees) ? $declarees[$paquet] ?? null : null;

            if (is_string($plage) && is_string($version) && $chargee !== null && $chargee[0] === $majeure && ! plageNpmAdmet($version, $plage)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Ce qui ne tient plus dans la liste des overrides relus hors de leur
 * majeure : une entrée dont package.json n'impose plus la majeure relue, une
 * entrée sans consommateur, et un consommateur relu qui ne charge plus cette
 * majeure par l'override (il n'est plus installé à cette version, ou sa plage
 * l'admet désormais).
 *
 * @param  array<mixed>  $overrides
 * @param  array<string, array<mixed>>  $installes  les entrées `packages` du verrou
 * @param  array<string, array{majeure: int, consommateurs: list<string>}>  $relus
 * @return list<string>
 */
function overridesNpmRelusPerimes(array $overrides, array $installes, array $relus): array
{
    $perimes = [];

    foreach ($relus as $cle => $relu) {
        $paquet = overrideNpmPaquetDeLaCle($cle);
        $imposee = $overrides[$cle] ?? null;

        if (! is_string($imposee) || preg_match('/^[\^~]?(\d+)\.\d+\.\d+$/', $imposee, $morceaux) !== 1 || (int) $morceaux[1] !== $relu['majeure']) {
            $perimes[] = "« {$cle} » n'impose plus la majeure {$relu['majeure']} dans package.json : retirer son entrée relue";
        }

        if ($relu['consommateurs'] === []) {
            $perimes[] = "« {$cle} » n'a aucun consommateur relu : retirer l'override et son entrée relue";
        }

        foreach ($relu['consommateurs'] as $consommateur) {
            if (! overrideNpmConsommateurChargeLaMajeure($installes, $consommateur, $paquet, $relu['majeure'])) {
                $perimes[] = "{$consommateur} ne charge plus {$paquet} {$relu['majeure']} par « {$cle} » : le retirer de la liste relue, ou relire sa version installée avant de l'y inscrire";
            }
        }
    }

    return $perimes;
}

/**
 * Chaque copie installée que vise un avis tenu. Celle d'un avis sans
 * correctif publié passe sous ses consommateurs relus, et sous eux seuls :
 * une copie que plus aucun paquet ne charge ne passe jamais, même sous un avis
 * qui en admet. Un consommateur relu qui ne charge plus de copie vulnérable
 * est nommé aussi, son admission n'ayant plus lieu d'être.
 *
 * @param  array<string, array<mixed>>  $installes  les entrées `packages` du verrou
 * @param  array<string, array{avis: string, vulnerable: string, admisSous: list<string>}>  $avis
 * @return list<string>
 */
function avisNpmCopiesVulnerables(array $installes, array $avis): array
{
    $vulnerables = [];
    $admissionsServies = [];

    foreach ($installes as $copie => $entreeDeLaCopie) {
        $copie = (string) $copie;
        $paquet = overrideNpmPaquetDuChemin($copie);
        $version = $entreeDeLaCopie['version'] ?? null;

        if (! array_key_exists($paquet, $avis) || ! is_string($version) || ! plageNpmAdmet($version, $avis[$paquet]['vulnerable'])) {
            continue;
        }

        $consommateurs = [];

        foreach ($installes as $chemin => $entree) {
            $chemin = (string) $chemin;

            if (overrideNpmDeclare($entree, $chemin, $paquet) && overrideNpmCopieChargee($installes, $chemin, $paquet) === $copie) {
                $consommateurs[] = overrideNpmConsommateurNomme($chemin, $entree) ?? 'le projet';
            }
        }

        $horsAdmission = array_values(array_diff($consommateurs, $avis[$paquet]['admisSous']));
        $admissionsServies = [...$admissionsServies, ...array_intersect($consommateurs, $avis[$paquet]['admisSous'])];

        if ($consommateurs === [] || $horsAdmission !== []) {
            $vulnerables[] = "{$paquet} {$version} ({$copie}) tombe sous {$avis[$paquet]['avis']} ({$avis[$paquet]['vulnerable']}), chargé par ".($horsAdmission === [] ? 'aucun paquet' : implode(', ', $horsAdmission));
        }
    }

    foreach ($avis as $paquet => $avisDuPaquet) {
        foreach (array_diff($avisDuPaquet['admisSous'], $admissionsServies) as $consommateur) {
            $vulnerables[] = "{$consommateur} ne charge plus de copie de {$paquet} que vise {$avisDuPaquet['avis']} : retirer son admission";
        }
    }

    return $vulnerables;
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
 * sélecteur `@N`, ou qui imposent une version hors de cette majeure sans
 * figurer, avec la majeure qu'elles imposent, parmi les overrides relus.
 *
 * @param  array<mixed>  $overrides
 * @param  array<string, array{majeure: int, consommateurs: list<string>}>  $relus
 * @return list<string>
 */
function overridesNpmSansMajeure(array $overrides, array $relus = []): array
{
    $fautives = [];

    foreach ($overrides as $cle => $valeur) {
        $cle = (string) $cle;

        if (preg_match('/^(?:@[^\/@\s]+\/)?[^@\/\s]+@(\d+)$/', $cle, $selecteur) !== 1) {
            $fautives[] = "« {$cle} » vise toutes les majeures : écrire « {$cle}@N », N la majeure fautive";
        } elseif (! is_string($valeur) || preg_match('/^[\^~]?(\d+)\.\d+\.\d+$/', $valeur, $imposee) !== 1 || ($imposee[1] !== $selecteur[1] && (int) $imposee[1] !== ($relus[$cle]['majeure'] ?? null))) {
            $fautives[] = "« {$cle} » doit imposer une version de sa majeure {$selecteur[1]} (^{$selecteur[1]}.x.y), ou, sans correctif publié sur cette majeure, être relu avec chacun de ses consommateurs (overridesNpmHorsDeLeurMajeureRelus)";
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
 * qu'il déclare, et le nombre de consommateurs vérifiés par paquet. Une copie
 * plus récente que la plage, mais de la même majeure que sa plus haute version
 * admise, passe : c'est la forme d'un plancher relevé pour une faille chez un
 * consommateur qui fige sa dépendance. Une copie de la majeure qu'impose un
 * override relu passe chez les seuls consommateurs relus, à leur version. Une
 * dépendance optionnelle ou paire absente de l'installation, et une source qui
 * n'est pas une plage (`npm:`, `file:`, `git+…`), sont laissées de côté.
 *
 * @param  array<string, array<mixed>>  $installes  les entrées `packages` du verrou
 * @param  list<string>  $paquets
 * @param  array<string, array{majeure: int, consommateurs: list<string>}>  $relus
 * @return array{verifies: array<string, int>, ecarts: list<string>}
 */
function overridesNpmEcartsDesConsommateurs(array $installes, array $paquets, array $relus = []): array
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

                if (! is_string($version) || (! plageNpmAdmet($version, $plage) && ! plageNpmDepasseeDansSaMajeure($version, $plage) && ! overrideNpmConsommateurRelu($relus, $paquet, $chemin, $entree, $version))) {
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

it('ne fait charger à aucun consommateur une autre majeure ni une version plus ancienne que sa plage', function (): void {
    ['overrides' => $overrides, 'installes' => $installes] = overridesNpmDuDepot();
    $paquets = overridesNpmPaquetsVises($overrides);
    $resultat = overridesNpmEcartsDesConsommateurs($installes, $paquets, overridesNpmHorsDeLeurMajeureRelus());
    $sansConsommateur = array_values(array_diff($paquets, array_keys($resultat['verifies'])));

    expect($sansConsommateur)->toBe([], 'Aucun paquet installé ne dépend plus de ces paquets visés : retirer leur override ('.implode(', ', $sansConsommateur).')')
        ->and($resultat['ecarts'])->toBe([], "Un override impose à ces consommateurs une autre majeure, ou une version plus ancienne, que leur plage :\n- ".implode("\n- ", $resultat['ecarts']));
});

it('vise une seule majeure par entrée d’overrides', function (): void {
    $fautives = overridesNpmSansMajeure(overridesNpmDuDepot()['overrides'], overridesNpmHorsDeLeurMajeureRelus());

    expect($fautives)->toBe([], implode("\n", $fautives));
});

it('ne relit que les overrides hors de leur majeure que le verrou sert encore', function (): void {
    ['overrides' => $overrides, 'installes' => $installes] = overridesNpmDuDepot();
    $perimes = overridesNpmRelusPerimes($overrides, $installes, overridesNpmHorsDeLeurMajeureRelus());

    expect($perimes)->toBe([], "La liste des overrides relus hors de leur majeure ne tient plus :\n- ".implode("\n- ", $perimes));
});

it('ne garde au verrou aucune copie que vise un avis, sauf sans correctif publié et sous un consommateur relu', function (): void {
    $vulnerables = avisNpmCopiesVulnerables(overridesNpmDuDepot()['installes'], avisNpmTenusSurLeVerrou());

    expect($vulnerables)->toBe([], "Le verrou garde des copies que vise un avis :\n- ".implode("\n- ", $vulnerables));
});

it('n’admet un override hors de sa majeure que relu, et chez ses seuls consommateurs relus', function (): void {
    $installes = [
        '' => ['devDependencies' => ['eslint' => '^10.13.0']],
        'node_modules/eslint' => ['version' => '10.13.0', 'dependencies' => ['minimatch' => '^10.2.5']],
        'node_modules/minimatch' => ['version' => '11.0.4'],
        'node_modules/editorconfig' => ['version' => '3.0.2', 'dependencies' => ['minimatch' => '~10.2.4']],
    ];
    $relus = ['minimatch@10' => ['majeure' => 11, 'consommateurs' => ['eslint@10.13.0']]];

    expect(overridesNpmEcartsDesConsommateurs($installes, ['minimatch'], $relus))->toBe([
        'verifies' => ['minimatch' => 2],
        'ecarts' => ['node_modules/editorconfig charge minimatch 11.0.4 (node_modules/minimatch) mais attend ~10.2.4'],
    ])
        ->and(overridesNpmEcartsDesConsommateurs($installes, ['minimatch'])['ecarts'])->toHaveCount(2)
        ->and(overridesNpmSansMajeure(['minimatch@10' => '^11.0.4'], $relus))->toBe([])
        ->and(overridesNpmSansMajeure(['minimatch@10' => '^11.0.4']))->toHaveCount(1)
        ->and(overridesNpmSansMajeure(['minimatch@10' => '^12.0.0'], $relus))->toHaveCount(1)
        ->and(overridesNpmSansMajeure(['glob@13' => '^11.0.4'], $relus))->toHaveCount(1);

    $installes['node_modules/eslint']['version'] = '10.14.0';

    expect(overridesNpmEcartsDesConsommateurs($installes, ['minimatch'], $relus)['ecarts'])
        ->toContain('node_modules/eslint charge minimatch 11.0.4 (node_modules/minimatch) mais attend ^10.2.5');
});

it('nomme un override relu qui n’a plus lieu d’être', function (): void {
    $installes = [
        '' => ['devDependencies' => ['eslint' => '^10.13.0']],
        'node_modules/eslint' => ['version' => '10.13.0', 'dependencies' => ['minimatch' => '^10.2.5']],
        'node_modules/minimatch' => ['version' => '11.0.4'],
    ];
    $relus = ['minimatch@10' => ['majeure' => 11, 'consommateurs' => ['eslint@10.13.0']]];

    expect(overridesNpmRelusPerimes(['minimatch@10' => '^11.0.4'], $installes, $relus))->toBe([])
        ->and(overridesNpmRelusPerimes([], $installes, $relus))
        ->toBe(["« minimatch@10 » n'impose plus la majeure 11 dans package.json : retirer son entrée relue"])
        ->and(overridesNpmRelusPerimes(['minimatch@10' => '^11.0.4'], $installes, ['minimatch@10' => ['majeure' => 11, 'consommateurs' => []]]))
        ->toBe(["« minimatch@10 » n'a aucun consommateur relu : retirer l'override et son entrée relue"]);

    $monte = $installes;
    $monte['node_modules/eslint'] = ['version' => '10.15.0', 'dependencies' => ['minimatch' => '^10.2.5']];
    $accepte = $installes;
    $accepte['node_modules/eslint']['dependencies'] = ['minimatch' => '^11.0.1'];

    foreach ([$monte, $accepte] as $verrou) {
        expect(overridesNpmRelusPerimes(['minimatch@10' => '^11.0.4'], $verrou, $relus))
            ->toBe(["eslint@10.13.0 ne charge plus minimatch 11 par « minimatch@10 » : le retirer de la liste relue, ou relire sa version installée avant de l'y inscrire"]);
    }
});

it('n’admet une copie vulnérable sans correctif publié que sous ses consommateurs relus', function (): void {
    $installes = [
        '' => ['devDependencies' => ['vite-plugin-pwa' => '^2.0.0']],
        'node_modules/@rollup/plugin-node-resolve' => ['version' => '16.0.3', 'dependencies' => ['deepmerge' => '^4.2.2']],
        'node_modules/deepmerge' => ['version' => '4.3.1'],
        'node_modules/minimatch' => ['version' => '10.2.6'],
        'node_modules/eslint' => ['version' => '10.13.0', 'dependencies' => ['minimatch' => '^10.2.5']],
        'node_modules/filelist/node_modules/minimatch' => ['version' => '5.1.9'],
        'node_modules/filelist' => ['version' => '1.0.6', 'dependencies' => ['minimatch' => '^5.0.1']],
    ];
    $avis = [
        'minimatch' => ['avis' => 'GHSA-1', 'vulnerable' => '>=7.0.0 <11.0.1', 'admisSous' => []],
        'deepmerge' => ['avis' => 'GHSA-2', 'vulnerable' => '>=4.2.0 <=4.3.1', 'admisSous' => ['@rollup/plugin-node-resolve@16.0.3']],
    ];

    expect(avisNpmCopiesVulnerables($installes, $avis))
        ->toBe(['minimatch 10.2.6 (node_modules/minimatch) tombe sous GHSA-1 (>=7.0.0 <11.0.1), chargé par eslint@10.13.0']);

    $installes['node_modules/minimatch']['version'] = '11.0.4';
    $installes['node_modules/eslint/node_modules/deepmerge'] = ['version' => '4.3.1'];
    $installes['node_modules/eslint']['dependencies']['deepmerge'] = '^4.3.0';

    expect(avisNpmCopiesVulnerables($installes, $avis))
        ->toBe(['deepmerge 4.3.1 (node_modules/eslint/node_modules/deepmerge) tombe sous GHSA-2 (>=4.2.0 <=4.3.1), chargé par eslint@10.13.0']);

    unset($installes['node_modules/eslint/node_modules/deepmerge'], $installes['node_modules/eslint']['dependencies']['deepmerge']);

    expect(avisNpmCopiesVulnerables($installes, $avis))->toBe([]);

    $avis['glob'] = ['avis' => 'GHSA-3', 'vulnerable' => '>=9.0.0 <=14.0.1', 'admisSous' => []];
    $installes['node_modules/glob'] = ['version' => '13.0.6'];
    $installes['node_modules/workbox-build/node_modules/deepmerge'] = ['version' => '4.3.1'];

    expect(avisNpmCopiesVulnerables($installes, $avis))->toBe([
        'glob 13.0.6 (node_modules/glob) tombe sous GHSA-3 (>=9.0.0 <=14.0.1), chargé par aucun paquet',
        'deepmerge 4.3.1 (node_modules/workbox-build/node_modules/deepmerge) tombe sous GHSA-2 (>=4.2.0 <=4.3.1), chargé par aucun paquet',
    ]);

    unset($installes['node_modules/glob'], $installes['node_modules/workbox-build/node_modules/deepmerge']);
    $installes['node_modules/deepmerge']['version'] = '4.3.2';

    expect(avisNpmCopiesVulnerables($installes, $avis))
        ->toBe(['@rollup/plugin-node-resolve@16.0.3 ne charge plus de copie de deepmerge que vise GHSA-2 : retirer son admission']);
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

it('admet un plancher relevé pour une faille chez un consommateur qui fige sa version', function (): void {
    $installes = [
        '' => ['devDependencies' => ['concurrently' => '^10.0.5']],
        'node_modules/concurrently' => ['version' => '10.0.5', 'dependencies' => ['shell-quote' => '1.9.0']],
        'node_modules/shell-quote' => ['version' => '1.12.0'],
    ];

    expect(overridesNpmEcartsDesConsommateurs($installes, ['shell-quote']))->toBe([
        'verifies' => ['shell-quote' => 1],
        'ecarts' => [],
    ]);

    $installes['node_modules/shell-quote'] = ['version' => '2.0.0'];

    expect(overridesNpmEcartsDesConsommateurs($installes, ['shell-quote'])['ecarts'])
        ->toBe(['node_modules/concurrently charge shell-quote 2.0.0 (node_modules/shell-quote) mais attend 1.9.0']);
});

it('ne laisse passer hors de la plage qu’une version plus récente de sa majeure', function (string $version, string $plage, bool $admise): void {
    expect(plageNpmAdmet($version, $plage))->toBeFalse()
        ->and(plageNpmDepasseeDansSaMajeure($version, $plage))->toBe($admise);
})->with([
    'un plancher relevé sur une version figée' => ['1.12.0', '1.9.0', true],
    'une autre majeure que la version figée' => ['2.0.0', '1.9.0', false],
    'au-delà d’un ~ de la même majeure' => ['1.12.0', '~1.9.0', true],
    'au-delà d’une plage à tiret' => ['2.5.0', '1.2.3 - 2.3', true],
    'l’écart de filelist' => ['5.0.12', '^2.0.1', false],
    'la majeure suivante' => ['3.0.0', '^2.0.1', false],
    'une version plus ancienne que la plage' => ['1.2.0', '^1.5.0', false],
    'une 0.x suivante' => ['0.3.0', '^0.2.3', false],
    'une 0.0.x suivante' => ['0.0.4', '^0.0.3', false],
    'une préversion de la même majeure' => ['1.13.0-beta.1', '1.9.0', false],
    'une plage sans plafond' => ['1.2.0', '>=1.5.0', false],
    'la majeure de l’un des membres de ||' => ['4.2.0', '^2 || ~4.1.0', true],
]);

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
