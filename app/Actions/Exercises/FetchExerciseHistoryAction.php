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

        $grouped = $rows->groupBy('workout_id');

        /** @var Collection<int, \stdClass> $sessionRows */
        return $grouped->map(function (Collection $sessionRows) {
            $first = $sessionRows->first();

            /** @var \stdClass $row */
            $sets = $sessionRows->map(function ($row) {
                $oneRm = $row->weight * (1 + ($row->reps / 30));
                return [
                    'weight' => (float) $row->weight,
                    'reps' => (int) $row->reps,
                    '1rm' => (float) $oneRm,
                ];
            })->values()->all();

            $best1rm = collect($sets)->max('1rm');

            return [
                'id' => $first->workout_id,
                'workout_id' => $first->workout_id,
                'workout_name' => $first->workout_name,
                'formatted_date' => Carbon::parse($first->started_at)->translatedFormat('D d M'),
                'best_1rm' => (float) $best1rm,
                'sets' => $sets,
            ];
        })->values()->all();
    }
}
