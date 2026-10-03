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
