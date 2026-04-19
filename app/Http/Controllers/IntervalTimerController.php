<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreIntervalTimerRequest;
use App\Http\Requests\UpdateIntervalTimerRequest;
use App\Models\IntervalTimer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing interval timers.
 *
 * This controller allows users to create, update, delete, and view their interval timers
 * for use during workouts or other activities.
 */
class IntervalTimerController extends Controller
{
    /**
     * Display a listing of the user's interval timers.
     *
     * @return \Inertia\Response The Inertia response rendering the 'Tools/IntervalTimer' page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', IntervalTimer::class);

        return Inertia::render('Tools/IntervalTimer', [
            'timers' => $this->user()->intervalTimers()->latest()->limit(50)->get(),
        ]);
    }

    /**
     * Store a newly created interval timer in storage.
     *
     * @param  \App\Http\Requests\StoreIntervalTimerRequest  $request  The validated HTTP request containing the timer details.
     * @return \Illuminate\Http\RedirectResponse A redirect response to the timer index page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to create a timer.
     */
    public function store(StoreIntervalTimerRequest $request): RedirectResponse
    {
        $this->authorize('create', IntervalTimer::class);

        $validated = $request->validated();

        $this->user()->intervalTimers()->create($validated);

        return redirect()->route('tools.interval-timer.index')
            ->with('success', 'Timer created successfully.');
    }

    /**
     * Update the specified interval timer in storage.
     *
     * @param  \App\Http\Requests\UpdateIntervalTimerRequest  $request  The validated HTTP request with updated timer data.
     * @param  \App\Models\IntervalTimer  $intervalTimer  The timer instance to update.
     * @return \Illuminate\Http\RedirectResponse A redirect response to the timer index page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update this timer.
     */
    public function update(UpdateIntervalTimerRequest $request, IntervalTimer $intervalTimer): RedirectResponse
    {
        $this->authorize('update', $intervalTimer);

        $validated = $request->validated();

        $intervalTimer->update($validated);

        return redirect()->route('tools.interval-timer.index')
            ->with('success', 'Timer updated successfully.');
    }

    /**
     * Remove the specified interval timer from storage.
     *
     * @param  \App\Models\IntervalTimer  $intervalTimer  The timer instance to delete.
     * @return \Illuminate\Http\RedirectResponse A redirect response to the timer index page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete this timer.
     */
    public function destroy(IntervalTimer $intervalTimer): RedirectResponse
    {
        $this->authorize('delete', $intervalTimer);

        $intervalTimer->delete();

        return redirect()->route('tools.interval-timer.index')
            ->with('success', 'Timer deleted successfully.');
    }
}
