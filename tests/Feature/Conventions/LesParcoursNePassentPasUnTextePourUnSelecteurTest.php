<?php

declare(strict_types=1);

use Laravel\Dusk\ElementResolver;
use Symfony\Component\Finder\Finder;
use Tests\Support\NavigateurDesParcours;

/**
 * Une méthode de Dusk qui prend un sélecteur ne lit pas un texte (#2124).
 *
 * `ProfileEditTest` attendait la fermeture de la fenêtre de suppression du
 * compte par `waitUntilMissing('CONFIRMER LA SUPPRESSION', 15)`. Dusk y lisait
 * le sélecteur `body CONFIRMER LA SUPPRESSION`, des éléments <confirmer>, <la>
 * et <suppression> imbriqués, que la page n'a jamais. Une absence attendue
 * (`waitUntilMissing()`, `assertMissing()`) sur un sélecteur qui ne désigne
 * rien passe au premier essai : les trois parcours affirmaient que « Annuler »
 * fermait la fenêtre sans le vérifier. Une présence attendue sur un tel
 * sélecteur échoue, mais en parlant d'un sélecteur que personne n'a voulu
 * écrire. Un texte s'attend par `waitForText()` ou `waitUntilMissingText()`,
 * et se vérifie par `assertSee()` ou `assertDontSee()`.
 *
 * La garde refuse, en premier argument d'une méthode du navigateur des
 * parcours dont le premier paramètre est un sélecteur ou un champ (`$selector`
 * ou `$field`, chez Dusk comme dans les macros de `DuskTestCase`), une chaîne
 * faite d'au moins deux mots en capitales séparés par des espaces : le texte
 * d'un titre ou d'un bouton que le CSS passe en majuscules, tel que WebDriver
 * le rend (.ai/rules/browser.md). Un mot seul reste permis : un nom de balise
 * en capitales (`NAV`) est un sélecteur valide. Aucun parcours ne nomme ses
 * éléments par des balises en capitales ; il le fait par `dusk`, par
 * `data-testid` ou par des balises en minuscules.
 *
 * Elle lit des jetons, pas ce que le code fera : un sélecteur calculé,
 * concaténé ou passé par son nom lui échappe, comme un texte en minuscules
 * sans ponctuation (`Annuler la suppression`), qui serait lui aussi un
 * sélecteur valide qui ne désigne rien.
 */

/**
 * Les méthodes du navigateur des parcours qui prennent un sélecteur ou un
 * champ en premier argument, indexées par leur nom en minuscules : celles de
 * Dusk et de `NavigateurDesParcours`, celles de son `ElementResolver`, et les
 * macros que `DuskTestCase` déclare avec un tel premier paramètre.
 *
 * @return array<string, string>
 */
function texteEnSelecteurMethodes(): array
{
    $methodes = [];

    foreach ([NavigateurDesParcours::class, ElementResolver::class] as $classe) {
        foreach (new ReflectionClass($classe)->getMethods(ReflectionMethod::IS_PUBLIC) as $methode) {
            $premier = $methode->getParameters()[0] ?? null;

            if ($premier !== null && in_array($premier->getName(), ['selector', 'field'], true)) {
                $methodes[strtolower($methode->getName())] = $methode->getName();
            }
        }
    }

    preg_match_all(
        '/Browser::macro\(\s*[\'"](\w+)[\'"]\s*,\s*(?:static\s+)?(?:function|fn)\s*\(\s*(?:[\w\\\\|?]+\s+)?\$(?:selector|field)\b/',
        (string) file_get_contents(base_path('tests/DuskTestCase.php')),
        $macros,
    );

    foreach ($macros[1] as $macro) {
        $methodes[strtolower($macro)] = $macro;
    }

    return $methodes;
}

/**
 * Les fichiers des parcours, indexés par leur chemin depuis la racine :
 * tests/Browser, `tests/DuskTestCase.php` et ce que tests/Support écrit pour
 * Dusk.
 *
 * @return array<string, string>
 */
function texteEnSelecteurFichiersDesParcours(): array
{
    $fichiers = [];

    foreach (Finder::create()->files()->in(base_path('tests'))->name('*.php') as $fichier) {
        $chemin = 'tests/'.str_replace('\\', '/', $fichier->getRelativePathname());
        $contenu = $fichier->getContents();

        if (str_starts_with($chemin, 'tests/Browser/')
            || $chemin === 'tests/DuskTestCase.php'
            || (str_starts_with($chemin, 'tests/Support/') && str_contains($contenu, 'Laravel\\Dusk\\'))) {
            $fichiers[$chemin] = $contenu;
        }
    }

    ksort($fichiers);

    return $fichiers;
}

