<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\User;
use App\Models\Workout;
use App\Services\StatsService;

class CreateWorkoutAction
{
    public function __construct(protected StatsService $statsService)
    {
    }

    public function execute(User $user): Workout
    {
        $workout = new Workout([
            'started_at' => now(),
            'name' => 'Séance du '.now()->format('d/m/Y'),
        ]);
        $workout->user_id = $user->id;
        $workout->save();

        $this->statsService->clearWorkoutRelatedStats($user);
        $this->statsService->clearWorkoutMetadataStats($user);

        return $workout;
    }
}
