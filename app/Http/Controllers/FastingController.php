<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Fast;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class FastingController extends Controller
{
    public function index(): Response
    {
        $user = Auth::user();

        $activeFast = $user->fasts()
            ->whereNull('end_time')
            ->first();

        $history = $user->fasts()
            ->whereNotNull('end_time')
            ->latest('end_time')
            ->take(10)
            ->get();

        return Inertia::render('Tools/FastingTracker', [
            'activeFast' => $activeFast,
            'history' => $history,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();

        // Check if there's already an active fast
        if ($user->fasts()->whereNull('end_time')->exists()) {
            return redirect()->back()->withErrors(['message' => 'You already have an active fast.']);
        }

        $validated = $request->validate([
            'start_time' => 'required|date',
            'target_duration_minutes' => 'required|integer|min:1',
            'type' => 'required|string',
        ]);

        $user->fasts()->create($validated);

        return redirect()->back();
    }

    public function update(Request $request, Fast $fast): \Illuminate\Http\RedirectResponse
    {
        if ($fast->user_id !== Auth::id()) {
            abort(403);
        }

        $validated = $request->validate([
            'end_time' => 'nullable|date',
            'target_duration_minutes' => 'nullable|integer|min:1',
        ]);

        $fast->update($validated);

        return redirect()->back();
    }

    public function destroy(Fast $fast): \Illuminate\Http\RedirectResponse
    {
        if ($fast->user_id !== Auth::id()) {
            abort(403);
        }

        $fast->delete();

        return redirect()->back();
    }
}
