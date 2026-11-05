<?php

declare(strict_types=1);

namespace App\Support;

/**
 * La forme canonique d'une adresse de compte : son domaine en ASCII (#2168).
 *
 * Un domaine internationalisé s'écrit sous deux formes qui désignent la même
 * boîte : Unicode (« jean@bücher.example.org ») et ASCII
 * (« jean@xn--bcher-kva.example.org »). La collation de `users.email`
 * (`utf8mb4_unicode_ci`) ne les rapproche pas, mais rapproche « bücher » de
 * « bucher », un autre domaine. Le compte n'était donc trouvé que sous la
 * forme où il était enregistré, et l'index unique en admettait un par forme.
 * Chromium envoie l'ASCII depuis un champ `type="email"`, un champ texte ou
 * un client hors navigateur la saisie telle quelle.
 *
 * La forme canonique est l'ASCII : celle que l'inscription enregistre depuis
 * #2001 et que Chromium envoie, et entre deux adresses en ASCII la collation
 * ne confond que la casse. L'écriture (inscription, profil, panneau,
 * connexion sociale) comme la recherche (connexion, « mot de passe oublié »,
 * réinitialisation, connexion sociale) passent par ici ; la base cherche
 * ensuite comme avant, selon sa collation. La conversion ne lit pas la base
 * et vaut pour toute saisie, qu'elle ait un compte ou non.
 *
 * Seul un domaine qui porte un caractère hors ASCII change, par UTS #46 non
 * transitoire, avec les règles STD3 : sans elles, la conversion ferait d'un
 * « ＠ » pleine chasse du domaine un « @ », et l'adresse changerait de
 * domaine. Restent tels qu'ils sont tapés :
 *
 *  - la partie locale, tout ce qui précède le dernier @ : elle n'a pas de
 *    forme ASCII ;
 *  - un domaine en ASCII, capitales comprises ;
 *  - un domaine que la conversion refuse (commentaire, tiret bas, label vide
 *    ou trop long, blanc, octet hors UTF-8) ;
 *  - un domaine dont la conversion ne donne aucun label `xn--` (une lettre
 *    pleine chasse, le signe kelvin à la place d'un « k ») : il n'était
 *    internationalisé qu'en apparence, la collation le rapproche déjà de
 *    l'ASCII, et la connexion sociale le refuse plutôt que de le replier ;
 *  - un domaine que l'ancien traitement IDNA, transitoire, écrit autrement
 *    (« ß », « ς », U+200C, U+200D) : Chromium envoie « strasse » d'un
 *    « straße » tapé, que la collation tient pour le même, quand la
 *    conversion non transitoire écrit « xn--strae-oqa ». Converti, un compte
 *    « sam@straße.example.org » ne serait plus trouvé depuis Chromium.
 */
final class AdresseCanonique
{
    /**
     * UTS #46 non transitoire, règles STD3, et les deux contrôles que la
     * RFC 5893 (bidi) et la RFC 5892 (CONTEXTJ) demandent.
     */
    private const int CONVERSION = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;

    /**
     * Le même traitement, transitoire : celui qui écrit « ß » en « ss ».
     */
    private const int ANCIEN_TRAITEMENT = IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;

    /**
     * L'adresse, son domaine écrit en ASCII quand il est internationalisé ;
     * telle quelle sinon.
     */
    public static function de(string $adresse): string
    {
        $arobase = strrpos($adresse, '@');

        if ($arobase === false) {
            return $adresse;
        }

        $domaine = substr($adresse, $arobase + 1);

        if (preg_match('/[\x80-\xFF]/', $domaine) !== 1) {
            return $adresse;
        }

        $converti = self::versLAscii($domaine, self::CONVERSION);

        if ($converti === null
            || $converti !== self::versLAscii($domaine, self::ANCIEN_TRAITEMENT)
            || ! self::porteUnLabelInternationalise($converti)) {
            return $adresse;
        }

        return substr($adresse, 0, $arobase + 1).$converti;
    }

    /**
     * Le domaine en ASCII sous ces options, ou null quand la conversion le
     * refuse.
     */
    private static function versLAscii(string $domaine, int $options): ?string
    {
        $details = [];
        $converti = idn_to_ascii($domaine, $options, INTL_IDNA_VARIANT_UTS46, $details);

        return is_string($converti) && is_array($details) && ($details['errors'] ?? null) === 0 ? $converti : null;
    }

    /**
     * Un label du domaine converti est-il un label internationalisé (`xn--`) ?
     */
    private static function porteUnLabelInternationalise(string $domaine): bool
    {
        return array_any(explode('.', $domaine), static fn (string $label): bool => str_starts_with($label, 'xn--'));
    }
}
