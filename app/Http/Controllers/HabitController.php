<?php

namespace App\Http\Controllers;

use App\Models\Habit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HabitController extends Controller
{
    public function index(Request $request)
    {
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();

        $habits = auth()->user()->habits()
            ->where('archived', false)
            ->with(['logs' => function ($query) use ($startOfWeek, $endOfWeek) {
                $query->whereBetween('date', [$startOfWeek->format('Y-m-d'), $endOfWeek->format('Y-m-d')]);
            }])
            ->get();

        return Inertia::render('Habits/Index', [
            'habits' => $habits,
            'weekDates' => $this->getWeekDates(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'color' => 'nullable|string',
            'icon' => 'nullable|string',
            'goal_times_per_week' => 'required|integer|min:1|max:7',
        ]);

        $data = $validated;
        if (empty($data['color'])) {
            $data['color'] = 'bg-slate-500';
        }
        if (empty($data['icon'])) {
            $data['icon'] = 'check_circle';
        }

        auth()->user()->habits()->create($data);

        return redirect()->back()->with('success', 'Habitude créée.');
    }

    public function update(Request $request, Habit $habit)
    {
        if ($habit->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'color' => 'nullable|string',
            'icon' => 'nullable|string',
            'goal_times_per_week' => 'required|integer|min:1|max:7',
            'archived' => 'boolean',
        ]);

        $habit->update($validated);

        return redirect()->back()->with('success', 'Habitude mise à jour.');
    }

    public function destroy(Habit $habit)
    {
        if ($habit->user_id !== auth()->id()) {
            abort(403);
        }

        $habit->delete();

        return redirect()->back()->with('success', 'Habitude supprimée.');
    }

    public function toggle(Request $request, Habit $habit)
    {
        if ($habit->user_id !== auth()->id()) {
            abort(403);
        }

        $request->validate([
            'date' => 'required|date',
        ]);

        $date = $request->date;

        $log = $habit->logs()->whereDate('date', $date)->first();

        if ($log) {
            $log->delete();
        } else {
            $habit->logs()->create(['date' => $date]);
        }

        return redirect()->back();
    }

    private function getWeekDates()
    {
        $start = Carbon::now()->startOfWeek();
        $dates = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $start->copy()->addDays($i);
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
