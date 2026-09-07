<?php

declare(strict_types=1);

namespace App\Actions\Supplements;

use App\Http\Resources\SupplementResource;
use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Support\Collection;

final class FetchSupplementsIndexAction
{
    /**
     * @return array{
     *     supplements: Collection<int, array<string, mixed>>,
     *     usageHistory: array<int, array{date: string, count: float}>
     * }
     */
    public function execute(User $user): array
    {
        return [
            'supplements' => $this->getSupplementsWithLatestLog($user),
            'usageHistory' => $this->getUsageHistory($user),
        ];
    }

    /**
     * Les compléments, avec l'état de leur dernière prise.
     *
     * Mis en forme par `SupplementResource`, qui décrit déjà exactement ce que
     * la page lit. Le tableau écrit à la main qu'il remplace envoyait id, name,
     * icon, current_log, unit et daily_goal — les quatre derniers lus par rien,
     * nulle part — tout en omettant brand, dosage, servings_remaining,
     * low_stock_threshold et last_taken_at, que la carte affiche tous. Le stock
     * restant et la couleur de rupture sortaient donc en `undefined`, et le
     * formulaire d'édition se pré-remplissait avec rien, ce que la requête de
     * mise à jour rejetait ensuite.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function getSupplementsWithLatestLog(User $user): Collection
    {
        // `toArray()` plutôt que `resolve()` : `resolve()` est déclaré `array`
        // tout court, donc au niveau 9 ses valeurs sont des `mixed` implicites,
        // qui ne satisfont pas le `mixed` explicite ci-dessus. La ressource,
        // elle, déclare `array<string, mixed>`.
        return Supplement::forUser($user->id)
            ->with(['latestLog'])
            ->get()
            ->map(fn (Supplement $supplement): array => new SupplementResource($supplement)->toArray(request()))
            ->values();
    }

    /**
     * L'historique de prise sur les trente derniers jours.
     *
     * @return array<int, array{date: string, count: float}>
     */
    private function getUsageHistory(User $user): array
    {
        $days = 30;
        $usageHistoryRaw = SupplementLog::where('user_id', $user->id)
            ->where('consumed_at', '>=', now()->subDays($days)->startOfDay())
            ->selectRaw('DATE(consumed_at) as date, SUM(quantity) as count')
            ->groupBy('date')
            ->get()
            ->pluck('count', 'date');

        /** @var Collection<string, float> $results */
        $results = $usageHistoryRaw;

        return $this->fillUsageHistory($results, $days);
    }

    /**
     * Un jour sans prise vaut zéro plutôt que d'être absent : le graphique
     * attend une série continue.
     *
     * @param  Collection<string, float>  $usageHistoryRaw
     * @return array<int, array{date: string, count: float}>
     */
    private function fillUsageHistory(Collection $usageHistoryRaw, int $days): array
    {
        $history = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $carbonDate = now()->subDays($i);
            $dateKey = $carbonDate->format('Y-m-d');
            $dateString = $carbonDate->format('d/m');

            $rawTotal = $usageHistoryRaw[$dateKey] ?? 0.0;

            $history[] = [
                'date' => $dateString,
                'count' => (float) $rawTotal,
            ];
        }

        return $history;
    }
}
