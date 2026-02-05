<?php

declare(strict_types=1);

namespace App\Actions\Habits;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class FetchHabitsIndexAction
{
    /**
     * @return array{
     *     habits: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Habit>,
     *     weekDates: array<int, array{date: string, day: string, day_name: string, day_short: string, day_num: int, is_today: bool}>,
     *     consistencyData: array<int, array{date: string, count: int}>,
     *     history: array<int, array{date: string, full_date: string, count: int}>
     * }
     */
    public function execute(User $user): array
    {
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();

        $habits = $user->habits()
            ->where('archived', false)
            ->with([
                'logs' => function ($query) use ($startOfWeek, $endOfWeek): void {
                    $query->whereBetween('date', [$startOfWeek->format('Y-m-d'), $endOfWeek->format('Y-m-d')]);
                },
            ])
            ->get();

        // Calculate consistency for the last 30 days
        $past30Days = Carbon::now()->subDays(29)->startOfDay();
        $consistencyStats = DB::table('habit_logs')
            ->join('habits', 'habit_logs.habit_id', '=', 'habits.id')
            ->where('habits.user_id', $user->id)
            ->where('habit_logs.date', '>=', $past30Days)
            ->groupBy('habit_logs.date')
            ->selectRaw('DATE(habit_logs.date) as date, COUNT(*) as count')
            ->pluck('count', 'date');

        $consistencyData = [];
        $history = []; // For Bar Chart

        for ($i = 29; $i >= 0; $i--) {
            $dateObj = Carbon::now()->subDays($i);
            $dateStr = $dateObj->format('Y-m-d');
            $count = $consistencyStats[$dateStr] ?? 0;

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
            'habits' => $habits,
            'weekDates' => $this->getWeekDates(),
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
