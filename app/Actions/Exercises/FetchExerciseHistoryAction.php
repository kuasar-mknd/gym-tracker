<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class FetchExerciseHistoryAction
{
    /**
     * @return array<int, array{date: string, weight: float, reps: int, one_rep_max: float}>
     */
    public function execute(User $user, Exercise $exercise): array
    {
        /** @var \Illuminate\Support\Collection<int, object{started_at: string, weight: float, reps: int}> $results */
        $results = DB::table('sets')
            ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->where('workouts.user_id', $user->id)
            ->where('workout_lines.exercise_id', $exercise->id)
            ->whereNotNull('workouts.started_at')
            ->orderByDesc('workouts.started_at')
            ->select([
                'workouts.started_at',
                'sets.weight',
                'sets.reps',
            ])
            ->get();

        return $results->map(fn (object $row): array => [
            'date' => $row->started_at,
            'weight' => (float) $row->weight,
            'reps' => (int) $row->reps,
            'one_rep_max' => $this->calculateOneRepMax((float) $row->weight, (int) $row->reps),
        ])->values()->toArray();
    }

    private function calculateOneRepMax(float $weight, int $reps): float
    {
        if ($reps === 0) {
            return 0.0;
        }
        return $weight * (1 + $reps / 30.0);
    }
}
