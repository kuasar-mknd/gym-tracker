<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\DTOs\Stats\DistributionStat;
use App\DTOs\Stats\DurationHistoryPoint;
use App\Models\User;
use App\Models\Workout;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class WorkoutStatsService
{
    /**
     * @return array<int, DurationHistoryPoint>
     */
    public function getDurationHistory(User $user, int $limit = 20): array
    {
        return Cache::remember(
            ClesDeStats::seances($user, "duration_history.{$limit}"),
            now()->addMinutes(30),
            /**
             * Sans `toBase()`, contrairement a la methode voisine.
             *
             * Le `toBase()` qui etait ici evitait d'hydrater des modeles « pour
             * de gros volumes » — sauf que la requete est bornee par `take(20)`.
             * Il ne faisait donc economiser l'hydratation d'au plus vingt lignes,
             * et il coutait cher : les valeurs revenaient non typees, ce qui
             * imposait trois casts vers `string` (trois entrees de baseline
             * PHPStan) et un decoupage de chaines a la main.
             *
             * Eloquent caste `started_at` et `ended_at` en Carbon, et `name` en
             * `?string`. Il ne reste ni cast, ni `strtotime()`, ni le garde
             * `=== false` qui l'accompagnait — lequel ne pouvait pas s'executer,
             * les deux colonnes etant garanties non nulles ici, et faisait donc
             * survivre trois mutants.
             *
             * `all()` plutot que `toArray()` a la fin : le second est declare
             * `array<TKey, mixed>` et perd le type des elements, ce qui faisait
             * que la methode ne tenait plus sa propre signature — une entree de
             * baseline PHPStan de plus. Le resultat est le meme, les DTO n'etant
             * pas `Arrayable`, mais celui-ci se laisse verifier.
             *
             * @return array<int, DurationHistoryPoint>
             */
            fn (): array => Workout::query()
                ->select(['name', 'started_at', 'ended_at'])
                ->where('user_id', $user->id)
                ->whereNotNull('ended_at')
                ->latest('started_at')
                ->take($limit)
                ->get()
                ->map(fn (Workout $workout): DurationHistoryPoint => new DurationHistoryPoint(
                    $workout->started_at->format('d/m'),
                    (int) $workout->started_at->diffInMinutes($workout->ended_at, true),
                    $workout->name ?? __('Workout'),
                ))
                ->reverse()->values()->all()
        );
    }

    /**
     * Get consolidated workout distributions (duration + time of day).
     * ⚡ Bolt: Reduces 2 database queries to 1 and uses a single cache key.
     *
     * @param  User  $user  The user to fetch stats for.
     * @param  int  $days  The number of days to look back.
     * @return array{duration: array<int, DistributionStat>, time_of_day: array<int, DistributionStat>}
     */
    public function getWorkoutDistributions(User $user, int $days = 90): array
    {
        return Cache::remember(
            ClesDeStats::seances($user, "workout_distributions.{$days}"),
            now()->addMinutes(30),
            function () use ($user, $days): array {
                /*
                 * `toBase()` se justifie ici, contrairement a la methode voisine :
                 * cette requete balaie 90 jours et n'est bornee par rien.
                 *
                 * `id` en revanche n'etait jamais lu dans la boucle. La colonne
                 * partait en base et revenait pour rien, et le mutant qui la
                 * retirait survivait parce qu'il n'y avait rien a voir.
                 */
                $workouts = $user->workouts()
                    ->toBase()
                    ->select(['started_at', 'ended_at'])
                    ->where('started_at', '>=', now()->subDays($days))
                    ->get();

                $durationBuckets = [
                    '< 30 min' => 0,
                    '30-60 min' => 0,
                    '60-90 min' => 0,
                    '90+ min' => 0,
                ];

                $timeBuckets = [
                    'Morning (06h-12h)' => 0,
                    'Afternoon (12h-17h)' => 0,
                    'Evening (17h-22h)' => 0,
                    'Night (22h-06h)' => 0,
                ];

                foreach ($workouts as $workout) {
                    /*
                     * Le `is_string` reste : `toBase()` rend des valeurs non
                     * typees, et `ended_at` est nullable tant que la seance n'est
                     * pas terminee. Les controles de longueur qui l'accompagnaient
                     * sont partis : MySQL rend un DATETIME sur exactement 19
                     * caracteres, donc `strlen(...) < 19` ne se declenchait
                     * jamais — d'ou deux mutants survivants, dont un qui abaissait
                     * le seuil a 18 sans que rien ne bouge.
                     */
                    if (! is_string($workout->started_at)) {
                        continue;
                    }

                    $timeLabel = $this->resolveTimeOfDayLabel($workout->started_at);
                    $timeBuckets[$timeLabel]++;

                    if (is_string($workout->ended_at)) {
                        $durationBuckets[$this->resolveDurationLabel($workout->started_at, $workout->ended_at)]++;
                    }
                }

                return [
                    'duration' => collect($durationBuckets)
                        ->map(fn (int $count, string $label): DistributionStat => new DistributionStat(__($label), $count))
                        ->values()
                        ->all(),
                    'time_of_day' => collect($timeBuckets)
                        ->map(fn (int $count, string $label): DistributionStat => new DistributionStat(__($label), $count))
                        ->values()
                        ->all(),
                ];
            }
        );
    }

    private function resolveTimeOfDayLabel(string $startedAt): string
    {
        $hour = (int) substr($startedAt, 11, 2);

        return match (true) {
            $hour >= 6 && $hour < 12 => 'Morning (06h-12h)',
            $hour >= 12 && $hour < 17 => 'Afternoon (12h-17h)',
            $hour >= 17 && $hour < 22 => 'Evening (17h-22h)',
            default => 'Night (22h-06h)',
        };
    }

    private function resolveDurationLabel(string $startedAt, string $endedAt): string
    {
        /*
         * `parse()` plutot que `strtotime()`, et donc pas de garde `=== false` :
         * les deux colonnes sont des DATETIME valides, le faux n'arrivait
         * jamais, et le `return null` qui l'accompagnait faisait disparaitre la
         * seance du graphique sans rien dire.
         *
         * Un « chemin rapide » se trouvait ici, qui lisait heures et minutes a
         * coups de `substr` quand la seance tenait dans une journee, pour
         * eviter le cout de l'analyse de date. Il sautait les SECONDES.
         *
         * Ce n'etait pas une approximation sans consequence : sa voisine
         * `getDurationHistory` tronque des minutes reelles, et les deux
         * graphiques du tableau de bord se retrouvaient a compter la meme
         * seance differemment. De 10:00:30 a 10:30:00, le chemin rapide voyait
         * trente minutes et la courbe vingt-neuf — trente etant exactement la
         * frontiere entre deux seaux, la seance changeait de tranche selon le
         * graphique qui la regardait. La production ecrit `now()`, secondes
         * comprises, donc le cas se produit une fois sur soixante.
         *
         * Rien ne le voyait : toutes les fixtures se posaient a la seconde 00,
         * ce qui laissait vingt-deux mutants survivre sur ces six lignes. Une
         * branche dont le seul comportement propre est de contredire sa voisine
         * sur les memes donnees se supprime — elle ne se teste pas.
         */
        $debut = CarbonImmutable::parse($startedAt);
        $minutes = (int) $debut->diffInMinutes(CarbonImmutable::parse($endedAt), true);

        return match (true) {
            $minutes < 30 => '< 30 min',
            $minutes < 60 => '30-60 min',
            $minutes < 90 => '60-90 min',
            default => '90+ min',
        };
    }
}
