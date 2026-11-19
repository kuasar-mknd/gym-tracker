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
        return Cache::has(self::CLEF_DE_LA_PAUSE) ? new Collection() : $this->interroger($hashPrefix);
    }

    /**
     * @return Collection<int, string>
     */
    private function interroger(string $hashPrefix): Collection
    {
        try {
            $corps = $this->factory->withHeaders(['Add-Padding' => 'true'])
                ->connectTimeout(self::DELAI_EN_SECONDES)
                ->timeout(self::DELAI_EN_SECONDES)
                ->get('https://api.pwnedpasswords.com/range/'.$hashPrefix)
                ->throw()
                ->body();
        } catch (Exception) {
            return $this->suspendre();
        }

        return new Collection($this->lignes($corps));
    }

    /**
     * @return list<string>
     */
    private function lignes(string $corps): array
    {
        return array_values(array_filter(
            explode("\n", trim($corps)),
            static fn (string $ligne): bool => str_contains($ligne, ':'),
        ));
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
