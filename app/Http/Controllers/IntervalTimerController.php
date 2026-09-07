<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreIntervalTimerRequest;
use App\Http\Requests\UpdateIntervalTimerRequest;
use App\Models\IntervalTimer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class IntervalTimerController extends Controller
{
    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'a pas le droit de consulter ses minuteurs.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', IntervalTimer::class);

        return Inertia::render('Tools/IntervalTimer', [
            'timers' => $this->user()->intervalTimers()->latest()->limit(50)->get(),
        ]);
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'a pas le droit de créer un minuteur.
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
     * @throws \Illuminate\Auth\Access\AuthorizationException Si le minuteur n'est pas celui de l'utilisateur.
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
     * @throws \Illuminate\Auth\Access\AuthorizationException Si le minuteur n'est pas celui de l'utilisateur.
     */
    public function destroy(IntervalTimer $intervalTimer): RedirectResponse
    {
        $this->authorize('delete', $intervalTimer);

        $intervalTimer->delete();

        return redirect()->route('tools.interval-timer.index')
            ->with('success', 'Timer deleted successfully.');
    }
}
