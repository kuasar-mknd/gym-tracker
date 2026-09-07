<?php

declare(strict_types=1);

/*
 * Le mode sombre a ete retire, et il ne doit pas revenir par inadvertance.
 *
 * Il a ete ecrit paire par paire : un composant nommait une couleur claire
 * (`bg-white`) et on lui ajoutait un jumeau (`dark:bg-slate-800`). Partout ou le
 * jumeau manquait, la valeur claire traversait — et RIEN ne pouvait s'en
 * apercevoir : la page s'affiche, les tests passent, seul l'oeil devant l'ecran
 * sait. Mesure avant le retrait : 238 utilitaires clairs sur 449 n'avaient aucun
 * jumeau de la meme famille, dans 49 fichiers sur 138.
 *
 * Le theme suivait `prefers-color-scheme` par defaut, donc n'importe quel
 * visiteur dont le systeme est en sombre recevait cette version-la sans l'avoir
 * demandee. C'est ce qui a decide du retrait plutot que d'une reparation
 * (#1580).
 *
 * Ce controle existe parce qu'un `dark:` se recopie tout seul : il suffit de
 * reprendre un bloc d'un autre projet, ou de suivre un exemple de la
 * documentation Tailwind. Sans lui, la variante reviendrait un composant a la
 * fois, et avec elle exactement le meme defaut — puisque plus rien, ni CSS ni
 * bascule, ne la ferait fonctionner.
 *
 * La decision est desormais prise et elle est definitive : il n'y aura pas de
 * mode sombre (#1806, ferme comme non planifie le 07/09/2026). Ce controle n'est
 * donc plus une precaution en attendant mieux, c'est la tenue d'un choix.
 *
 * Il a longtemps ete plus etroit que son titre. Il ne cherchait que les
 * utilitaires `dark:` et la bascule Tailwind, si bien qu'un theme sombre complet
 * a vecu des mois dans la page de la charte — monte sur `prefers-color-scheme`,
 * c'est-a-dire le mecanisme meme que l'en-tete ci-dessus designe comme la cause
 * du retrait — sans qu'aucune CI ne s'en apercoive. D'ou les deux controles
 * suivants.
 */

use Symfony\Component\Finder\Finder;

/**
 * @return array<string, list<string>>
 */
function utilitairesSombresParFichier(): array
{
    $fichiers = Finder::create()
        ->files()
        ->in([resource_path('js'), resource_path('css'), resource_path('views')])
        ->name(['*.vue', '*.js', '*.css', '*.blade.php']);

    $trouves = [];

    foreach ($fichiers as $fichier) {
        $contenu = $fichier->getContents();

        // `dark:` suivi d'un caractere de classe : `dark: 'valeur'` en objet
        // JavaScript n'en est pas un, et n'a rien a faire ici.
        preg_match_all('/(?<![\w:-])dark:[a-zA-Z0-9:_\/\[\]%#()-]+/', $contenu, $occurrences);

        if ($occurrences[0] !== []) {
            $trouves[$fichier->getRelativePathname()] = array_values(array_unique($occurrences[0]));
        }
    }

    return $trouves;
}

it('ne laisse revenir aucune variante sombre dans les sources', function (): void {
    $trouves = utilitairesSombresParFichier();

    $lignes = [];

    foreach ($trouves as $fichier => $utilitaires) {
        $lignes[] = sprintf('%s : %s', $fichier, implode(', ', array_slice($utilitaires, 0, 5)));
    }

    expect($trouves)->toBe([], sprintf(
        "Des variantes `dark:` sont revenues dans %d fichier(s) :\n  %s\n\n"
        ."Le mode sombre a ete retire (#1580) : il n'y a plus de bascule `.dark` sur la racine, "
        ."donc ces classes ne s'appliqueront jamais. Elles ne sont pas inoffensives pour autant — "
        .'elles donnent a lire une intention que le rendu ne suit pas, et c\'est ainsi que la '
        ."moitie des surfaces s'etait desynchronisee.\n\n"
        .'Pour retablir un mode sombre, passer par des jetons semantiques renverses dans '
        .'`app.css`, et retirer ce controle sciemment.',
        count($trouves),
        implode("\n  ", $lignes)
    ));
});

it('ne laisse pas revenir la bascule elle-meme', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect(str_contains($css, '@variant dark'))->toBeFalse(
        'La variante `dark` est redeclaree dans `app.css`. Sans elle, un `dark:` egare ne produit '
        .'aucune regle ; avec elle, il en produit une que rien ne declenche — ce qui est pire, '
        .'parce que le CSS grossit sans que rien ne change a l\'ecran.'
    );

    expect(preg_match('/(?<![\w&.-])\.dark\b/', $css))->toBe(0,
        'Un bloc `.dark` est revenu dans `app.css`. La classe n\'est plus posee sur la racine par '
        .'personne : ces regles seraient du CSS mort.'
    );
});

it('ne laisse pas revenir un theme sombre par la requete de media', function (): void {
    $fichiers = Finder::create()
        ->files()
        ->in([resource_path('js'), resource_path('css'), resource_path('views')])
        ->name(['*.vue', '*.js', '*.css', '*.blade.php']);

    $coupables = [];

    foreach ($fichiers as $fichier) {
        $contenu = $fichier->getContents();

        // `prefers-color-scheme: dark` renverse la page sans que personne ne
        // l'ait demande ; `[data-theme="dark"]` fait la meme chose sur un
        // attribut que plus rien ne pose.
        if (preg_match('/prefers-color-scheme\s*:\s*dark/i', $contenu) === 1
            || preg_match('/\[data-theme\s*=\s*[\'"]dark[\'"]\]/i', $contenu) === 1) {
            $coupables[] = $fichier->getRelativePathname();
        }
    }

    expect($coupables)->toBe([], sprintf(
        "Un theme sombre est revenu dans %d fichier(s) :\n  %s\n\n"
        .'Le theme suivait `prefers-color-scheme` par defaut, donc tout visiteur dont le systeme '
        .'est en sombre recevait cette version-la sans l\'avoir demandee : c\'est ce qui a decide '
        ."du retrait (#1580) plutot que d'une reparation.\n\n"
        .'Ce controle a ete ajoute parce qu\'il manquait : la page de la charte a porte une palette '
        .'sombre complete pendant des mois, sur la page meme qui explique pourquoi il n\'y en a plus.',
        count($coupables),
        implode("\n  ", $coupables)
    ));
});

it('declare au navigateur que l\'application n\'a qu\'un theme', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    // `toContain` prend ses arguments comme autant de chaines a chercher : un
    // message passe en second y devient un motif, et le controle echoue sur un
    // fichier pourtant conforme.
    expect(str_contains($css, 'color-scheme: light'))->toBeTrue(
        'Sans `color-scheme: light` sur `html`, un telephone regle en sombre laisse le navigateur '
        .'repeindre ce qu\'il dessine lui-meme : cases a cocher, listes deroulantes, ascenseurs et '
        .'champs de saisie sortent en sombre au milieu de surfaces claires. Ne plus supporter le '
        .'mode sombre, c\'est aussi le dire au navigateur.'
    );
});
