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
     *
     * Le nom et le début se tirent d'une seule lecture de l'horloge : avec une
     * lecture chacun, une séance ouverte quand minuit passait entre les deux
     * commençait la veille et portait le nom du lendemain (#2033).
     */
    public function execute(User $user): Workout
    {
        $maintenant = now();
        $workout = new Workout([
            'started_at' => $maintenant,
            'name' => $maintenant->format('d/m/Y'),
        ]);
        $workout->user_id = $user->id;
        $workout->save();

        $this->statsCache->clearWorkoutRelatedStats($user);

        return $workout;
    }
}
