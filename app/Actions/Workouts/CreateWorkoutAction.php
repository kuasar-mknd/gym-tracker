<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\User;
use App\Models\Workout;
use App\Services\Stats\StatsCacheManager;
use Carbon\CarbonInterface;

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
     * Le nom et le début se tirent d'un seul instant : avec une lecture de
     * l'horloge chacun, une séance ouverte quand minuit passait entre les
     * deux commençait la veille et portait le nom du lendemain (#2033).
     * Une séance démarrée sur l'appareil reçoit ce début de
     * `DemarrerUneSeanceAction`, déjà borné, et son nom en découle, lu dans le
     * fuseau de l'application, comme le début qu'Eloquent écrit (#2049). Sans
     * début, l'action lit l'horloge une fois. La clé d'idempotence se pose à
     * la main : elle nomme la tentative, jamais un champ du corps.
     */
    public function execute(User $user, ?CarbonInterface $debut = null, ?string $nom = null, ?string $cle = null): Workout
    {
        $debut = ($debut ?? now())->copy()->setTimezone(config()->string('app.timezone'));
        $workout = new Workout([
            'started_at' => $debut,
            'name' => $nom ?? $debut->format('d/m/Y'),
        ]);
        $workout->user_id = $user->id;
        $workout->idempotency_key = $cle;
        $workout->save();

        $this->statsCache->clearWorkoutRelatedStats($user);

        return $workout;
    }
}
