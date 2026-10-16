<?php

declare(strict_types=1);

namespace App\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * L'avatar par défaut du panneau, de Pulse et de Telescope : les initiales du
 * nom, dessinées en SVG par l'application et servies en `data:`, sans requête.
 *
 * Filament prend le sien chez un service d'avatars tiers, dont l'adresse porte
 * les initiales du nom de l'administrateur : chaque page du panneau les lui
 * envoyait, avec l'adresse IP et le navigateur de qui la lisait. Pulse prenait
 * ceux de sa carte « Usage » chez d'autres (`ComptesVusParPulse`), et Telescope
 * celui du détail d'une entrée (`TelescopeServiceProvider`). Tous passent
 * désormais par cette classe.
 *
 * Seules des lettres entrent dans le dessin : la première lettre du premier et
 * du dernier mot du nom, rien d'autre, échappées malgré tout. Un nom sans
 * lettre (vide, chiffres, emojis) donne une silhouette. Les couleurs sont
 * celles d'un utilitaire apparié de la charte, `accent-fill`, dont
 * `UnFondEtSonTexteSeLisentTest` tient le contraste : l'avatar ne choisit pas
 * lui-même la couleur de son texte.
 */
final class AvatarDesInitiales implements AvatarProvider
{
    /**
     * L'utilitaire apparié de la charte dont l'avatar prend le fond et le
     * texte.
     */
    private const string SURFACE = 'accent-fill';

    /**
     * Le carré de fond, que le panneau et Pulse arrondissent en disque : sa
     * couleur, puis ce qu'il porte.
     */
    private const string DESSIN = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><rect width="64" height="64" fill="%s"/>%s</svg>';

    /**
     * Les initiales, en police du système : la couleur du texte, puis les
     * lettres, échappées.
     */
    private const string INITIALES = '<text x="32" y="32" dy="0.35em" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="26" font-weight="600" fill="%1$s">%2$s</text>';

    /**
     * La silhouette d'un nom sans lettre, de la couleur du texte.
     */
    private const string SILHOUETTE = '<circle cx="32" cy="25" r="10" fill="%1$s"/><path d="M14 52c0-10 8-16 18-16s18 6 18 16z" fill="%1$s"/>';

    /**
     * Le contrat de Filament : l'avatar d'un administrateur, d'après le nom
     * que le panneau affiche pour lui.
     */
    public function get(Model|Authenticatable $record): string
    {
        return $this->pourLeNom(Filament::getNameForDefaultAvatar($record));
    }

    /**
     * L'avatar d'un nom, en URL `data:` : le SVG encodé en base64, si bien
     * qu'aucun caractère du dessin ne touche l'attribut qui le porte.
     */
    public function pourLeNom(string $nom): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->dessin(self::initiales($nom)));
    }

    /**
     * La première lettre du premier mot et celle du dernier, en capitales.
     *
     * Un mot commence après une espace ; ce qui précède sa première lettre
     * (crochets, apostrophe, chiffres) est sauté, et un mot sans lettre ne
     * compte pas. Un nom sans lettre, ou qui n'est pas de l'UTF-8 valide (le
     * motif échoue alors), n'en donne aucune.
     */
    public static function initiales(string $nom): string
    {
        if ((int) preg_match_all('/(?:^|\s)[^\p{L}\s]*(\p{L})/u', $nom, $trouvees) === 0) {
            return '';
        }

        $lettres = $trouvees[1];
        $initiales = count($lettres) > 1 ? $lettres[0].end($lettres) : $lettres[0];

        return mb_convert_case($initiales, MB_CASE_UPPER_SIMPLE, 'UTF-8');
    }

    /**
     * Le SVG : le carré de fond, et les initiales, ou une silhouette quand il
     * n'y en a pas.
     */
    private function dessin(string $initiales): string
    {
        $surface = Charte::surfacesAppariees()[self::SURFACE]
            ?? throw new RuntimeException('L’utilitaire apparié `'.self::SURFACE.'` a disparu de la charte : l’avatar n’a plus de couleurs.');

        $fond = self::echappe(Charte::jeton($surface['fond']));
        $texte = self::echappe(Charte::jeton($surface['texte']));

        $contenu = $initiales === ''
            ? sprintf(self::SILHOUETTE, $texte)
            : sprintf(self::INITIALES, $texte, self::echappe($initiales));

        return sprintf(self::DESSIN, $fond, $contenu);
    }

    private static function echappe(string $valeur): string
    {
        return htmlspecialchars($valeur, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