/**
 * Les textes en capitales que ce code passe, en premier argument littéral, à
 * l'une de ces méthodes.
 *
 * @param  array<string, string>  $methodes  indexées par leur nom en minuscules
 * @return list<array{ligne: int, methode: string, texte: string}>
 */
function texteEnSelecteurTrouves(string $code, array $methodes): array
{
    $jetons = array_values(array_filter(
        PhpToken::tokenize($code),
        fn (PhpToken $jeton): bool => ! $jeton->isIgnorable(),
    ));
    $trouves = [];

    foreach ($jetons as $position => $jeton) {
        $argument = $jetons[$position + 2] ?? null;

        if (! $jeton->is(T_STRING)
            || ! isset($methodes[strtolower($jeton->text)])
            || ! in_array(($jetons[$position - 1] ?? null)?->text, ['->', '?->'], true)
            || ($jetons[$position + 1] ?? null)?->text !== '('
            || $argument === null
            || ! $argument->is(T_CONSTANT_ENCAPSED_STRING)
            || ! in_array(($jetons[$position + 3] ?? null)?->text, [',', ')'], true)) {
            continue;
        }

        $texte = substr($argument->text, 1, -1);
        $texte = $argument->text[0] === "'"
            ? strtr($texte, ["\\'" => "'", '\\\\' => '\\'])
            : stripcslashes($texte);

        if (preg_match('/^\p{Lu}[\p{Lu}\p{M}\'’-]*(?:\s+\p{Lu}[\p{Lu}\p{M}\'’-]*)+$/u', trim($texte)) === 1) {
            $trouves[] = ['ligne' => $jeton->line, 'methode' => $jeton->text, 'texte' => $texte];
        }
    }

    return $trouves;
}

it('connaît les méthodes qui prennent un sélecteur, et pas celles qui lisent un texte', function (): void {
    $methodes = texteEnSelecteurMethodes();

    expect($methodes)
        ->toHaveKeys(['waituntilmissing', 'assertmissing', 'assertnotpresent', 'waitfor', 'click', 'type', 'clickwhensettled', 'findorfail']);

    expect($methodes)
        ->not->toHaveKeys(['waituntilmissingtext', 'waitfortext', 'assertsee', 'assertdontsee', 'press', 'clicklink']);
});

it('reconnaît un texte en capitales passé pour un sélecteur, et rien d’autre', function (): void {
    $code = <<<'PHP'
        <?php
        $browser->waitUntilMissing('CONFIRMER LA SUPPRESSION', 15);
        $browser->waitForText('MON PROFIL')->assertMissing("MOT DE PASSE");
        $browser?->clickWhenSettled('L\'ÉCRAN D’ACCUEIL');
        $browser->type('NOUVEAU MOT DE PASSE', 'secret');
        $browser->waitUntilMissingText('CONFIRMER LA SUPPRESSION', 15)->assertDontSee('MON PROFIL');
        $browser->press('SUPPRIMER LE COMPTE')->clickLink('MES PROGRAMMES');
        $browser->waitUntilMissing('dialog[open]', 15)->waitFor('@add-habit')->assertMissing('NAV');
        $browser->mouseover('header h1')->waitFor('[dusk="'.$id.' A B"]')->assertSeeIn('@titre', 'MON PROFIL');
        PHP;

    expect(texteEnSelecteurTrouves($code, texteEnSelecteurMethodes()))->toBe([
        ['ligne' => 2, 'methode' => 'waitUntilMissing', 'texte' => 'CONFIRMER LA SUPPRESSION'],
        ['ligne' => 3, 'methode' => 'assertMissing', 'texte' => 'MOT DE PASSE'],
        ['ligne' => 4, 'methode' => 'clickWhenSettled', 'texte' => 'L\'ÉCRAN D’ACCUEIL'],
        ['ligne' => 5, 'methode' => 'type', 'texte' => 'NOUVEAU MOT DE PASSE'],
    ]);
});

it('ne passe aucun texte à une méthode des parcours qui prend un sélecteur', function (): void {
    $methodes = texteEnSelecteurMethodes();
    $fichiers = texteEnSelecteurFichiersDesParcours();

    expect($fichiers)->toHaveKeys(['tests/Browser/ProfileEditTest.php', 'tests/DuskTestCase.php']);

    $fautifs = [];

    foreach ($fichiers as $chemin => $code) {
        foreach (texteEnSelecteurTrouves($code, $methodes) as $trouve) {
            $fautifs[] = sprintf(
                '%s:%d : %s() prend un sélecteur, et « %s » est un texte, qui n’y désigne aucun élément. '
                .'Un texte s’attend par waitForText() ou waitUntilMissingText(), et se vérifie par assertSee() ou assertDontSee().',
                $chemin,
                $trouve['ligne'],
                $trouve['methode'],
                $trouve['texte'],
            );
        }
    }

    expect($fautifs)->toBe([]);
});
