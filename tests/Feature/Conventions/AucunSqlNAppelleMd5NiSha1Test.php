<?php

declare(strict_types=1);

/*
 * MySQL 9.7 ne fournit plus `md5()` ni `sha1()` (ni son alias `sha()`) en
 * fonctions natives : elles vivent dans un composant à installer, comme
 * fonctions chargeables. Sans lui, une requête qui les appelle échoue ; avec
 * lui, une colonne générée les refuse encore. L'empreinte des tables de Pulse,
 * `unhex(md5(key))`, empêchait le schéma figé de se charger sur une base neuve
 * et la mise à niveau d'une base 8.4 d'aboutir
 * (`database/migrations/2026_10_17_145842_hacher_les_cles_de_pulse_sans_md5.php`).
 *
 * Le SQL de l'application se lit dans le schéma figé et dans les chaînes du
 * code PHP (app, config, database, routes) : une chaîne qui appelle l'une de
 * ces fonctions est du SQL que 9.7 refuse. `md5()` de PHP, un appel de
 * fonction, n'est pas une chaîne, et `hash('md5', …)` n'appelle rien.
 * `SHA2()` reste native : c'est elle que prend l'empreinte de Pulse.
 */

/**
 * Vrai quand le texte appelle une fonction d'empreinte que MySQL 9.7 a
 * retirée des fonctions natives.
 */
function sqlRetireAppelleUneEmpreinteRetiree(string $texte): bool
{
    return preg_match('/\b(?:md5|sha1|sha)\s*\(/i', $texte) === 1;
}

/**
 * Les chaînes d'un source PHP qui appellent une fonction d'empreinte retirée.
 *
 * @return list<string>
 */
function sqlRetireChainesFautives(string $source): array
{
    $fautives = [];

    foreach (token_get_all($source) as $jeton) {
        if (is_array($jeton)
            && in_array($jeton[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && sqlRetireAppelleUneEmpreinteRetiree($jeton[1])) {
            $fautives[] = $jeton[1];
        }
    }

    return $fautives;
}

/**
 * Les fichiers PHP de l'application qui portent du SQL.
 *
 * @return list<string>
 */
function sqlRetireFichiersPhp(): array
{
    $fichiers = [];

    foreach (['app', 'config', 'database', 'routes'] as $dossier) {
        $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dossier), FilesystemIterator::SKIP_DOTS));

        foreach ($iterateur as $fichier) {
            if ($fichier instanceof SplFileInfo && $fichier->getExtension() === 'php') {
                $fichiers[] = $fichier->getPathname();
            }
        }
    }

    sort($fichiers);

    return $fichiers;
}

it('charge un schéma figé sans md5, sha1 ni sha', function (): void {
    $schema = (string) file_get_contents(base_path('database/schema/mysql-schema.sql'));
    $lignes = array_values(array_filter(
        explode("\n", $schema),
        sqlRetireAppelleUneEmpreinteRetiree(...),
    ));

    expect($schema)->toContain('CREATE TABLE `pulse_values`')
        ->and($lignes)->toBe([], "MySQL 9.7 refuse ces lignes du schéma figé :\n".implode("\n", $lignes));
});

it('n’écrit aucune chaîne SQL qui appelle md5, sha1 ou sha', function (): void {
    $fichiers = sqlRetireFichiersPhp();
    $fautes = [];

    foreach ($fichiers as $chemin) {
        foreach (sqlRetireChainesFautives((string) file_get_contents($chemin)) as $chaine) {
            $fautes[] = str_replace(base_path().'/', '', $chemin).' : '.$chaine;
        }
    }

    expect(count($fichiers))->toBeGreaterThan(100)
        ->and($fautes)->toBe([], "Ces chaînes appellent une fonction que MySQL 9.7 ne fournit plus nativement (SHA2() la remplace) :\n".implode("\n", $fautes));
});

it('reconnaît une empreinte retirée dans une chaîne, et seulement là', function (string $source, bool $fautive): void {
    expect(sqlRetireChainesFautives("<?php\n".$source) !== [])->toBe($fautive);
})->with([
    'la colonne générée de Pulse' => ['$table->char("key_hash", 16)->virtualAs(\'unhex(md5(`key`))\');', true],
    'une requête brute en capitales' => ['DB::select("SELECT MD5(email) FROM users");', true],
    'sha1 avec un espace' => ['DB::raw(\'sha1 (token)\');', true],
    'l’alias sha' => ['DB::raw(\'SHA(token)\');', true],
    'une chaîne interpolée' => ['DB::statement("select md5({$colonne}) from t");', true],
    'l’empreinte SHA-256' => ['->virtualAs(\'unhex(left(sha2(`key`,256),32))\');', false],
    'md5 de PHP' => ['$empreinte = md5($cle);', false],
    'hash par son nom' => ['$empreinte = hash(\'md5\', $cle);', false],
    'un mot qui finit par sha' => ['DB::raw(\'pasha(1)\');', false],
]);
