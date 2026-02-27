<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Set;
use App\Models\User;
use App\Models\WorkoutLine;
use App\Services\StatsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class CreateSetAction
{
    public function __construct(
        protected StatsService $statsService
    ) {
    }

    /**
     * Create a new set for a workout line.
     *
     * @param  User  $user  The authenticated user.
     * @param  array  $data  The validated data (must contain 'workout_line_id').
     * @return Set
     *
     * @throws AuthorizationException
     */
    public function execute(User $user, array $data): Set
    {
        $workoutLineId = $data['workout_line_id'];
        $workoutLine = WorkoutLine::findOrFail($workoutLineId);

        if (! Gate::forUser($user)->allows('create', [Set::class, $workoutLine])) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        // Remove workout_line_id from data before creation
        unset($data['workout_line_id']);

        /** @var Set $set */
        $set = $workoutLine->sets()->create($data);

        $this->statsService->clearWorkoutRelatedStats($user);

        return $set;
    }
}
