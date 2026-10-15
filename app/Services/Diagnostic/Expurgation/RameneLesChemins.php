<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Expurgation;

/**
 * Huitième étape de l'expurgation : un chemin absolu ramené à la racine de
 * l'application (`app/Models/User.php`), ou remplacé par `[chemin]` s'il est
 * ailleurs sur la machine.
 *
 * Un chemin absolu de la machine dit comment elle est rangée ; un chemin du
 * dépôt est public. Un chemin d'URL n'est pas visé : il suit un hôte (déjà
 * marqué), pas un blanc, une apostrophe, une parenthèse, `=`, `:` ou `,`.
 */
final class RameneLesChemins
{
    private const string CHEMIN_ABSOLU = '/(?<![^\s\'"(=:,\[])\/(?:[\w.@-]{1,255}\/){1,32}[\w.@-]{0,255}/u';

    public function __invoke(string $texte): string
    {
        return Motifs::remplacer(self::CHEMIN_ABSOLU, '[chemin]', str_replace(base_path().'/', '', $texte));
    }
}
