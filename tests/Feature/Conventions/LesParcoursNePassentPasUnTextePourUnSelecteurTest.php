<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\Support\NavigateurDesParcours;

/**
 * Une méthode de Dusk qui prend un sélecteur ne lit pas un texte (#2124).
 *
 * `ProfileEditTest` attendait la fermeture de la fenêtre de suppression du
 * compte par `waitUntilMissing('CONFIRMER LA SUPPRESSION', 15)`. Dusk y lisait
 * le sélecteur `body CONFIRMER LA SUPPRESSION`, des éléments <confirmer>, <la>
 * et <suppression> imbriqués, que la page n'a jamais. Une absence attendue
 * (`waitUntilMissing()`, `assertMissing()`, `assertNotPresent()`) sur un
 * sélecteur qui ne désigne rien passe au premier essai : les trois parcours
 * affirmaient que « Annuler » fermait la fenêtre sans le vérifier. Une
 * présence attendue sur un tel sélecteur échoue, mais en parlant d'un
 * sélecteur que personne n'a voulu écrire. Un texte s'attend par
 * `waitForText()` ou `waitUntilMissingText()`, et se vérifie par
 * `assertSee()` ou `assertDontSee()`.
 *
 * Un texte reste un sélecteur valide, quelle que soit sa casse, avec des
 * virgules, des `+ > ~` ou des caractères hors ASCII (’ « » ✓ …) : ces trois
 * méthodes d'absence passent alors en silence. Une ponctuation ASCII qui casse
 * le CSS (`.` final, `!`, `?`) le rend invalide : `waitUntilMissing()`
 * échoue au bout de son délai, `assertMissing()` tout de suite, mais
 * `assertNotPresent()` passe encore, car `ElementResolver::find()` avale toute
 * exception, l'erreur de sélecteur comprise.
 *
 * La garde refuse, en premier argument d'une méthode du navigateur des
 * parcours dont le premier paramètre est un sélecteur ou un champ (`$selector`
 * ou `$field`, chez Dusk comme dans les macros de `DuskTestCase`), une chaîne
 * qui ne porte aucun signe d'un sélecteur voulu et qui a un caractère hors
 * ASCII, qu'aucun nom de balise ne porte, ou un mot d'au moins deux
 * capitales : le texte d'un titre ou d'un bouton que le CSS passe en
 * majuscules, tel que WebDriver le rend (.ai/rules/browser.md), ponctuation
 * comprise. Un mot seul suffit (`ANNULER`), le même défaut : une balise en
 * capitales (`NAV`) serait un sélecteur valide, mais aucun parcours ne nomme
 * ses éléments ainsi ; il le fait par `dusk`, par `data-testid` ou par des
 * balises en minuscules. Seul un champ d'un seul mot reste permis, même en
 * capitales ou hors ASCII : c'est un nom, que Dusk cherche aussi par
 * `input[name="…"]`.
 *
 * Elle lit des jetons, pas ce que le code fera : un sélecteur calculé,
 * concaténé ou passé par son nom lui échappe. Un texte en ASCII sans mot en
 * capitales lui échappe aussi (`Annuler la suppression`,
 * `Oui, supprimer`) : il se lit comme des noms de balises, et le reconnaître
 * demanderait la liste des balises HTML et SVG, à tenir à jour. De même pour
 * un texte qui porte un signe de sélecteur (`SÉRIES (3)`).
 */

/**
 * Les méthodes du navigateur des parcours qui prennent un sélecteur ou un
 * champ en premier argument, indexées par leur nom en minuscules, vrai pour
 * un champ : celles de Dusk et de `NavigateurDesParcours`, et les macros que
 * `DuskTestCase` déclare avec un tel premier paramètre. Pas celles de
 * l'`ElementResolver`, qu'aucun parcours n'appelle : son `format()` porterait
 * le nom de celui de Carbon, dont un format en capitales (`'D M Y'`)
 * passerait pour un texte.
 *
 * @return array<string, bool>
 */
function texteEnSelecteurMethodes(): array
{
    $methodes = [];

    foreach (new ReflectionClass(NavigateurDesParcours::class)->getMethods(ReflectionMethod::IS_PUBLIC) as $methode) {
        $premier = $methode->getParameters()[0] ?? null;

        if ($premier !== null && in_array($premier->getName(), ['selector', 'field'], true)) {
            $methodes[strtolower($methode->getName())] = $premier->getName() === 'field';
        }
    }

    preg_match_all(
        '/Browser::macro\(\s*[\'"](\w+)[\'"]\s*,\s*(?:static\s+)?(?:function|fn)\s*\(\s*(?:[\w\\\\|?]+\s+)?\$(selector|field)\b/',
        (string) file_get_contents(base_path('tests/DuskTestCase.php')),
        $macros,
        PREG_SET_ORDER,
    );

    foreach ($macros as [, $macro, $parametre]) {
        $methodes[strtolower($macro)] = $parametre === 'field';
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
 * @param  array<string, bool>  $methodes  indexées par leur nom en minuscules, vrai pour un champ
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

        if (texteEnSelecteurEstUnTexte($texte, $methodes[strtolower($jeton->text)])) {
            $trouves[] = ['ligne' => $jeton->line, 'methode' => $jeton->text, 'texte' => $texte];
        }
    }

    return $trouves;
}

