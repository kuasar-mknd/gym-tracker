<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Collection;

class FetchExerciseHistoryAction
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function execute(User $user, Exercise $exercise): Collection
    {
        return $user->workoutLines()
            ->with(['workout', 'sets'])
            ->where('exercise_id', $exercise->id)
            ->whereHas('workout', function ($query) {
                $query->whereNotNull('completed_at');
            })
            ->get()
            ->sortByDesc(fn ($line) => $line->workout->completed_at)
            ->map(function ($line) {
                return [
                    'id' => $line->id,
                    'date' => $line->workout->completed_at,
                    'workout_name' => $line->workout->name,
                    'sets' => $line->sets->map(function ($set) {
                        return [
                            'weight' => $set->weight,
                            'reps' => $set->reps,
                            'distance' => $set->distance,
                            'duration' => $set->duration,
                        ];
                    }),
                ];
            })
            ->values();
    }
}
