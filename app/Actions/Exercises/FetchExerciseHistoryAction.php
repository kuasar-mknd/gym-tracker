<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FetchExerciseHistoryAction
{
    /**
     * @return array<int, array{id: int, workout_id: int, workout_name: string, formatted_date: string, best_1rm: float, sets: array<int, array{weight: float, reps: int, 1rm: float}>}>
     */
    public function execute(User $user, Exercise $exercise): array
    {
        $rows = DB::table('workouts')
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('workouts.user_id', $user->id)
            ->where('workout_lines.exercise_id', $exercise->id)
            ->whereNotNull('workouts.ended_at')
            ->select([
                'workouts.id as workout_id',
                'workouts.name as workout_name',
                'workouts.started_at',
                'sets.weight',
                'sets.reps',
            ])
            ->orderByDesc('workouts.started_at')
            ->get();

        /** @var Collection<int, \stdClass> $rows */
        $grouped = $rows->groupBy('workout_id');

        /** @var Collection<int, Collection<int, \stdClass>> $grouped */
        return $grouped->map(function (Collection $sessionRows) {
            /** @var \stdClass|null $first */
            $first = $sessionRows->first();

            if (! $first) {
                return [];
            }

            /** @var array<int, array{weight: float, reps: int, 1rm: float}> $sets */
            $sets = $sessionRows->map(function (\stdClass $row): array {
                /** @var float|int $weight */
                $weight = $row->weight;
                /** @var int $reps */
                $reps = $row->reps;

                $oneRm = $weight * (1 + ($reps / 30));

                return [
                    'weight' => (float) $weight,
                    'reps' => (int) $reps,
                    '1rm' => (float) $oneRm,
                ];
            })->values()->all();

            $best1rm = collect($sets)->max('1rm');

            return [
                'id' => (int) $first->workout_id,
                'workout_id' => (int) $first->workout_id,
                'workout_name' => (string) $first->workout_name,
                'formatted_date' => Carbon::parse($first->started_at)->translatedFormat('D d M'),
                'best_1rm' => is_numeric($best1rm) ? (float) $best1rm : 0.0,
                'sets' => $sets,
            ];
        })->filter()->values()->all();
    }
}
