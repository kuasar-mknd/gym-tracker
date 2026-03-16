<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;

final class CreateExerciseAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data): Exercise
    {
        $exercise = new Exercise($data);
        $exercise->user_id = $user->id;
        $exercise->save();

        // Explicitly invalidate cache to ensure UI updates immediately
        $exercise->invalidateCache();

        return $exercise;
    }
}
