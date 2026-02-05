<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Habits\FetchHabitsIndexAction;
use App\Http\Requests\HabitStoreRequest;
use App\Http\Requests\HabitUpdateRequest;
use App\Http\Requests\ToggleHabitRequest;
use App\Models\Habit;
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
    public function index(Request $request, FetchHabitsIndexAction $fetchHabits): \Inertia\Response
    {
        return Inertia::render('Habits/Index', $fetchHabits->execute($this->user()));
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
     * @param  \App\Http\Requests\ToggleHabitRequest  $request  The HTTP request containing the date.
     * @param  \App\Models\Habit  $habit  The habit to toggle.
     * @return \Illuminate\Http\RedirectResponse Redirects back.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException If the user is not authorized (403).
     */
    public function toggle(ToggleHabitRequest $request, Habit $habit): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validated();

        /** @var string $date */
        $date = $validated['date'];

        /** @var \App\Models\HabitLog|null $log */
        $log = $habit->logs()->whereDate('date', $date)->first();

        if ($log) {
            $log->delete();
        } else {
            $habit->logs()->create(['date' => $date]);
        }

        return redirect()->route('habits.index');
    }
}
