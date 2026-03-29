<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\Workout;

class CreateWorkoutAction
{
    /**
     * Create a new workout and trigger stats recalculation.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data): Workout
    {
        $workout = new Workout($data);
        $workout->user_id = $user->id;
        $workout->save();

        \App\Jobs\RecalculateUserStats::dispatch($user);

        return $workout;
    }
}
