<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\DTOs\Stats\DurationHistoryPoint;
use App\Models\User;
use App\Models\Workout;
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
             * Le début et la fin viennent avec leur décalage (#2159) : sans lui,
             * une séance commencée dans la première occurrence de l'heure que
             * le passage à l'heure d'hiver répète perdait une heure.
             *
             * @return array<int, DurationHistoryPoint>
             */
            fn (): array => $user->seancesFaites()
                ->select(['name', 'started_at', 'started_at_offset', 'ended_at', 'ended_at_offset'])
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
}
