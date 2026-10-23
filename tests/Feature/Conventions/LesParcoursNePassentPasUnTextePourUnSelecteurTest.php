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
 * ou `$field` chez Dusk, et aussi `$selecteur` ou `$champ` dans les aides du
 * dépôt : `NavigateurDesParcours` et les macros de `DuskTestCase`), une chaîne
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
 * Une aide du dépôt dont le premier paramètre peut recevoir une chaîne (sans
 * type, `string` ou `mixed`) sous un autre nom (`$cible`) fait échouer la
 * garde, tant qu'elle n'est pas rangée parmi les aides sans sélecteur : sans
 * cela, la garde ne surveillerait pas ses appels, et rien ne le dirait.
 *
 * Elle lit des jetons, pas ce que le code fera : un sélecteur calculé,
 * concaténé ou passé par son nom lui échappe. Un texte en ASCII sans mot en
 * capitales lui échappe aussi (`Annuler la suppression`,
 * `Oui, supprimer`) : il se lit comme des noms de balises, et le reconnaître
 * demanderait la liste des balises HTML et SVG, à tenir à jour. De même pour
 * un texte qui porte un signe de sélecteur (`SÉRIES (3)`).
 */

/**
 * Les noms d'un premier paramètre qui prend un sélecteur (faux) ou un champ
 * (vrai) : ceux de Dusk, et leur traduction dans les aides du dépôt.
 *
 * @return array<string, bool>
 */
function texteEnSelecteurNomsDesParametres(): array
{
    return ['selector' => false, 'selecteur' => false, 'field' => true, 'champ' => true];
}

/**
 * Les macros que ce code déclare sur `Browser`, indexées par leur nom, avec
 * le nom et le type de leur premier paramètre (vide sans type). Une macro sans
 * paramètre n'y est pas.
 *
 * @return array<string, array{parametre: string, type: string}>
 */
function texteEnSelecteurMacros(string $code): array
{
    preg_match_all(
        '/Browser::macro\(\s*[\'"](\w+)[\'"]\s*,\s*(?:static\s+)?(?:function|fn)\s*\(\s*(?:([\w\\\\|?]+)\s+)?\$(\w+)/u',
        $code,
        $trouvees,
        PREG_SET_ORDER,
    );

    $macros = [];

    foreach ($trouvees as [, $nom, $type, $parametre]) {
        $macros[$nom] = ['parametre' => $parametre, 'type' => $type];
    }

    return $macros;
}

/**
 * Le code qui déclare les macros des parcours : `tests/DuskTestCase.php`, ou
 * celui qu'un test d'exemple donne.
 */
function texteEnSelecteurCodeDesMacros(?string $code = null): string
{
    return $code ?? (string) file_get_contents(base_path('tests/DuskTestCase.php'));
}

/**
 * Les méthodes du navigateur des parcours qui prennent un sélecteur ou un
 * champ en premier argument, indexées par leur nom en minuscules, vrai pour
 * un champ : celles de Dusk et de `NavigateurDesParcours`, et les macros
 * déclarées avec un tel premier paramètre, en anglais ou en français. Pas
 * celles de l'`ElementResolver`, qu'aucun parcours n'appelle : son `format()`
 * porterait le nom de celui de Carbon, dont un format en capitales
 * (`'D M Y'`) passerait pour un texte.
 *
 * @return array<string, bool>
 */
function texteEnSelecteurMethodes(?string $codeDesMacros = null): array
{
    $noms = texteEnSelecteurNomsDesParametres();
    $methodes = [];

    foreach (new ReflectionClass(NavigateurDesParcours::class)->getMethods(ReflectionMethod::IS_PUBLIC) as $methode) {
        $premier = $methode->getParameters()[0] ?? null;

        if ($premier !== null && isset($noms[$premier->getName()])) {
            $methodes[strtolower($methode->getName())] = $noms[$premier->getName()];
        }
    }

    foreach (texteEnSelecteurMacros(texteEnSelecteurCodeDesMacros($codeDesMacros)) as $nom => ['parametre' => $parametre]) {
        if (isset($noms[$parametre])) {
            $methodes[strtolower($nom)] = $noms[$parametre];
        }
    }

    return $methodes;
}

