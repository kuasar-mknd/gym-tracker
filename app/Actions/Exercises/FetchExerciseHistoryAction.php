<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FetchExerciseHistoryAction
{
    /**
     * @return array<int, array{date: string, weight: float, reps: int, one_rep_max: float, set_count: int}>
     */
    public function execute(User $user, Exercise $exercise): array
    {
        return DB::table('workouts')
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('workouts.user_id', $user->id)
            ->where('workout_lines.exercise_id', $exercise->id)
            ->whereNotNull('workouts.ended_at')
            ->select(
                'workouts.started_at',
                DB::raw('MAX(sets.weight) as max_weight'),
                DB::raw('MAX(sets.reps) as max_reps'),
                DB::raw('MAX(sets.weight * (1 + sets.reps / 30)) as one_rep_max'),
                DB::raw('COUNT(sets.id) as set_count')
            )
            ->groupBy('workouts.id', 'workouts.started_at')
            ->orderByDesc('workouts.started_at')
            ->get()
            ->map(fn (object $row): array => [
                'date' => \Carbon\Carbon::parse($row->started_at)->format('Y-m-d'),
                'weight' => (float) $row->max_weight,
                'reps' => (int) $row->max_reps,
                'one_rep_max' => (float) $row->one_rep_max,
                'set_count' => (int) $row->set_count,
            ])
            ->values()
            ->toArray();
    }
}
