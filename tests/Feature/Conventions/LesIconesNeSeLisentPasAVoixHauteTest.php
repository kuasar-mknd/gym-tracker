<?php

declare(strict_types=1);

/*
 * Une icône Material Symbols se lit à voix haute si on ne l'en empêche pas.
 *
 * La police fonctionne par ligature : le glyphe « calendrier » s'obtient en
 * écrivant `calendar_month` dans l'élément. C'est du texte, et un lecteur
 * d'écran le prononce — « person Profil », « settings Paramètres ».
 *
 * 84 des 141 occurrences ne portaient aucun attribut. Le correctif est
 * `aria-hidden="true"` quand un libellé accompagne l'icône, et `aria-label`
 * quand elle est seule à porter le sens. Les deux sont acceptés ici : ce
 * contrôle refuse l'absence des deux, il ne choisit pas entre eux.
 *
 * Vérifié au navigateur avant d'être posé : sur `/calendar` et `/tools`,
 * masquer toutes les icônes ne prive aucun bouton ni lien de son nom
 * accessible — ceux qui n'ont qu'une icône portaient déjà un `aria-label`.
 */

it('n’expose aucune icône sans dire ce qu’elle est', function (): void {
    $fichiers = glob(resource_path('js/**/*.vue'), GLOB_BRACE);
    $tous = [];

    // `glob()` ne descend pas récursivement, d'où l'itérateur.
    $iterateur = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS)
    );

    /** @var \SplFileInfo $fichier */
    foreach ($iterateur as $fichier) {
        if ($fichier->getExtension() === 'vue') {
            $tous[] = $fichier->getPathname();
        }
    }

    expect($tous)->not->toBeEmpty('aucun composant trouvé : le contrôle ne prouverait rien');

    $nues = [];

    foreach ($tous as $chemin) {
        $source = (string) file_get_contents($chemin);

        /*
         * La balise ouvrante, en tolérant les sauts de ligne — Prettier répartit
         * les attributs sur plusieurs lignes dès qu'ils sont nombreux. Le motif
         * s'arrête au premier `>` qui n'est pas dans une valeur entre guillemets,
         * sans quoi il coupe au milieu d'une expression comme `${a > b}`.
         */
        preg_match_all('/<span(?:[^>"\']|"[^"]*"|\'[^\']*\')*?>/s', $source, $balises);

        foreach ($balises[0] as $balise) {
            if (! str_contains($balise, 'material-symbols-outlined')) {
                continue;
            }

            if (str_contains($balise, 'aria-hidden') || str_contains($balise, 'aria-label')) {
                continue;
            }

            $nues[] = str_replace(resource_path('js').'/', '', $chemin);
        }
    }

    expect(array_values(array_unique($nues)))->toBe([], sprintf(
        "Ces composants rendent une icône sans `aria-hidden` ni `aria-label` :\n  %s\n\n"
        .'La police est ligaturée : le nom du glyphe EST le texte de l’élément, et un lecteur '
        .'d’écran le prononce. Poser `aria-hidden=\"true\"` quand un libellé accompagne l’icône, '
        .'`aria-label` quand elle porte seule le sens.',
        implode("\n  ", array_unique($nues))
    ));
});
