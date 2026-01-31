<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Fast;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FastingController extends Controller
{
    public function index(): Response
    {
        $user = $this->user();

        $activeFast = $user->fasts()
            ->where('status', 'active')
            ->latest()
            ->first();

        $history = $user->fasts()
            ->where('status', '!=', 'active')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Tools/Fasting/Index', [
            'activeFast' => $activeFast,
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

        $user = $this->user();

        // Check if there is already an active fast
        if ($user->fasts()->where('status', 'active')->exists()) {
             return back()->withErrors(['message' => 'You already have an active fast.']);
        }

        $user->fasts()->create([
            'start_time' => $validated['start_time'],
            'target_duration_minutes' => $validated['target_duration_minutes'],
            'type' => $validated['type'],
            'status' => 'active',
        ]);

        return back()->with('success', 'Fast started successfully.');
    }

    public function update(Request $request, Fast $fast): RedirectResponse
    {
        if ($fast->user_id !== $this->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'end_time' => ['nullable', 'date'],
            'status' => ['required', 'string', 'in:active,completed,broken'],
        ]);

        $fast->update($validated);

        return back()->with('success', 'Fast updated successfully.');
    }

    public function destroy(Fast $fast): RedirectResponse
    {
        if ($fast->user_id !== $this->user()->id) {
            abort(403);
        }

        $fast->delete();

        return back()->with('success', 'Fast deleted successfully.');
    }
}
