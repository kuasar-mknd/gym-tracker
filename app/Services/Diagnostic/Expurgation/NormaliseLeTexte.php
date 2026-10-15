<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Expurgation;

use Normalizer;

/**
 * Deuxième étape de l'expurgation : un texte normalisé en NFKC, ses `%xx`
 * décodés.
 *
 * NFKC ramène la pleine chasse (`ｊｅａｎ＠ｅｘａｍｐｌｅ．ｏｒｇ`) et les
 * formes de compatibilité à leurs caractères ordinaires, que les motifs des
 * étapes suivantes reconnaissent. Le décodage fait de `jean%40example.org`
 * un courriel ; il passe trois fois, pour un texte encodé deux fois
 * (`%2540`), puis le texte décodé est normalisé à nouveau : un `%xx` peut
 * cacher un caractère de pleine chasse. Un octet décodé qui ne forme pas
 * d'UTF-8 valide est remplacé, et ce qu'il ne fallait pas décoder (un
 * caractère de contrôle, `%1B`) part à l'étape suivante.
 */
final class NormaliseLeTexte
{
    public function __invoke(string $texte): string
    {
        return $this->nfkc(rawurldecode(rawurldecode(rawurldecode($this->nfkc($texte)))));
    }

    private function nfkc(string $texte): string
    {
        $normalise = Normalizer::normalize(mb_scrub($texte, 'UTF-8'), Normalizer::FORM_KC);

        return is_string($normalise) ? $normalise : throw new TexteIllisible('Normalisation NFKC refusée.');
    }
}