/**
 * Les aides que le dépôt ajoute au navigateur des parcours, indexées par leur
 * nom, avec le nom et le type de leur premier paramètre : les méthodes que
 * `NavigateurDesParcours` déclare elle-même, et les macros.
 *
 * @return array<string, array{parametre: string, type: string}>
 */
function texteEnSelecteurPremiersParametresDesAides(?string $codeDesMacros = null): array
{
    $aides = [];

    foreach (new ReflectionClass(NavigateurDesParcours::class)->getMethods(ReflectionMethod::IS_PUBLIC) as $methode) {
        $premier = $methode->getParameters()[0] ?? null;

        if ($premier !== null && $methode->getDeclaringClass()->getName() === NavigateurDesParcours::class) {
            $aides[$methode->getName()] = ['parametre' => $premier->getName(), 'type' => (string) $premier->getType()];
        }
    }

    return $aides + texteEnSelecteurMacros(texteEnSelecteurCodeDesMacros($codeDesMacros));
}

/**
 * Les aides du dépôt dont le premier paramètre peut recevoir une chaîne sans
 * être ni un sélecteur ni un champ, avec ce qu'il est.
 *
 * @return array<string, string>
 */
function texteEnSelecteurAidesSansSelecteur(): array
{
    return ['waitUsing' => 'une durée, sans type comme chez Dusk'];
}

/**
 * Les aides du dépôt dont le premier paramètre peut recevoir une chaîne (sans
 * type, `string` ou `mixed`) et que la garde ne range nulle part : ni parmi
 * les sélecteurs et les champs, faute d'un nom qu'elle connaît (`$cible`), ni
 * parmi les aides sans sélecteur. Elle ne surveillerait pas leurs appels.
 *
 * @return list<string>
 */
function texteEnSelecteurAidesNonRangees(?string $codeDesMacros = null): array
{
    $noms = texteEnSelecteurNomsDesParametres();
    $sansSelecteur = texteEnSelecteurAidesSansSelecteur();
    $nonRangees = [];

    foreach (texteEnSelecteurPremiersParametresDesAides($codeDesMacros) as $nom => ['parametre' => $parametre, 'type' => $type]) {
        $prendUneChaine = $type === '' || preg_match('/(?:^|[|?])(?:string|mixed)(?:$|\|)/i', $type) === 1;

        if ($prendUneChaine && ! isset($noms[$parametre]) && ! isset($sansSelecteur[$nom])) {
            $nonRangees[] = $nom;
        }
    }

    return $nonRangees;
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

    $champs = array_keys(array_filter($methodes));

    expect($champs)->toContain('type', 'select', 'check');
    expect($champs)->not->toContain('waituntilmissing', 'assertmissing', 'clickwhensettled');
});

it('reconnaît une macro par le nom de son premier paramètre, en français comme en anglais', function (): void {
    $code = <<<'PHP'
        Browser::macro('attendreLaDisparition', function (string $selecteur, int $secondes = 15): object {});
        Browser::macro('remplirLeChamp', static fn (?string $champ, string $valeur): object => $this);
        Browser::macro('choisirLaDuree', function (string $selector, int $heures): object {});
        Browser::macro('viserLaCible', function (string $cible): object {});
        Browser::macro('sansType', fn ($quoi): object => $this);
        Browser::macro('attendreLesSecondes', function (int $secondes): object {});
        Browser::macro('auRepos', fn (): object => $this);
        PHP;

    $methodes = texteEnSelecteurMethodes($code);

    expect($methodes)->toMatchArray(['attendreladisparition' => false, 'remplirlechamp' => true, 'choisirladuree' => false]);
    expect($methodes)->not->toHaveKeys(['viserlacible', 'sanstype', 'attendrelessecondes', 'aurepos']);

    expect(texteEnSelecteurAidesNonRangees($code))->toBe(['viserLaCible', 'sansType']);
});

it('range chaque aide du dépôt dont le premier paramètre peut être une chaîne', function (): void {
    expect(texteEnSelecteurAidesNonRangees())->toBe([]);

    expect(texteEnSelecteurPremiersParametresDesAides())
        ->toHaveKeys(array_keys(texteEnSelecteurAidesSansSelecteur()));
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
