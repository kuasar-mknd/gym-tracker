<?php

declare(strict_types=1);

namespace App\Actions\Stats;

use App\Models\User;
use App\Services\Stats\ClesDeStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class FetchRegulariteAction
{
    /**
     * Les deux cartes de régularité de Progrès : les séances par mois et par
     * jour de la semaine, sur les six derniers mois calendaires.
     *
     * Elles reçoivent le même instant, lu une fois : quand le mois changeait
     * entre deux lectures, deux cartes voisines comptaient deux fenêtres
     * différentes, ou l'entrée rangée sous le mois qui s'achevait gardait la
     * fenêtre du mois suivant (#2033).
     *
     * @return array{
     *     monthly_frequency: Collection<int, array{month: string, count: int}>,
     *     day_of_week_frequency: Collection<int, array{day: string, count: int}>
     * }
     */
    public function execute(User $user, ?Carbon $maintenant = null): array
    {
        $maintenant ??= now();

        return [
            'monthly_frequency' => $this->getMonthlyFrequency($user, $maintenant),
            'day_of_week_frequency' => $this->getDayOfWeekFrequency($user, $maintenant),
        ];
    }

    /**
     * @param  Carbon  $maintenant  L'instant de l'opération, dont viennent la clef et la fenêtre.
     * @return Collection<int, array{day: string, count: int}>
     */
    private function getDayOfWeekFrequency(User $user, Carbon $maintenant): Collection
    {
        return Cache::remember(
            ClesDeStats::seances($user, 'day_of_week_frequency.'.self::moisCourant($maintenant)),
            $maintenant->copy()->addHour(),
            fn (): Collection => $this->calculateDayOfWeekFrequency($user, self::debutDeLaFenetre($maintenant))
        );
    }

    /**
     * @param  Carbon  $maintenant  L'instant de l'opération, dont viennent la clef et la fenêtre.
     * @return Collection<int, array{month: string, count: int}>
     */
    private function getMonthlyFrequency(User $user, Carbon $maintenant): Collection
    {
        return Cache::remember(
            ClesDeStats::seances($user, 'monthly_frequency.'.self::moisCourant($maintenant)),
            $maintenant->copy()->addHour(),
            fn (): Collection => $this->calculateMonthlyFrequency($user, self::debutDeLaFenetre($maintenant))
        );
    }

    /**
     * Le debut des six mois glissants qu'affichent les cartes de frequence.
     *
     * Six MOIS CALENDAIRES et non cent quatre-vingts jours : la carte mensuelle
     * dessine six barres, dont celle du mois en cours.
     *
     * Le recul part du premier du mois, jamais d'aujourd'hui : Carbon deborde,
     * et le 31 juillet moins cinq mois donnait le « 31 fevrier », soit le
     * 3 mars — fevrier sortait de la fenetre (#1955).
     *
     * @param  Carbon  $maintenant  L'instant de l'opération, que la fenêtre ne modifie pas.
     */
    private static function debutDeLaFenetre(Carbon $maintenant): Carbon
    {
        return $maintenant->copy()->startOfMonth()->subMonths(5);
    }

    /**
     * Le mois courant, que portent les clefs des deux cartes de frequence.
     *
     * Leur fenetre suit le mois calendaire : sans lui dans la clef, le premier
     * du mois servait encore, jusqu'a l'expiration, les six mois de la veille.
     * Il vient du même instant que la fenêtre, pour que la clef dise le mois
     * de la fenêtre qu'elle garde (#2033).
     *
     * @param  Carbon  $maintenant  L'instant de l'opération.
     */
    private static function moisCourant(Carbon $maintenant): string
    {
        return $maintenant->format('Y-m');
    }

    /**
     * @param  Carbon  $startDate  Le premier jour de la fenêtre.
     * @return Collection<int, array{month: string, count: int}>
     */
    private function calculateMonthlyFrequency(User $user, Carbon $startDate): Collection
    {
        $results = $user->seancesFaites()
            ->toBase()
            ->where('workouts.started_at', '>=', $startDate)
            ->selectRaw("DATE_FORMAT(workouts.started_at, '%Y-%m') as month, COUNT(*) as count")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        /*
         * Chaque barre avance d'un mois depuis le premier jour de la fenetre :
         * reculer depuis aujourd'hui sautait et doublait des mois les 29, 30
         * et 31 (#1955).
         */
        return collect(range(0, 5))->map(function (int $i) use ($results, $startDate): array {
            $date = $startDate->copy()->addMonths($i);
            $monthKey = $date->format('Y-m');
            $data = $results->get($monthKey);

            return [
                'month' => $date->translatedFormat('M'),
                'count' => $data !== null && is_numeric($data->count) ? (int) $data->count : 0,
            ];
        });
    }

    /**
     * @param  Carbon  $debut  Le premier jour de la fenêtre, celui de la fréquence mensuelle.
     * @return Collection<int, array{day: string, count: int}>
     */
    private function calculateDayOfWeekFrequency(User $user, Carbon $debut): Collection
    {
        /*
         * La MEME fenetre que la frequence mensuelle, sa voisine immediate :
         * sans borne, le cout suivait l'anciennete du compte (109 lectures
         * d'index a 30 seances anciennes, 1 249 a 600).
         */
        $results = $user->seancesFaites()
            ->toBase()
            ->where('workouts.started_at', '>=', $debut)
            ->selectRaw('DAYOFWEEK(workouts.started_at) as day_of_week, COUNT(*) as count')
            ->groupBy('day_of_week')
            ->get()
            ->keyBy('day_of_week');

        $jours = [
            2 => 'Lun',
            3 => 'Mar',
            4 => 'Mer',
            5 => 'Jeu',
            6 => 'Ven',
            7 => 'Sam',
            1 => 'Dim',
        ];

        return collect($jours)->map(function (string $dayName, int $dayIndex) use ($results): array {
            $data = $results->get($dayIndex);

            return [
                'day' => $dayName,
                'count' => $data !== null && is_numeric($data->count) ? (int) $data->count : 0,
            ];
        })->values();
    }
}
