<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Fast;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class FastingController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $activeFast = $user->fasts()
            ->where('status', 'ACTIVE')
            ->latest('start_time')
            ->first();

        $history = $user->fasts()
            ->where('status', '!=', 'ACTIVE')
            ->latest('start_time')
            ->take(10)
            ->get()
            ->map(fn (Fast $fast) => [
                'id' => $fast->id,
                'start_time' => $fast->start_time,
                'end_time' => $fast->end_time,
                'duration_minutes' => $fast->duration_minutes,
                'target_duration_minutes' => $fast->target_duration_minutes,
                'type' => $fast->type,
                'status' => $fast->status,
                'progress_percent' => $fast->progress_percent,
            ]);

        return Inertia::render('Tools/FastingTracker', [
            'activeFast' => $activeFast ? [
                'id' => $activeFast->id,
                'start_time' => $activeFast->start_time,
                'target_duration_minutes' => $activeFast->target_duration_minutes,
                'type' => $activeFast->type,
                'elapsed_minutes' => $activeFast->duration_minutes,
                'progress_percent' => $activeFast->progress_percent,
            ] : null,
            'history' => $history,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'start_time' => ['required', 'date'],
            'target_duration_minutes' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'string'],
        ]);

        if ($request->user()->fasts()->where('status', 'ACTIVE')->exists()) {
            return back()->withErrors(['message' => 'You already have an active fast.']);
        }

        $request->user()->fasts()->create([
            'start_time' => $validated['start_time'],
            'target_duration_minutes' => $validated['target_duration_minutes'],
            'type' => $validated['type'],
            'status' => 'ACTIVE',
        ]);

        return back();
    }

    public function update(Request $request, Fast $fast): RedirectResponse
    {
        if ($fast->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($request->input('action') === 'end') {
            $fast->update([
                'end_time' => now(),
                'status' => 'COMPLETED',
            ]);
        } elseif ($request->has('start_time')) {
             $validated = $request->validate([
                'start_time' => ['required', 'date'],
             ]);
             $fast->update($validated);
        }

        return back();
    }

    public function destroy(Request $request, Fast $fast): RedirectResponse
    {
        if ($fast->user_id !== $request->user()->id) {
            abort(403);
        }

        $fast->delete();

        return back();
    }
}
