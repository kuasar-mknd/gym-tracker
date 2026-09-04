<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\DTOs\Stats\BodyFatHistoryPoint;
use App\DTOs\Stats\LatestBodyMetrics;
use App\DTOs\Stats\WeightHistoryPoint;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class BodyStatsService
{
    public function getLatestBodyMetrics(User $user): LatestBodyMetrics
    {
        return Cache::remember(
            ClesDeStats::mesures($user, 'latest_metrics'),
            now()->addMinutes(30),
            function () use ($user): LatestBodyMetrics {
                $measurements = $user->bodyMeasurements()
                    // `id` et `user_id` etaient selectionnes sans jamais etre
                    // lus : la closure ne rend que le poids et la masse grasse.
                    // `measured_at` reste, il sert de cle de tri juste dessous.
                    ->select(['weight', 'body_fat', 'measured_at'])
                    ->latest('measured_at')
                    ->take(2)
                    ->get();

                $latest = $measurements->first();
                $previous = $measurements->skip(1)->first();

                $weightChange = $latest !== null && $previous !== null
                    ? round((float) $latest->weight - (float) $previous->weight, 1)
                    : 0.0;

                return new LatestBodyMetrics(
                    $latest?->weight,
                    $weightChange,
                    $latest?->body_fat,
                );
            }
        );
    }

    /**
     * Get consolidated body progress data (weight and body fat history).
     * ⚡ Bolt: Reduces 2 database queries to 1 and uses a single cache key.
     *
     * @param  User  $user  The user to fetch stats for.
     * @param  int  $days  The number of days to look back.
     * @return array{weightHistory: array<int, WeightHistoryPoint>, bodyFatHistory: array<int, BodyFatHistoryPoint>}
     */
    public function getBodyProgressOverview(User $user, int $days = 90): array
    {
        return Cache::remember(
            ClesDeStats::mesures($user, "body_progress.{$days}"),
            now()->addMinutes(30),
            function () use ($user, $days): array {
                /*
                 * Le `toBase()` qui etait ici se reclamait d'une economie de
                 * memoire « pour de gros volumes » — sans `select()`, donc en
                 * ramenant toutes les colonnes, sur une requete deja bornee a
                 * `$days` jours. Il ne faisait economiser que l'hydratation, et
                 * il coutait trois entrees de baseline PHPStan : les valeurs
                 * revenaient non typees, d'ou deux casts vers `float` et un vers
                 * `string` pour reparser une date que le modele caste deja.
                 */
                $measurements = $user->bodyMeasurements()
                    ->select(['weight', 'body_fat', 'measured_at'])
                    ->where('measured_at', '>=', now()->subDays($days))
                    ->orderBy('measured_at', 'asc')
                    ->get();

                $weightHistory = [];
                $bodyFatHistory = [];

                foreach ($measurements as $m) {
                    /*
                     * `body_measurements.measured_at` est NOT NULL : `strtotime()`
                     * ne pouvait pas rendre false et le `continue` n'etait jamais
                     * emprunte. Cinquieme fois que ce motif est retire (#1459,
                     * #1474, #1493, #1494).
                     */
                    $date = $m->measured_at->format('d/m');
                    $fullDate = $m->measured_at->format('Y-m-d');

                    /*
                     * `weight` et `body_fat` sont castes `decimal:2`, ce qui rend
                     * une chaine et non un flottant — le cast reste donc requis,
                     * mais il porte desormais sur un `numeric-string` declare et
                     * non sur du `mixed`.
                     */
                    $weightHistory[] = new WeightHistoryPoint(
                        $date,
                        $fullDate,
                        (float) $m->weight,
                    );

                    if ($m->body_fat !== null) {
                        $bodyFatHistory[] = new BodyFatHistoryPoint(
                            $date,
                            $fullDate,
                            (float) $m->body_fat,
                        );
                    }
                }

                return [
                    'weightHistory' => $weightHistory,
                    'bodyFatHistory' => $bodyFatHistory,
                ];
            }
        );
    }
}
