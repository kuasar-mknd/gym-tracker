<?php

declare(strict_types=1);

namespace App\Actions\Measurements;

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
}
