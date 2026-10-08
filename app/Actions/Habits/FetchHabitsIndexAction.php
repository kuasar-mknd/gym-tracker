<?php

declare(strict_types=1);

namespace App\Actions\Habits;

use App\Models\User;
use Carbon\Carbon;

final class FetchHabitsIndexAction
{
    /**
     * De quoi rendre la page des habitudes tout de suite : des requêtes rapides,
     * rien d'autre.
     *
     * @return array{
     *     habits: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Habit>,
     *     weekDates: array<int, array{date: string, day: string, day_name: string, day_short: string, day_num: int, is_today: bool}>
     * }
     */
    public function getImmediateData(User $user): array
    {
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();

        $habits = $user->habits()
            ->where('archived', false)
            ->with([
                /*
                 * `$query` type, sinon il est `mixed` et l'appel qui suit ne
                 * verifie plus rien : c'est un `Relation`, pas un `Builder`,
                 * parce qu'Eloquent passe la relation elle-meme aux fermetures
                 * de chargement anticipe.
                 */
                'logs' => function (\Illuminate\Database\Eloquent\Relations\Relation $query) use ($startOfWeek, $endOfWeek): void {
                    $query->whereBetween('date', [$startOfWeek->format('Y-m-d'), $endOfWeek->format('Y-m-d')]);
                },
            ])
            ->get();

        return [
            'habits' => $habits,
            'weekDates' => $this->getWeekDates($startOfWeek, $endOfWeek),
        ];
    }

    /**
     * Les statistiques lourdes des habitudes, réunies en une seule prop
     * différée : séparées, elles faisaient chacune sa requête XHR.
     *
     * @return array{
     *     consistencyData: array<int, array{date: string, count: int}>,
     *     history: array<int, array{date: string, full_date: string, count: int}>
     * }
     */
    public function getStatsData(User $user): array
    {
        $now = Carbon::now();
        $past30Days = $now->copy()->subDays(29)->startOfDay();

        /*
         * `DATE()` sur une colonne deja de type `date` etait sans effet sur la
         * valeur et fatal a l'index. Sans la jointure ni la fonction,
         * `(user_id, date)` borne la lecture aux trente jours demandes.
         */
        $consistencyStats = \App\Models\HabitLog::query()
            ->where('user_id', $user->id)
            ->where('date', '>=', $past30Days->toDateString())
            ->groupBy('date')
            ->selectRaw('date, count(*) as count')
            ->pluck('count', 'date');

        $consistencyData = []; // Pour la courbe.
        $historique = []; // Pour l'histogramme.

        /*
         * Les trente jours de la période, du premier à aujourd'hui, et non un
         * compteur : `$i++` à la place de `$i--` ne finissait jamais, et ce
         * mutant tenait un processus de la passe nocturne jusqu'au délai que
         * Pest accorde à chaque mutant (#2017).
         */
        foreach ($past30Days->daysUntil($now)->toArray() as $dateObj) {
            $dateStr = $dateObj->format('Y-m-d');
            // @phpstan-ignore-next-line
            $count = (int) ($consistencyStats[$dateStr] ?? 0);

            $consistencyData[] = [
                'date' => $dateStr,
                'count' => $count,
            ];

            $historique[] = [
                'date' => $dateObj->format('d/m'),
                'full_date' => $dateStr,
                'count' => $count,
            ];
        }

        return [
            'consistencyData' => $consistencyData,
            'history' => $historique,
        ];
    }

    /**
     * Les jours de la semaine dont les suivis sont chargés, du lundi au
     * dimanche : la grille et le chargement anticipé lisent la même semaine.
     * Un parcours de période et non un compteur, dont le mutant `$i--` ne
     * finissait jamais (#2017).
     *
     * @return array<int, array{date: string, day: string, day_name: string, day_short: string, day_num: int, is_today: bool}>
     */
    private function getWeekDates(Carbon $debut, Carbon $fin): array
    {
        $dates = [];
        foreach ($debut->daysUntil($fin)->toArray() as $date) {
            $dates[] = [
                'date' => $date->format('Y-m-d'),
                'day' => $date->format('D'),
                'day_name' => $date->locale('fr')->dayName,
                'day_short' => $date->locale('fr')->shortDayName,
                'day_num' => $date->day,
                'is_today' => $date->isToday(),
            ];
        }

        return $dates;
    }
}
