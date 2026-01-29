<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\HabitStoreRequest;
use App\Http\Requests\HabitUpdateRequest;
use App\Models\Habit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class HabitController extends Controller
{
    public function index(Request $request): \Inertia\Response
    {
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();

        $habits = $this->user()->habits()
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
            ->where('habits.user_id', $this->user()->id)
            ->where('habit_logs.date', '>=', $past30Days)
            ->groupBy('habit_logs.date')
            ->selectRaw('DATE(habit_logs.date) as date, COUNT(*) as count')
            ->pluck('count', 'date');

        $consistencyData = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $consistencyData[] = [
                'date' => $date,
                'count' => $consistencyStats[$date] ?? 0,
            ];
        }

        return Inertia::render('Habits/Index', [
            'habits' => $habits,
            'weekDates' => $this->getWeekDates(),
            'consistencyData' => $consistencyData,
        ]);
    }

    public function store(HabitStoreRequest $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validated();
        if (! ($data['color'] ?? null)) {
            $data['color'] = 'bg-slate-500';
        }
        if (! ($data['icon'] ?? null)) {
            $data['icon'] = 'check_circle';
        }

        $this->user()->habits()->create($data);

        return redirect()->back()->with('success', 'Habitude créée.');
    }

    public function update(HabitUpdateRequest $request, Habit $habit): \Illuminate\Http\RedirectResponse
    {
        // Authorization is handled by HabitUpdateRequest

        $habit->update($request->validated());

        return redirect()->back()->with('success', 'Habitude mise à jour.');
    }

    public function destroy(Habit $habit): \Illuminate\Http\RedirectResponse
    {
        if ($habit->user_id !== $this->user()->id) {
            abort(403);
        }

        $habit->delete();

        return redirect()->back()->with('success', 'Habitude supprimée.');
    }

    public function toggle(Request $request, Habit $habit): \Illuminate\Http\RedirectResponse
    {
        if ($habit->user_id !== $this->user()->id) {
            abort(403);
        }

        $request->validate([
            'date' => 'required|date',
        ]);

        $date = $request->date;

        $dateInput = $request->date;
        if (! is_string($dateInput)) {
            throw new \UnexpectedValueException('Date must be a string');
        }

        /** @var \App\Models\HabitLog|null $log */
        $log = $habit->logs()->whereDate('date', $dateInput)->first();

        if ($log) {
            $log->delete();
        } else {
            $habit->logs()->create(['date' => $date]);
        }

        return redirect()->back();
    }

    /**
     * @return array<int, array{date: string, day: string, day_name: string, day_short: string, day_num: int, is_today: bool}>
     */
    private function getWeekDates(): array
    {
        $start = Carbon::now()->startOfWeek();
        $dates = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $start->copy()->addDays($i);

            if (! $date instanceof \Illuminate\Support\Carbon) {
                continue;
            }

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
