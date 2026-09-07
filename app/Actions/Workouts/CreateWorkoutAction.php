<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\User;
use App\Models\Workout;
use App\Services\Stats\StatsCacheManager;

class CreateWorkoutAction
{
    public function __construct(protected StatsCacheManager $statsCache)
    {
    }

    /**
     * Le nom par défaut est la date, et rien d'autre.
     *
     * C'était « Séance du 04/08/2026 ». Toute séance est une séance : ces mots
     * n'apprennent rien, et ils coûtent la part qui apprend quelque chose. L'en-tête
     * tronque, donc la date — la seule chose qui distingue deux séances — était
     * la moitié coupée. Qui veut un vrai nom le donne depuis les réglages de la
     * séance.
     */
    public function execute(User $user): Workout
    {
        $workout = new Workout([
            'started_at' => now(),
            'name' => now()->format('d/m/Y'),
        ]);
        $workout->user_id = $user->id;
        $workout->save();

        $this->statsCache->clearWorkoutRelatedStats($user);

        return $workout;
    }
}
