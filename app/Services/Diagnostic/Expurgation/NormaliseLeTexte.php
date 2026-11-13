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

    public function __invoke(string $texte): string
    {
        return $this->nfkc($this->decoder($this->decoder($this->decoder($this->nfkc($texte)))));
    }

    private function decoder(string $texte): string
    {
        return html_entity_decode(
            rawurldecode($this->sansBlancEncode($this->entitesNumeriquesFermees($texte))),
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
     * Un blanc écrit en `%xx` dans les identifiants d'une URL, décodé,
     * finirait le mot que le masque lit : il devient un `_`, le mot de passe
     * étant masqué en entier.
     */
    private function sansBlancEncode(string $texte): string
    {
        $remplace = preg_replace_callback(
            self::IDENTIFIANTS_D_URL,
            static fn (array $identifiants): string => preg_replace('/%(?:20|09|0[a-d])/i', '%5F', $identifiants[0]) ?? '',
            $texte,
        );
        Motifs::verifierLeDernierAppel();

        return $remplace ?? '';
    }

    private function nfkc(string $texte): string
    {
        $normalise = Normalizer::normalize(mb_scrub($texte, 'UTF-8'), Normalizer::FORM_KC);

        return is_string($normalise) ? $normalise : throw new TexteIllisible('Normalisation NFKC refusée.');
    }
}
