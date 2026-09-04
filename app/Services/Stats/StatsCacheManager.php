<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\Models\User;

/**
 * Invalide les statistiques en cache d'un utilisateur. Les trois méthodes
 * de séance ne font plus qu'une chose, incrémenter la version des séances :
 * un renommage recalcule aussi le volume hebdomadaire à la prochaine lecture,
 * ce qui coûte une requête, contre une liste de clefs à tenir à jour.
 */
final class StatsCacheManager
{
    public function clearWorkoutMetadataStats(User $user): void
    {
        ClesDeStats::invaliderSeances($user);
    }

    public function clearVolumeStats(User $user): void
    {
        ClesDeStats::invaliderSeances($user);
    }

    public function clearWorkoutRelatedStats(User $user): void
    {
        ClesDeStats::invaliderSeances($user);
    }

    public function clearBodyMeasurementStats(User $user): void
    {
        ClesDeStats::invaliderMesures($user);
    }
}
