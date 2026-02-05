<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutLine;
use Illuminate\Support\Collection;

class FetchExerciseHistoryAction
{
    /**
     * @return Collection<int, array{
     *     id: int,
     *     workout_id: int,
     *     workout_name: string,
     *     formatted_date: string,
     *     best_1rm: float,
     *     sets: Collection<int, array{weight: float, reps: int, 1rm: float}>
     * }>
     */
    public function execute(User $user, Exercise $exercise): Collection
    {
        return WorkoutLine::query()
            ->where('exercise_id', $exercise->id)
            ->whereHas('workout', function ($query) use ($user): void {
                $query->where('user_id', $user->id)
                    ->whereNotNull('started_at');
            })
            ->with(['workout', 'sets'])
            ->get()
            ->map(function (WorkoutLine $line) {
                $workout = $line->workout;
                if (! $workout || ! $workout->started_at) {
                    return null;
                }

                $sets = $line->sets->map(fn ($set) => [
                    'weight' => (float) $set->weight,
                    'reps' => (int) $set->reps,
                    '1rm' => $this->calculate1RM((float) $set->weight, (int) $set->reps),
                ]);

                $best1rm = $sets->max('1rm') ?? 0.0;

                return [
                    'id' => $line->id,
                    'workout_id' => $workout->id,
                    'workout_name' => $workout->name,
                    // @phpstan-ignore-next-line
                    'formatted_date' => $workout->started_at->locale('fr')->isoFormat('ddd D MMM'),
                    'best_1rm' => $best1rm,
                    'sets' => $sets,
                    'started_at' => $workout->started_at, // For sorting
                ];
            })
            ->filter()
            ->sortByDesc('started_at')
            ->values()
            ->map(function ($item) {
                unset($item['started_at']);

                return $item;
            });
    }

    private function calculate1RM(float $weight, int $reps): float
    {
        if ($reps === 0) {
            return 0.0;
        }
        if ($reps === 1) {
            return $weight;
        }

        return $weight * (1 + ($reps / 30));
    }
}
