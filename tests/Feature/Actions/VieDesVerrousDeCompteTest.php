<?php

declare(strict_types=1);

use App\Actions\Fasting\DemarrerUnJeuneAction;
use App\Actions\Workouts\DemarrerUneSeanceAction;
use Illuminate\Support\Facades\Config;

/*
 * Un verrou qui expire pendant que sa section tourne laisse un second démarrage
 * passer : la requête la plus longue doit finir avant lui (#2293).
 */
it('garde chaque verrou de compte plus longtemps que la requête la plus longue', function (int $vie): void {
    expect($vie)->toBeGreaterThan(Config::integer('octane.max_execution_time'));
})->with([
    'séance' => DemarrerUneSeanceAction::VIE_DU_VERROU,
    'jeûne' => DemarrerUnJeuneAction::VIE_DU_VERROU,
]);
