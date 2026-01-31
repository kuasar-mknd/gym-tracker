<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
            'timers' => $request->user()->intervalTimers()->latest()->get(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'work_seconds' => ['required', 'integer', 'min:1'],
            'rest_seconds' => ['required', 'integer', 'min:0'],
            'rounds' => ['required', 'integer', 'min:1'],
            'warmup_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $request->user()->intervalTimers()->create($validated);

        return redirect()->route('tools.interval-timer.index')
            ->with('success', 'Timer created successfully.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, IntervalTimer $intervalTimer): RedirectResponse
    {
        if ($intervalTimer->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'work_seconds' => ['required', 'integer', 'min:1'],
            'rest_seconds' => ['required', 'integer', 'min:0'],
            'rounds' => ['required', 'integer', 'min:1'],
            'warmup_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $intervalTimer->update($validated);

        return redirect()->route('tools.interval-timer.index')
            ->with('success', 'Timer updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, IntervalTimer $intervalTimer): RedirectResponse
    {
        if ($intervalTimer->user_id !== $request->user()->id) {
            abort(403);
        }

        $intervalTimer->delete();

        return redirect()->route('tools.interval-timer.index')
            ->with('success', 'Timer deleted successfully.');
    }
}
