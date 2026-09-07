<?php

declare(strict_types=1);

/**
 * Le code de cette application s'écrit en français.
 *
 * Il mêlait deux langues, souvent dans le même fichier : commentaire anglais
 * sur une méthode française, et l'inverse (#1810). Chaque contributeur — humain
 * ou agent — choisissait au hasard, et une recherche par mot-clef ratait la
 * moitié des occurrences.
 *
 * La garde compte les mots grammaticaux, pas le vocabulaire : « set », « user »
 * ou « offline » se disent en anglais dans une phrase française. Ce qui trahit
 * l'anglais, ce sont ses articles et ses auxiliaires.
 */
$motsAnglais = '/(?<![$@\\\\])\b(the|of|is|are|which|that|for|with|not|this|by|but|when|where|been|does|their|its|from|and|as|has|have|was|were|would|should|could)\b/i';
$motsFrancais = '/\b(le|la|les|une?|des|du|est|sont|qui|que|dans|pour|avec|sur|pas|plus|ce|cette|par|mais|donc|alors|quand|où|à|été|être|fait|même|leur|son|sa|ses|ne|au|aux|se|ça|elle|il)\b/iu';

it('écrit ses commentaires en français', function () use ($motsAnglais, $motsFrancais): void {
    $anglais = [];

    /** @var \SplFileInfo $fichier */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $fichier) {
        if ($fichier->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());
        $commentaires = '';

        foreach (token_get_all($source) as $jeton) {
            if (is_array($jeton) && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $commentaires .= ' '.$jeton[1];
            }
        }

        $en = (int) preg_match_all($motsAnglais, $commentaires);
        $fr = (int) preg_match_all($motsFrancais, $commentaires);

        if ($en > 3 && $en > $fr * 1.5) {
            $anglais[] = str_replace(base_path().'/', '', $fichier->getPathname())." ({$en} mots anglais, {$fr} français)";
        }
    }

    sort($anglais);

    expect($anglais)->toBe([], 'ces fichiers commentent en anglais ; la règle du dépôt est le français (.ai/rules/general.md)');
});
