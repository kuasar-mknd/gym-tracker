<?php

declare(strict_types=1);

namespace App\Actions\Habits;

use App\Models\User;
use Carbon\Carbon;

final class FetchHabitsIndexAction
{
    /**
     * Get immediate data for fast initial rendering of the habits page.
     * ⚡ Bolt: Fast queries only.
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
            'weekDates' => $this->getWeekDates(),
        ];
    }

    /**
     * Get heavy statistical data for the habits dashboard.
     * ⚡ Bolt: Consolidated into a single deferred prop to reduce XHR requests.
     *
     * @return array{
     *     consistencyData: array<int, array{date: string, count: int}>,
     *     history: array<int, array{date: string, full_date: string, count: int}>
     * }
     */
    public function getStatsData(User $user): array
    {
        // Calculate consistency for the last 30 days
        $now = Carbon::now();
        $past30Days = $now->copy()->subDays(29)->startOfDay();

        $consistencyStats = \App\Models\HabitLog::query()
            ->join('habits', 'habit_logs.habit_id', '=', 'habits.id')
            ->where('habits.user_id', $user->id)
            ->where('habit_logs.date', '>=', $past30Days)
            ->groupBy('habit_logs.date')
            ->selectRaw('DATE(habit_logs.date) as date, COUNT(*) as count')
            ->pluck('count', 'date');

        $consistencyData = [];
        $history = []; // For Bar Chart

        for ($i = 29; $i >= 0; $i--) {
            $dateObj = $now->copy()->subDays($i);
            $dateStr = $dateObj->format('Y-m-d');
            // @phpstan-ignore-next-line
            $count = (int) ($consistencyStats[$dateStr] ?? 0);

            // For Line Chart (consistencyData)
            $consistencyData[] = [
                'date' => $dateStr,
                'count' => $count,
            ];

            // For Bar Chart (history)
            $history[] = [
                'date' => $dateObj->format('d/m'),
                'full_date' => $dateStr,
                'count' => $count,
            ];
        }

        return [
            'consistencyData' => $consistencyData,
            'history' => $history,
        ];
    }

    /**
     * Get the dates for the current week.
     *
     * @return array<int, array{date: string, day: string, day_name: string, day_short: string, day_num: int, is_today: bool}>
     */
    private function getWeekDates(): array
    {
        $start = Carbon::now()->startOfWeek();
        $dates = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $start->copy()->addDays($i);

            $dates[] = [
                'date' => $date->format('Y-m-d'),
                'day' => $date->format('D'),
                // @phpstan-ignore-next-line
                'day_name' => $date->locale('fr')->dayName,
                // @phpstan-ignore-next-line
                'day_short' => $date->locale('fr')->shortDayName,
                'day_num' => $date->day,
                'is_today' => $date->isToday(),
            ];
        }

        return $dates;
    }
}
