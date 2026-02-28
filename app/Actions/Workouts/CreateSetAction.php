<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Set;
use App\Models\User;
use App\Models\WorkoutLine;
use App\Services\StatsService;
use Illuminate\Support\Arr;

class CreateSetAction
{
    public function __construct(
        protected StatsService $statsService
    ) {
    }

    /**
     * Execute the action to create a new set and clear related stats.
     *
     * @param  User  $user  The authenticated user.
     * @param  WorkoutLine  $workoutLine  The workout line the set belongs to.
     * @param  array<string, mixed>  $data  The validated data for the set.
     * @return Set The newly created set.
     */
    public function execute(User $user, WorkoutLine $workoutLine, array $data): Set
    {
        $setData = Arr::except($data, ['workout_line_id']);

        $set = $workoutLine->sets()->create($setData);

        $this->statsService->clearWorkoutRelatedStats($user);

        return $set;
    }
}
