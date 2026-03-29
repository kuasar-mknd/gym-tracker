<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Workout;
use Illuminate\Foundation\Auth\User;

class CreateWorkoutAction
{
    /**
     * Create a new workout and trigger stats recalculation.
     *
     * @param  User  $user
     * @param  array<string, mixed>  $data
     * @return Workout
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
