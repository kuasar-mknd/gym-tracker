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
     * The default name is the date, and nothing else.
     *
     * It used to be "Séance du 04/08/2026". Every session is a séance, so the
     * words carry no information — and they cost the part that does: the header
     * truncates, so the date, the one thing that tells two sessions apart, was
     * the half that got cut. A user who wants a real name gives it one from the
     * session settings.
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
