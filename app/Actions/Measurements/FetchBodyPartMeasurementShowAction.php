<?php

declare(strict_types=1);

namespace App\Actions\Measurements;

use App\Models\BodyPartMeasurement;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class FetchBodyPartMeasurementShowAction
{
    /**
     * Environ quatre ans a raison d'une mesure par semaine : la page trace un
     * point et rend une carte par ligne, elle grandissait donc sans fin.
     */
    private const int POINTS_MAX = 200;

    /**
     * Fetch the measurement history for a specific body part.
     *
     * Les plus recentes sont prises par l'index, puis remises dans l'ordre
     * croissant que la courbe attend — un `order by asc` avec `limit` aurait
     * rendu les plus ANCIENNES.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\BodyPartMeasurement>
     */
    public function execute(User $user, string $part): Collection
    {
        $recentes = $user->bodyPartMeasurements()
            ->where('part', $part)
            ->orderBy('measured_at', 'desc')
            ->limit(self::POINTS_MAX)
            ->get();

        return $recentes->reverse()->values();
    }

    /**
     * Le nom de la partie en tête de sa page, comme sur sa carte : une
     * « Taille » saisie à la main se distingue de `Waist` quand le compte
     * mesure les deux (#1974). La clef n'est cherchée que pour le nom français
     * d'une partie proposée, et parmi les mesures du compte.
     */
    public function libelle(User $user, string $part): string
    {
        $clef = BodyPartMeasurement::clefDePartie($part);

        if ($clef === $part) {
            return BodyPartMeasurement::libelle($part);
        }

        $laClefEstMesuree = $user->bodyPartMeasurements()->where('part', $clef)->exists();

        return BodyPartMeasurement::libelleParmi($part, $laClefEstMesuree ? [$clef] : []);
    }
}
