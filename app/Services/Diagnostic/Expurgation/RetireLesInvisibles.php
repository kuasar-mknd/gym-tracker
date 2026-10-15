<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Expurgation;

use App\Services\Diagnostic\TexteExpurge;

/**
 * Troisième étape de l'expurgation, et la seule qui s'applique aussi aux
 * champs structurels de l'enveloppe : un texte sans ce qui ne se voit pas.
 *
 * Un caractère invisible porte une consigne qu'un lecteur humain ne voit
 * pas, mais qu'un modèle lit : les caractères d'étiquette (U+E0000 à
 * U+E007F) écrivent en clair sans rien afficher. Sont retirés : les séquences
 * ANSI entières, toute la catégorie Unicode C (contrôles, formats, dont les
 * étiquettes, les espaces de largeur nulle et les marques de direction,
 * privés, non attribués) sauf le saut de ligne et la tabulation, les
 * sélecteurs de variante, et les caractères de remplissage qui ne sont pas
 * de la catégorie C (remplissages hangul, braille vierge, séparateurs de
 * ligne et de paragraphe, joint de graphème). Le compte de ce qui est retiré
 * est rendu avec le texte : la page de l'aperçu le montre.
 */
final class RetireLesInvisibles
{
    private const string SEQUENCES_ANSI = '/\x1B(?:\[[0-?]{0,32}[ -\/]{0,8}[@-~]|\][^\x07\x1B]{0,1024}(?:\x07|\x1B\\\\))/';

    private const string INVISIBLES = '/[^\P{C}\n\t]|[\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}\x{180B}-\x{180F}]|[\x{115F}\x{1160}\x{3164}\x{FFA0}\x{2800}\x{034F}\x{17B4}\x{17B5}\x{1D159}\x{1D173}-\x{1D17A}\x{2028}\x{2029}]/u';

    public function __invoke(string $texte): TexteExpurge
    {
        $valide = mb_scrub($texte, 'UTF-8');
        $visible = Motifs::remplacer(self::INVISIBLES, '', Motifs::remplacer(self::SEQUENCES_ANSI, '', $valide));

        return new TexteExpurge($visible, mb_strlen($valide) - mb_strlen($visible));
    }
}
