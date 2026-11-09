<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Diagnostic\Expurgation\MasqueLesSecrets;

/**
 * Un texte sans jeton de diagnostic ni valeur d'un en-tête `Bearer …` en
 * clair (#2032) : chaque chaîne d'une exception enregistrée
 * (`ExceptionEnregistree`), chaque ligne du journal (`FormateurSansAdresse`).
 *
 * Le jeton devient « [masqué] », la valeur « Bearer [masqué] », par les deux
 * motifs de l'expurgation du diagnostic (`MasqueLesSecrets::MOTIF_BEARER`,
 * `MOTIF_DU_JETON`), une seule source. Ses autres motifs (suites
 * hexadécimales, base64, JWT) n'en sont pas : ils prendraient pour des
 * secrets les empreintes et les fichiers du build qu'une exception ou une
 * ligne du journal citent. Un mot qui suit `Bearer` et un blanc est masqué
 * aussi, faute de pouvoir le distinguer d'un secret ; `bearerToken`,
 * `BearerTokenGuard` et `Bearer` suivi d'une ponctuation restent.
 *
 * Le motif de `Bearer` lit au plus 4 096 octets : la suite qu'il n'a pas lue
 * est retirée ensuite, jusqu'au premier blanc, guillemet, apostrophe ou
 * chevron, d'un trait et sans retour en arrière. Sans quoi la fin d'une
 * valeur plus longue resterait lisible, avec un jeton collé à sa fin, dont
 * le motif avalait le début et que le motif du jeton ne reconnaissait plus,
 * et le motif, qui lit des octets, laisserait un caractère coupé, que ni
 * MySQL ni le JSON n'accepteraient. Un texte que l'expression ne sait pas
 * lire est masqué en entier.
 *
 * Le masque reconnaît le jeton à sa forme, sans lire aucune table : il vaut
 * avant que les jetons existent.
 */
final class TexteSansJeton
{
    /** Ce qui remplace un jeton, une valeur `Bearer`, ou un texte illisible. */
    public const string MASQUE = '[masqué]';

    /**
     * Le texte, chaque jeton et chaque valeur `Bearer` masqués.
     */
    public static function de(string $texte): string
    {
        return preg_replace(
            [
                MasqueLesSecrets::MOTIF_BEARER,
                '/\bBearer '.preg_quote(self::MASQUE, '/').'\K[^\s"\'<>]++/',
                MasqueLesSecrets::MOTIF_DU_JETON,
            ],
            ['Bearer '.self::MASQUE, '', self::MASQUE],
            $texte,
        ) ?? self::MASQUE;
    }
}
