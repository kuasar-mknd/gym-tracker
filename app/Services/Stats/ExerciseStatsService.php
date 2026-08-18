<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\DTOs\Stats\Exercise1RMProgressPoint;
use App\DTOs\Stats\MuscleDistributionStat;
use App\Models\Set;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class ExerciseStatsService
{
    /**
     * @return array<int, MuscleDistributionStat>
     */
    public function getMuscleDistribution(User $user, int $days = 30): array
    {
        return Cache::remember(
            "stats.muscle_dist.{$user->id}.{$days}",
            now()->addMinutes(30),
            fn (): array => Set::query()
                ->toBase()
                ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
                ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
                ->join('exercises', 'workout_lines.exercise_id', '=', 'exercises.id')
                ->where('workouts.user_id', $user->id)
                ->where('workouts.started_at', '>=', now()->subDays($days))
                ->selectRaw('exercises.category, SUM(sets.weight * sets.reps) as volume')
                ->groupBy('exercises.category')
                ->get()
                ->map(fn (\stdClass $row): MuscleDistributionStat => new MuscleDistributionStat(
                    (string) ($row->category ?? 'Unknown'),
                    (float) ($row->volume ?? 0.0),
                ))
                ->toArray()
        );
    }

    /**
     * @return array<int, Exercise1RMProgressPoint>
     */
    public function getExercise1RMProgress(User $user, int $exerciseId, int $days = 90): array
    {
        /*
         * La version n'est ecrite qu'a un seul endroit, `StatsCacheManager:53`,
         * sous la forme `(string) time()`. Le repli `is_scalar()` qui etait ici
         * ne pouvait donc pas se declencher — deux mutants y survivaient.
         *
         * Ce qui EST atteignable, c'est l'absence de cle : un compte dont les
         * statistiques n'ont jamais ete invalidees n'a pas de version, et le
         * repli textuel sert alors vraiment. Ecrit ainsi, la branche est vivante
         * et un test peut la tenir.
         */
        $version = Cache::get("stats.1rm_version.{$user->id}");
        $version = is_string($version) ? $version : '1';

        return Cache::remember(
            "stats.1rm.{$user->id}.{$exerciseId}.{$days}.v{$version}",
            now()->addMinutes(30),
            fn (): array => Set::query()
                ->toBase()
                ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
                ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
                ->where('workouts.user_id', $user->id)
                ->where('workout_lines.exercise_id', $exerciseId)
                ->where('workouts.started_at', '>=', now()->subDays($days))
                ->selectRaw('workouts.started_at, MAX(sets.weight * (1 + sets.reps / 30.0)) as epley_1rm')
                ->groupBy('workouts.started_at')
                ->orderBy('workouts.started_at')
                ->get()
                ->map(function (\stdClass $set): Exercise1RMProgressPoint {
                    /*
                     * `workouts.started_at` est NOT NULL : `strtotime()` ne
                     * pouvait pas rendre false, et les deux replis sur « ?? »
                     * n'etaient jamais atteints — six mutants y survivaient.
                     *
                     * Ils meritaient d'autant moins de rester qu'ils produisaient
                     * une etiquette « ?? » sur la courbe plutot que d'echouer :
                     * un point de graphique nomme « ?? » n'aide personne, et
                     * n'arrivait de toute facon jamais.
                     *
                     * Quatrieme fois que ce motif est retire (#1459, #1474, #1493).
                     */
                    $jour = CarbonImmutable::parse((string) $set->started_at);

                    return new Exercise1RMProgressPoint(
                        $jour->format('d/m'),
                        $jour->format('Y-m-d'),
                        (float) $set->epley_1rm,
                    );
                })
                ->toArray()
        );
    }
}
