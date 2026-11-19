<?php

declare(strict_types=1);

namespace App\Support;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\NotPwnedVerifier;

/**
 * La recherche des fuites publiques, bornée à trois secondes ; un échec
 * l'ignore une minute et ne s'enregistre pas.
 */
final class VerificateurDeMotsDePasseDivulgues extends NotPwnedVerifier
{
    public const int DELAI_EN_SECONDES = 3;

    public const int PAUSE_APRES_UN_ECHEC_EN_SECONDES = 60;

    public const string CLEF_DE_LA_PAUSE = 'mots-de-passe-divulgues:en-panne';

    /**
     * @param  string  $hashPrefix
     * @return Collection<int, string>
     */
    #[\Override]
    protected function search($hashPrefix): Collection
    {
        if (Cache::has(self::CLEF_DE_LA_PAUSE)) {
            return new Collection();
        }

        try {
            $reponse = $this->factory->withHeaders(['Add-Padding' => 'true'])
                ->connectTimeout(self::DELAI_EN_SECONDES)
                ->timeout(self::DELAI_EN_SECONDES)
                ->get('https://api.pwnedpasswords.com/range/'.$hashPrefix);
        } catch (Exception) {
            return $this->suspendre();
        }

        if (! $reponse->successful()) {
            return $this->suspendre();
        }

        /** @var list<string> $lignes */
        $lignes = [];

        foreach (explode("\n", trim($reponse->body())) as $ligne) {
            if (str_contains($ligne, ':')) {
                $lignes[] = $ligne;
            }
        }

        return new Collection($lignes);
    }

    /**
     * @return Collection<int, string>
     */
    private function suspendre(): Collection
    {
        Cache::put(self::CLEF_DE_LA_PAUSE, true, self::PAUSE_APRES_UN_ECHEC_EN_SECONDES);

        return new Collection();
    }
}
