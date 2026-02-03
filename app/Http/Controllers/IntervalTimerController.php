<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreIntervalTimerRequest;
use App\Http\Requests\UpdateIntervalTimerRequest;
use App\Models\IntervalTimer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntervalTimerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Tools/IntervalTimer', [
            'timers' => $this->user()->intervalTimers()->latest()->get(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreIntervalTimerRequest $request): RedirectResponse
    {
        $this->user()->intervalTimers()->create($request->validated());

        return redirect()->route('tools.interval-timer.index')
            ->with('success', 'Timer created successfully.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateIntervalTimerRequest $request, IntervalTimer $intervalTimer): RedirectResponse
    {
        $intervalTimer->update($request->validated());

        return redirect()->route('tools.interval-timer.index')
            ->with('success', 'Timer updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, IntervalTimer $intervalTimer): RedirectResponse
    {
        if ($intervalTimer->user_id !== $this->user()->id) {
            abort(403);
        }

        $intervalTimer->delete();

        return redirect()->route('tools.interval-timer.index')
            ->with('success', 'Timer deleted successfully.');
    }
}
