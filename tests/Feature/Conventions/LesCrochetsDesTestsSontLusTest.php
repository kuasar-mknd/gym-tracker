<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;

/**
 * Deux réglages de la suite ne s'appliquaient à rien, sans qu'aucun message
 * ne le dise (#1927).
 *
 * `DuskTestCase::prepare()` devait démarrer ChromeDriver et poser
 * APP_ENV=testing avant chaque classe de parcours. Elle ne tenait plus qu'à
 * une étiquette de docblock, que PHPUnit ne lit plus depuis sa version 12, et
 * rien d'autre ne l'appelait : elle ne tournait plus depuis mars 2026, quand
 * son attribut `#[BeforeClass]` est devenu cette étiquette dans le commit
 * même qui lui ajoutait APP_ENV=testing, pose qui n'a donc jamais eu lieu.
 * tests/Pest.php liait de son côté DatabaseTruncation au dossier
 * tests/Browser, où `in()` ne trouvait plus aucun fichier Pest depuis mars
 * 2026 — seulement des classes, que la liaison n'atteint pas.
 *
 * Les deux défauts ont la même forme : un réglage qui se lit comme actif et
 * que l'outil ignore. Ces gardes refusent leur retour.
 *
 * `prepare()` a été retirée, pas réveillée : la CI lance ChromeDriver dans
 * une étape à part et Sail fournit Selenium, et Sail garde APP_ENV à `local`
 * pour les parcours. La remettre sous un attribut que PHPUnit lit doublerait
 * le pilote de la CI et poserait APP_ENV=testing dans leur processus : la
 * dernière garde refuse ce réveil, quelle que soit sa forme.
 */

/**
 * Les attributs de la version installée de PHPUnit, indexés par leur nom en
 * minuscules. Chacun remplace l'étiquette de docblock du même nom
 * (`#[BeforeClass]` pour l'ancienne étiquette beforeClass), qu'elle ignore.
 *
 * @return array<string, string>
 */
function crochetsDesTestsAttributsDePhpunit(): array
{
    $fichier = new ReflectionClass(Test::class)->getFileName();
    $chemins = $fichier === false ? false : glob(dirname($fichier).'/*.php');
    $attributs = [];

    foreach ($chemins === false ? [] : $chemins as $chemin) {
        $nom = basename($chemin, '.php');
        $attributs[strtolower($nom)] = $nom;
    }

    return $attributs;
}

/**
 * Le jeton à cette position pose-t-il APP_ENV : `putenv('APP_ENV=…')`, ou
 * une affectation à `$_ENV['APP_ENV']` ou `$_SERVER['APP_ENV']` ? Une lecture
 * ne compte pas.
 *
 * @param  list<PhpToken>  $jetons  les jetons du fichier, sans espaces ni commentaires
 */
function crochetsDesTestsPoseAppEnv(array $jetons, int $position): bool
{
    $jeton = $jetons[$position];

    if (! $jeton->is(T_CONSTANT_ENCAPSED_STRING)) {
        return false;
    }

    $valeur = substr($jeton->text, 1, -1);

    if (str_starts_with($valeur, 'APP_ENV=')) {
        return true;
    }

    return $valeur === 'APP_ENV'
        && ($jetons[$position - 1] ?? null)?->text === '['
        && in_array(($jetons[$position - 2] ?? null)?->text, ['$_ENV', '$_SERVER'], true)
        && ($jetons[$position + 1] ?? null)?->text === ']'
        && ($jetons[$position + 2] ?? null)?->text === '=';
}

it('ne confie aucun crochet ni réglage de test à une étiquette de docblock, que PHPUnit ne lit plus', function (): void {
    $attributs = crochetsDesTestsAttributsDePhpunit();

    expect($attributs)->toHaveKeys(['beforeclass', 'afterclass', 'before', 'after', 'test', 'dataprovider']);

    $fautifs = [];

    foreach (Finder::create()->files()->in(base_path('tests'))->name('*.php') as $fichier) {
        foreach (token_get_all($fichier->getContents()) as $jeton) {
            if (! is_array($jeton) || $jeton[0] !== T_DOC_COMMENT) {
                continue;
            }

            preg_match_all('/(?:^|\/\*\*)[ \t]*\*?[ \t]*@([A-Za-z]+)/m', $jeton[1], $etiquettes, PREG_OFFSET_CAPTURE);

            foreach ($etiquettes[1] as [$etiquette, $position]) {
                $attribut = $attributs[strtolower($etiquette)] ?? null;

                if ($attribut !== null) {
                    $fautifs[] = sprintf(
                        'tests/%s:%d : @%s est ignorée, écrire #[%s]',
                        str_replace('\\', '/', $fichier->getRelativePathname()),
                        $jeton[2] + substr_count(substr($jeton[1], 0, $position), "\n"),
                        $etiquette,
                        $attribut,
                    );
                }
            }
        }
    }

    expect($fautifs)->toBe([]);
});

it('ne lie dans tests/Pest.php que des dossiers où vivent des tests Pest', function (): void {
    preg_match_all('/->in\(([^)]*)\)/', (string) file_get_contents(base_path('tests/Pest.php')), $appels);

    $dossiers = [];

    foreach ($appels[1] as $arguments) {
        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $arguments, $noms);
        array_push($dossiers, ...$noms[1]);
    }

    expect($dossiers)->not->toBe([]);

    $sansTestPest = [];

    foreach ($dossiers as $dossier) {
        $chemin = base_path('tests/'.$dossier);
        $atteintUnTestPest = is_dir($chemin) && Finder::create()
            ->files()
            ->in($chemin)
            ->name('*.php')
            ->contains('/^(?:it|test|describe)\(/m')
            ->hasResults();

        if (! $atteintUnTestPest) {
            $sansTestPest[] = $dossier;
        }
    }

    expect($sansTestPest)->toBe([]);
});

it('ne démarre aucun pilote depuis la suite, et ne pose pas APP_ENV dans le processus des parcours', function (): void {
    $demarrages = ['startchromedriver', 'buildchromeprocess', 'chromeprocess'];
    $fautifs = [];

    foreach (Finder::create()->files()->in(base_path('tests'))->name('*.php') as $fichier) {
        $chemin = 'tests/'.str_replace('\\', '/', $fichier->getRelativePathname());
        $duProcessusDesParcours = $chemin === 'tests/DuskTestCase.php' || str_starts_with($chemin, 'tests/Browser/');
        $jetons = array_values(array_filter(
            PhpToken::tokenize($fichier->getContents()),
            fn (PhpToken $jeton): bool => ! $jeton->isIgnorable(),
        ));

        foreach ($jetons as $position => $jeton) {
            if ($jeton->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                && in_array(strtolower(class_basename($jeton->text)), $demarrages, true)) {
                $fautifs[] = sprintf('%s:%d : %s démarre un pilote, que la CI et Sail lancent déjà', $chemin, $jeton->line, $jeton->text);
            }

            if ($duProcessusDesParcours && crochetsDesTestsPoseAppEnv($jetons, $position)) {
                $fautifs[] = sprintf('%s:%d : pose APP_ENV dans le processus des parcours, où Sail le garde à local', $chemin, $jeton->line);
            }
        }
    }

    expect($fautifs)->toBe([]);
});
