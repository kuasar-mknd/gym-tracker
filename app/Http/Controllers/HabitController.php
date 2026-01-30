<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\HabitStoreRequest;
use App\Http\Requests\HabitUpdateRequest;
use App\Models\Habit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Controller for managing User Habits.
 *
 * This controller handles the CRUD operations for habits and tracks their daily completion.
 * It manages the habits list, creation, updates, deletion, and the daily toggle logic.
 */
class HabitController extends Controller
{
    /**
     * Display a listing of the user's habits.
     *
     * Retrieves all active (non-archived) habits for the authenticated user,
     * including their completion logs for the current week.
     * Also generates the dates for the current week to be displayed in the calendar view.
     *
     * @param  \Illuminate\Http\Request  $request  The HTTP request.
     * @return \Inertia\Response The Inertia response rendering the Habits/Index page.
     */
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

        return Inertia::render('Habits/Index', [
            'habits' => $habits,
            'weekDates' => $this->getWeekDates(),
        ]);
    }

    /**
     * Store a newly created habit in storage.
     *
     * Validates the input and creates a new habit for the authenticated user.
     * Sets default values for color and icon if they are not provided.
     *
     * @param  \App\Http\Requests\HabitStoreRequest  $request  The validated request containing habit details.
     * @return \Illuminate\Http\RedirectResponse Redirects back with a success message.
     */
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

    /**
     * Update the specified habit in storage.
     *
     * Updates the details of an existing habit.
     * Authorization is ensured by the HabitUpdateRequest.
     *
     * @param  \App\Http\Requests\HabitUpdateRequest  $request  The validated request with updated details.
     * @param  \App\Models\Habit  $habit  The habit to update.
     * @return \Illuminate\Http\RedirectResponse Redirects back with a success message.
     */
    public function update(HabitUpdateRequest $request, Habit $habit): \Illuminate\Http\RedirectResponse
    {
        // Authorization is handled by HabitUpdateRequest
        $habit->update($request->validated());

        return redirect()->back()->with('success', 'Habitude mise à jour.');
    }

    /**
     * Remove the specified habit from storage.
     *
     * Permanently deletes a habit. Ensures the user owns the habit.
     *
     * @param  \App\Models\Habit  $habit  The habit to delete.
     * @return \Illuminate\Http\RedirectResponse Redirects back with a success message.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException If the user is not authorized (403).
     */
    public function destroy(Habit $habit): \Illuminate\Http\RedirectResponse
    {
        if ($habit->user_id !== $this->user()->id) {
            abort(403);
        }

        $habit->delete();

        return redirect()->back()->with('success', 'Habitude supprimée.');
    }

    /**
     * Toggle the completion status of a habit for a specific date.
     *
     * If a log exists for the given date, it is deleted (uncheck).
     * If no log exists, one is created (check).
     * Ensures the user owns the habit.
     *
     * @param  \Illuminate\Http\Request  $request  The HTTP request containing the date.
     * @param  \App\Models\Habit  $habit  The habit to toggle.
     * @return \Illuminate\Http\RedirectResponse Redirects back.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException If the user is not authorized (403).
     */
    public function toggle(Request $request, Habit $habit): \Illuminate\Http\RedirectResponse
    {
        if ($habit->user_id !== $this->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'date' => 'required|date',
        ]);

        /** @var string $date */
        $date = $validated['date'];

        /** @var \App\Models\HabitLog|null $log */
        $log = $habit->logs()->whereDate('date', $date)->first();

        if ($log) {
            $log->delete();
        } else {
            $habit->logs()->create(['date' => $date]);
        }

        return redirect()->back();
    }

    /**
     * Get the dates for the current week.
     *
     * Generates an array of objects representing each day of the current week (Mon-Sun),
     * including localized day names and a flag for the current day.
     *
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
