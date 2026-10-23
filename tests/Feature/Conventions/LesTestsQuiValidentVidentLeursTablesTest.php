<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Un fichier Pest qui valide pour de bon, sous `DatabaseTruncation`, vit hors
 * de tests/Feature et vide ses tables après chaque test (#2108).
 *
 * tests/Pest.php lie `RefreshDatabase` à chaque fichier Pest de tests/Feature :
 * un fichier de ce dossier qui y ajouterait `DatabaseTruncation` ouvrirait la
 * transaction du test, puis la validerait par sa troncature, un ordre DDL.
 * Ailleurs, `DatabaseTruncation` ne vide les tables qu'AVANT chaque test, et
 * pas avant le premier, qu'il migre : sans un `afterEach` qui les vide, les
 * tests de `RefreshDatabase` qui le suivent dans le même processus partent
 * des lignes qu'il a validées, et tombent selon l'ordre de la suite.
 *
 * Les parcours de tests/Browser sont des classes PHPUnit (`use
 * DatabaseTruncation;`), que la garde ne lit pas : leur processus ne lance
 * aucun test de `RefreshDatabase`. Elle lit le code, chaînes et commentaires
 * ôtés : un trait lié autrement que par `uses()` ou `pest()->use()` lui
 * échappe.
 */

/**
 * Le code de ce fichier PHP, sans ses commentaires ni ses chaînes : un
 * commentaire ou un exemple écrit dans une chaîne ne lie aucun trait.
 */
function testsQuiValidentCode(string $source): string
{
    $code = '';

    foreach (PhpToken::tokenize($source) as $jeton) {
        if (! $jeton->is([T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
            $code .= $jeton->text;
        }
    }

    return $code;
}

/**
 * Ce fichier lie-t-il `DatabaseTruncation` par Pest ?
 */
function testsQuiValidentLieLaTroncature(string $code): bool
{
    return preg_match('/(?:\buses|->use)\([^;]*\bDatabaseTruncation\b/', $code) === 1;
}

/**
 * Ce que la garde reproche à ce fichier de test, lu à ce chemin relatif à
 * tests/ : rien s'il ne lie pas `DatabaseTruncation` par Pest.
 *
 * @return list<string>
 */
function testsQuiValidentReproches(string $chemin, string $source): array
{
    $source = testsQuiValidentCode($source);

    if (! testsQuiValidentLieLaTroncature($source)) {
        return [];
    }

    if (str_starts_with($chemin, 'Feature/')) {
        return ["tests/{$chemin} : DatabaseTruncation sous le RefreshDatabase de tests/Pest.php, à placer hors de tests/Feature"];
    }

    $debut = strpos($source, 'afterEach(');
    $fin = $debut === false ? false : strpos($source, "\n});", $debut);
    $apresChaqueTest = $debut === false || $fin === false ? '' : substr($source, $debut, $fin - $debut);

    if (! str_contains($apresChaqueTest, 'truncateTablesForAllConnections()')) {
        return ["tests/{$chemin} : DatabaseTruncation sans afterEach qui appelle truncateTablesForAllConnections()"];
    }

    return [];
}

it('ne trouve aucun fichier Pest sous DatabaseTruncation dans tests/Feature, ni aucun qui garde ses lignes après lui', function (): void {
    $reproches = [];
    $lies = [];

    foreach (Finder::create()->files()->in(base_path('tests'))->name('*Test.php')->sortByName() as $fichier) {
        $chemin = str_replace('\\', '/', $fichier->getRelativePathname());
        $source = $fichier->getContents();

        if (preg_match('/^(?:it|test)\(/m', testsQuiValidentCode($source)) !== 1) {
            continue;
        }

        if (testsQuiValidentLieLaTroncature(testsQuiValidentCode($source))) {
            $lies[] = $chemin;
        }

        array_push($reproches, ...testsQuiValidentReproches($chemin, $source));
    }

    // Témoin : la garde voit au moins le fichier qui l'a fait naître.
    expect($lies)->toContain('Unit/Services/SeanceEnCoursApresLaValidationTest.php')
        ->and($reproches)->toBe([]);
});

it('reproche chaque écart qu’elle doit voir', function (string $chemin, string $source, array $attendus): void {
    expect(testsQuiValidentReproches($chemin, $source))->toBe($attendus);
})->with([
    'sous RefreshDatabase' => [
        'Feature/Services/ValideTest.php',
        "<?php\nuses(DatabaseTruncation::class);\nafterEach(function (): void {\n    \$this->truncateTablesForAllConnections();\n});\nit('valide', fn () => null);\n",
        ['tests/Feature/Services/ValideTest.php : DatabaseTruncation sous le RefreshDatabase de tests/Pest.php, à placer hors de tests/Feature'],
    ],
    'sans afterEach' => [
        'Unit/ValideTest.php',
        "<?php\nuses(TestCase::class, DatabaseTruncation::class);\nit('valide', fn () => null);\n",
        ['tests/Unit/ValideTest.php : DatabaseTruncation sans afterEach qui appelle truncateTablesForAllConnections()'],
    ],
    'vidage hors de l’afterEach' => [
        'Unit/ValideTest.php',
        "<?php\npest()->use(DatabaseTruncation::class);\nafterEach(function (): void {\n    DB::purge('autre');\n});\nit('valide', function (): void {\n    \$this->truncateTablesForAllConnections();\n});\n",
        ['tests/Unit/ValideTest.php : DatabaseTruncation sans afterEach qui appelle truncateTablesForAllConnections()'],
    ],
    'vidé après chaque test' => [
        'Unit/ValideTest.php',
        "<?php\nuses(TestCase::class, DatabaseTruncation::class);\nafterEach(function (): void {\n    DB::purge('autre');\n    \$this->truncateTablesForAllConnections();\n});\nit('valide', fn () => null);\n",
        [],
    ],
    'sans DatabaseTruncation' => [
        'Feature/ValideTest.php',
        "<?php\nuses(RefreshDatabase::class);\nit('valide', fn () => null);\n",
        [],
    ],
    'nommé dans un commentaire seulement' => [
        'Feature/ValideTest.php',
        "<?php\n// uses(DatabaseTruncation::class) serait refusé ici.\nit('valide', fn () => null);\n",
        [],
    ],
]);