/**
 * Ce premier argument est un texte, et non un sélecteur : il ne porte aucun
 * signe d'un sélecteur voulu (crochet, `#`, `@`, parenthèse, `=`, `*`, `.` ou
 * `:` collé à un nom), et il a soit un caractère hors ASCII, qu'aucun nom de
 * balise ne porte, soit un mot d'au moins deux capitales. Virgules, `+ > ~`,
 * guillemets et ponctuation finale n'y changent rien. Le champ d'un seul mot
 * est un nom, que Dusk cherche aussi par `input[name="…"]` (`CVV`).
 */
function texteEnSelecteurEstUnTexte(string $argument, bool $champ): bool
{
    $argument = trim($argument);

    if (preg_match('/[\[\]#@()=*]|[.:][\p{L}_-]/u', $argument) === 1
        || ($champ && preg_match('/\s/u', $argument) !== 1)) {
        return false;
    }

    return preg_match('/[^\x00-\x7F]/', $argument) === 1
        || preg_match('/(?<![\p{L}\p{M}\p{N}])\p{Lu}[\p{Lu}\p{M}\'’-]*\p{Lu}\p{M}*(?![\p{L}\p{M}\p{N}])/u', $argument) === 1;
}

it('connaît les méthodes qui prennent un sélecteur, et pas celles qui lisent un texte', function (): void {
    $methodes = texteEnSelecteurMethodes();

    expect($methodes)
        ->toHaveKeys(['waituntilmissing', 'assertmissing', 'assertnotpresent', 'waitfor', 'click', 'type', 'clickwhensettled']);

    expect($methodes)
        ->not->toHaveKeys(['waituntilmissingtext', 'waitfortext', 'assertsee', 'assertdontsee', 'press', 'clicklink', 'format']);

    expect(array_keys(array_filter($methodes)))
        ->toContain('type', 'select', 'check')
        ->not->toContain('waituntilmissing', 'assertmissing', 'clickwhensettled');
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
        $browser->assertSee(now()->format('D M Y'));
        PHP;

    expect(texteEnSelecteurTrouves($code, texteEnSelecteurMethodes()))->toBe([
        ['ligne' => 2, 'methode' => 'waitUntilMissing', 'texte' => 'CONFIRMER LA SUPPRESSION'],
        ['ligne' => 3, 'methode' => 'assertMissing', 'texte' => 'MOT DE PASSE'],
        ['ligne' => 4, 'methode' => 'clickWhenSettled', 'texte' => 'L\'ÉCRAN D’ACCUEIL'],
        ['ligne' => 5, 'methode' => 'type', 'texte' => 'NOUVEAU MOT DE PASSE'],
        ['ligne' => 8, 'methode' => 'assertMissing', 'texte' => 'NAV'],
    ]);
});

it('refuse un texte que Dusk lirait comme un sélecteur, ou dont il avalerait l’erreur', function (string $appel): void {
    expect(texteEnSelecteurTrouves("<?php\n\$browser->{$appel};", texteEnSelecteurMethodes()))->toHaveCount(1);
})->with([
    'un signe hors ASCII après le mot' => ["waitUntilMissing('ENREGISTRÉ ✓', 3)"],
    'deux mots séparés par une virgule' => ["assertMissing('OUI, SUPPRIMER')"],
    'des guillemets français' => ["assertNotPresent('« ENREGISTRÉ »')"],
    'un combinateur entre deux mots' => ["waitUntilMissing('RETOUR > ACCUEIL')"],
    'un texte en minuscules, hors ASCII' => ["waitUntilMissing('n’a pas pu être ajouté', 3)"],
    'un texte en casse mixte, hors ASCII' => ["assertMissing('Enregistré ✓')"],
    'une question, dont assertNotPresent() avale l’erreur de sélecteur' => ["assertNotPresent('SUPPRIMER LE COMPTE ?')"],
    'une phrase finie par un point' => ["assertNotPresent('CONFIRMER LA SUPPRESSION.')"],
    'un mot seul en capitales' => ["waitUntilMissing('ANNULER', 15)"],
    'un autre mot seul en capitales' => ["assertMissing('SUPPRIMER')"],
]);

it('laisse passer un sélecteur, même hors ASCII ou entre deux combinateurs', function (string $appel): void {
    expect(texteEnSelecteurTrouves("<?php\n\$browser->{$appel};", texteEnSelecteurMethodes()))->toBe([]);
})->with([
    'une balise' => ["mouseover('nav')"],
    'des balises imbriquées' => ["waitFor('header h1')"],
    'une liste de balises' => ["assertMissing('ul, ol')"],
    'un combinateur entre deux balises' => ["waitFor('body > main')"],
    'un attribut au texte accentué' => ["assertMissing('nav[aria-label=\"Pagination de l’historique\"]')"],
    'un attribut dusk accentué' => ["click('@réglages')"],
    'une classe après une balise' => ["waitUntilMissing('div.Écran')"],
    'une pseudo-classe' => ["waitFor('button:not([disabled])')"],
    'un texte lu comme un texte' => ["waitUntilMissingText('ENREGISTRÉ ✓', 3)"],
    'un champ nommé d’un mot en capitales' => ["type('CVV', '123')"],
    'un champ nommé d’un mot accentué' => ["type('prénom', 'Léa')"],
]);

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
