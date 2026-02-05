<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;

class FetchExerciseHistoryAction
{
    /**
     * @return array<int, array{id: int, name: string, date: string, ago: string, sets: array<int, array{weight: float, reps: int}>}>
     */
    public function execute(User $user, Exercise $exercise): array
    {
        return $user->workouts()
            ->whereHas('workoutLines', fn ($query) => $query->where('exercise_id', $exercise->id))
            ->with(['workoutLines' => fn ($query) => $query->where('exercise_id', $exercise->id)->with('sets')])
            ->latest('started_at')
            ->take(10)
            ->get()
            ->map(function (Workout $workout) {
                /** @var \App\Models\WorkoutLine|null $line */
                $line = $workout->workoutLines->first();

                return [
                    'id' => $workout->id,
                    'name' => $workout->name,
                    // @phpstan-ignore-next-line
                    'date' => $workout->started_at ? $workout->started_at->format('d/m/Y') : '',
                    // @phpstan-ignore-next-line
                    'ago' => $workout->started_at ? $workout->started_at->diffForHumans() : '',
                    'sets' => $line?->sets->map(fn ($set) => [
                        'weight' => (float) $set->weight,
                        'reps' => (int) $set->reps,
                    ])->toArray() ?? [],
                ];
            })
            ->toArray();
    }
}
