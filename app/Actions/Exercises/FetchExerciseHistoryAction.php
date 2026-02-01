<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FetchExerciseHistoryAction
{
    /**
     * @return Collection<int, array>
     */
    public function execute(User $user, Exercise $exercise): Collection
    {
        return $exercise->workoutLines()
            ->with(['workout' => function ($query): void {
                $query->select('id', 'name', 'started_at', 'ended_at');
            }, 'sets'])
            ->whereHas('workout', function (Builder $query) use ($user): void {
                $query->where('user_id', $user->id)
                    ->whereNotNull('ended_at');
            })
            ->get()
            ->sortByDesc('workout.started_at')
            ->values()
            ->map(fn ($line): array => [
                'id' => $line->id,
                'workout_id' => $line->workout->id,
                'workout_name' => $line->workout->name,
                'date' => $line->workout->started_at->format('Y-m-d'),
                'formatted_date' => $line->workout->started_at->format('d/m/Y'),
                'sets' => $line->sets->map(fn ($set): array => [
                    'weight' => $set->weight,
                    'reps' => $set->reps,
                    '1rm' => $set->weight * (1 + $set->reps / 30),
                ]),
                'best_1rm' => $line->sets->max(fn ($set): int|float => $set->weight * (1 + $set->reps / 30)),
            ]);
    }
}
