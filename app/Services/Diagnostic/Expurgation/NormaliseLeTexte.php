<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Expurgation;

use Normalizer;

/**
 * Deuxième étape de l'expurgation : un texte normalisé en NFKC, ses `%xx`
 * et ses entités HTML décodés.
 *
 * NFKC ramène la pleine chasse (`ｊｅａｎ＠ｅｘａｍｐｌｅ．ｏｒｇ`) et les
 * formes de compatibilité à leurs caractères ordinaires, que les motifs des
 * étapes suivantes reconnaissent. Le décodage fait de `jean%40example.org`
 * un courriel ; il passe trois fois, pour un texte encodé deux fois
 * (`%2540`, `&amp;#64;`), une entité numérique sans point-virgule (`&#64`) fermée avant, un décodage faisant apparaître ce que l'autre
 * décode (`&#37;40`) ; ce qui devient invisible (`&#8203;`) part à l'étape
 * suivante. Puis le texte décodé est normalisé à nouveau : un `%xx` peut
 * cacher un caractère de pleine chasse. Un octet décodé qui ne forme pas
 * d'UTF-8 valide est remplacé, et ce qu'il ne fallait pas décoder (un
 * caractère de contrôle, `%1B`) part à l'étape suivante.
 */
final class NormaliseLeTexte
{
    /**
     * Les identifiants d'une URL (`://compte:mot de passe@`), tels que le
     * masque les lit.
     */
    private const string IDENTIFIANTS_D_URL = '/(?<=:\/\/)[^\s\/?#@"\'<>:]{0,64}:[^\s"\'<>]{1,256}@/u';

    /**
     * Un caractère écrit en `%xx` (un à quatre octets d'UTF-8) ou en entité.
     */
    private const string BLANC_ECRIT = '/%[0-7][0-9a-f]|%[c-d][0-9a-f]%[89ab][0-9a-f]|%e[0-9a-f](?:%[89ab][0-9a-f]){2}|%f[0-4](?:%[89ab][0-9a-f]){3}|&#\d{1,7};|&#x[0-9a-f]{1,6};|&[a-z][a-z0-9]{1,31};/i';

    public function __invoke(string $texte): string
    {
        return $this->nfkc($this->decoder($this->decoder($this->decoder($this->nfkc($texte)))));
    }

    private function decoder(string $texte): string
    {
        return html_entity_decode(
            mb_scrub(rawurldecode($this->sansBlancEncode($this->entitesNumeriquesFermees($texte))), 'UTF-8'),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );
    }

    /**
     * Une entité numérique sans point-virgule (`&#64`), que le navigateur lit
     * comme avec.
     */
    private function entitesNumeriquesFermees(string $texte): string
    {
        return Motifs::remplacer(
            '/&#(?:(\d{1,7})(?![\d;])|([xX][0-9a-fA-F]{1,6})(?![0-9a-fA-F;]))/',
            '&#$1$2;',
            $texte,
        );
    }

    /**
     * Un blanc écrit autrement qu'en clair dans les identifiants d'une URL
     * (`%20`, `%C2%A0`, `&#32;`, `&nbsp;`), que le décodage ou la
     * normalisation ferait finir le mot que le masque lit, devient un `_` : le
     * mot de passe est masqué en entier.
     */
    private function sansBlancEncode(string $texte): string
    {
        $remplace = preg_replace_callback(
            self::IDENTIFIANTS_D_URL,
            fn (array $identifiants): string => Motifs::remplacerParFonction(
                self::BLANC_ECRIT,
                fn (array $ecrit): string => $this->estUnBlanc($ecrit[0]) ? '_' : $ecrit[0],
                $identifiants[0],
            ),
            $texte,
        );
        Motifs::verifierLeDernierAppel();

        return $remplace ?? '';
    }

    private function estUnBlanc(string $ecrit): bool
    {
        $lu = $this->nfkc(html_entity_decode(mb_scrub(rawurldecode($ecrit), 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return Motifs::trouver('/^[\p{Z}\s]+$/u', $lu) !== null;
    }

    private function nfkc(string $texte): string
    {
        $normalise = Normalizer::normalize(mb_scrub($texte, 'UTF-8'), Normalizer::FORM_KC);

        return is_string($normalise) ? $normalise : throw new TexteIllisible('Normalisation NFKC refusée.');
    }
}
