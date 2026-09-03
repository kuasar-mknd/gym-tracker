<?php

declare(strict_types=1);

namespace App\Models;

use Spatie\Activitylog\Models\Activity;

/**
 * Le journal d'activité du paquet, sous un nom de l'application : c'est ce
 * qui lui donne une policy découverte comme les autres (ActivityLogPolicy)
 * et une ressource Filament en lecture seule.
 */
class ActivityLog extends Activity
{
}
