<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Fast;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FastingController extends Controller
{
    use AuthorizesRequests;

    public function index(): \Inertia\Response
    {
        $user = auth()->user();

        $activeFast = $user->fasts()->active()->first();

        $history = $user->fasts()
            ->whereNotNull('end_time')
            ->orderBy('start_time', 'desc')
            ->limit(20)
            ->get();

        return Inertia::render('Tools/Fasting/Index', [
            'activeFast' => $activeFast,
            'history' => $history,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'target_duration_minutes' => 'required|integer|min:1',
            'type' => 'required|string|max:255',
        ]);

        $user = auth()->user();

        if ($user->fasts()->active()->exists()) {
            return redirect()->back()->withErrors(['message' => 'You already have an active fast.']);
        }

        $user->fasts()->create([
            'start_time' => now(),
            'target_duration_minutes' => $request->target_duration_minutes,
            'type' => $request->type,
        ]);

        return redirect()->back();
    }

    public function update(Fast $fast): \Illuminate\Http\RedirectResponse
    {
        if ($fast->user_id !== auth()->id()) {
            abort(403);
        }

        $fast->update([
            'end_time' => now(),
        ]);

        return redirect()->back();
    }

    public function destroy(Fast $fast): \Illuminate\Http\RedirectResponse
    {
        if ($fast->user_id !== auth()->id()) {
            abort(403);
        }

        $fast->delete();

        return redirect()->back();
    }
}
