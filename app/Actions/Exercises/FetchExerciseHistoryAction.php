<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use App\Traits\CalculatesOneRepMax;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FetchExerciseHistoryAction
{
    use CalculatesOneRepMax;

    /**
     * @return \Illuminate\Support\Collection<int, array{
     *     id: int,
     *     workout_id: int,
     *     workout_name: string,
     *     formatted_date: string,
     *     best_1rm: float,
     *     sets: array<int, array<string, mixed>>
     * }>
     */
    public function execute(User $user, Exercise $exercise): Collection
    {
        $version = Cache::get("stats.1rm_version.{$user->id}", '1');
        $version = is_scalar($version) ? (string) $version : '1';

        // ⚡ Bolt: Cache the exercise history results using the user-specific 1RM version.
        // This avoids expensive JOINs and PHP-side mapping on every page load.
        return Cache::remember(
            "stats.exercise_history.{$user->id}.{$exercise->id}.v{$version}",
            now()->addMinutes(30),
            fn (): Collection => $this->fetchFromDatabase($user, $exercise)
        );
    }

    /**
     * Fetch exercise history directly from the database using toBase() to avoid Eloquent overhead.
     */
    private function fetchFromDatabase(User $user, Exercise $exercise): Collection
    {
        // ⚡ Bolt: Use toBase() and joins to avoid Eloquent model hydration and N+1 queries.
        // We fetch everything in a single query and group by workout_line_id in PHP.
        // Use leftJoin for sets to include workout lines that might not have sets yet.
        $results = DB::table('workout_lines')
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->leftJoin('sets', 'sets.workout_line_id', '=', 'workout_lines.id')
            ->where('workout_lines.exercise_id', $exercise->id)
            ->where('workouts.user_id', $user->id)
            ->whereNotNull('workouts.started_at')
            ->select([
                'workout_lines.id as line_id',
                'workouts.id as workout_id',
                'workouts.name as workout_name',
                'workouts.started_at',
                'sets.weight',
                'sets.reps',
            ])
            ->get();

        return $results->groupBy('line_id')->map(function (Collection $group): array {
            $first = $group->first();
            $startedAt = Carbon::parse((string) $first->started_at);

            // Filter out empty sets from left join (where weight/reps are null)
            $sets = $group
                ->filter(fn ($set) => $set->weight !== null)
                ->map(fn ($set): array => [
                    'weight' => (float) $set->weight,
                    'reps' => (int) $set->reps,
                    'one_rep_max' => $this->calculate1RM((float) $set->weight, (int) $set->reps),
                ]);

            $best1rm = $sets->max('one_rep_max') ?? 0.0;

            return [
                'id' => (int) $first->line_id,
                'workout_id' => (int) $first->workout_id,
                'workout_name' => (string) $first->workout_name,
                'formatted_date' => $startedAt->locale('fr')->isoFormat('ddd D MMM'),
                'best_1rm' => (float) $best1rm,
                'sets' => $sets->values()->toArray(),
                'timestamp' => $startedAt->timestamp,
            ];
        })
            ->sortByDesc('timestamp')
            ->values()
            ->map(function (array $item): array {
                unset($item['timestamp']);

                return $item;
            });
    }
}
