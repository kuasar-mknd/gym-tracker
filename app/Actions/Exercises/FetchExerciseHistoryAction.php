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
     * @return \Illuminate\Support\Collection<int, array{
     *     id: int,
     *     workout_id: int,
     *     workout_name: string,
     *     formatted_date: string,
     *     best_1rm: float,
     *     sets: \Illuminate\Support\Collection<int, array<string, mixed>>
     * }>
     */
    public function execute(User $user, Exercise $exercise): Collection
    {
        // Optimization: Use JOIN instead of whereHas + PHP sorting
        // @phpstan-ignore-next-line
        return WorkoutLine::query()
            ->select('workout_lines.*')
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->where('workout_lines.exercise_id', $exercise->id)
            ->where('workouts.user_id', $user->id)
            ->whereNotNull('workouts.started_at')
            ->with(['workout', 'sets'])
            ->orderByDesc('workouts.started_at')
            ->get()
            ->map(function (WorkoutLine $line): ?array {
                $workout = $line->workout;
                /** @phpstan-ignore-next-line */
                if (! $workout || ! $workout->started_at) {
                    return null;
                }

                $sets = $line->sets->map(fn ($set): array => [
                    'weight' => (float) $set->weight,
                    'reps' => (int) $set->reps,
                    'one_rep_max' => $this->calculate1RM((float) $set->weight, (int) $set->reps),
                ]);

                $best1rm = $sets->max('one_rep_max') ?? 0.0;

                return [
                    'id' => $line->id,
                    'workout_id' => $workout->id,
                    'workout_name' => $workout->name,
                    // @phpstan-ignore-next-line
                    'formatted_date' => $workout->started_at->locale('fr')->isoFormat('ddd D MMM'),
                    'best_1rm' => $best1rm,
                    'sets' => $sets,
                ];
            })
            ->filter()
            ->values();
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
