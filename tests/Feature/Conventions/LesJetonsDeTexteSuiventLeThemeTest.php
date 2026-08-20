<?php

declare(strict_types=1);

/*
 * Un jeton de couleur de texte doit basculer avec le thème.
 *
 * Le dépôt porte DEUX familles de variables, et elles ne sont pas la même
 * chose :
 *
 *  - `--color-text-muted`, déclarée dans le bloc `@theme`. C'est celle que
 *    Tailwind lit pour produire `.text-text-muted { color: var(...) }`, donc
 *    celle que le HTML utilise réellement — 363 fois pour la seule
 *    `text-text-muted` ;
 *  - `--text-muted`, une variable historique lue par les règles CSS écrites à
 *    la main.
 *
 * Seule la seconde était renversée dans le bloc `.dark`. Les 363 usages
 * gardaient donc leur gris clair sur un fond sombre : mesuré au navigateur,
 * 1,96:1 là où la norme AA demande 4,5:1 pour du texte courant. Ce n'était pas
 * « un peu juste », c'était illisible, et ça expliquait à soi seul l'essentiel
 * du grief sur le mode sombre (#1317).
 *
 * Le piège est qu'un tel oubli ne casse rien : la page s'affiche, les tests
 * passent, et seul l'œil devant l'écran sait. D'où ce contrôle.
 */

/**
 * Les noms de variables déclarés dans un bloc.
 *
 * @return list<string>
 */
function variablesDeclarees(string $css, string $ouvrant): array
{
    $debut = strpos($css, $ouvrant);

    if ($debut === false) {
        return [];
    }

    // Du bloc jusqu'à sa fermeture : les blocs concernés n'imbriquent rien.
    $fin = strpos($css, "\n    }", $debut);
    $bloc = substr($css, $debut, $fin === false ? null : $fin - $debut);

    preg_match_all('/(--[a-z0-9-]+)\s*:/', $bloc, $trouves);

    return array_values(array_unique($trouves[1]));
}

it('renverse en mode sombre chaque jeton de couleur de texte', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    $duTheme = array_values(array_filter(
        variablesDeclarees($css, '@theme {'),
        static fn (string $nom): bool => str_starts_with($nom, '--color-text-')
    ));

    expect($duTheme)->not->toBeEmpty('aucun jeton `--color-text-*` trouvé : le contrôle ne prouverait rien');

    $duSombre = variablesDeclarees($css, '.dark {');
    $oublies = array_values(array_diff($duTheme, $duSombre));

    expect($oublies)->toBe([], sprintf(
        "Ces jetons ne basculent pas en mode sombre :\n  %s\n\n"
        .'Tailwind produit `.text-…` à partir de `--color-…`, pas de la variable historique du '
        .'même nom. Un jeton déclaré dans `@theme` et absent de `.dark` garde sa valeur claire '
        .'sur fond sombre, sans que rien ne casse.',
        implode("\n  ", $oublies)
    ));
});

/*
 * Et les deux familles ne doivent plus se répéter.
 *
 * C'est en portant chacune sa propre valeur littérale qu'elles ont pu diverger.
 * La variable historique référence désormais le jeton, ce qui rend l'écart
 * impossible plutôt qu'improbable.
 */
it('fait pointer les variables historiques vers les jetons, plutôt que de les recopier', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    foreach (['text-main', 'text-muted'] as $nom) {
        // `toContain()` prend des aiguilles, pas un message : lui passer les
        // deux fait chercher le message dans le fichier.
        expect(str_contains($css, "--{$nom}: var(--color-{$nom});"))->toBeTrue(
            "`--{$nom}` doit référencer `--color-{$nom}` plutôt que recopier sa valeur : "
            .'c\'est en portant chacune la sienne que les deux familles ont divergé.'
        );
    }
});
