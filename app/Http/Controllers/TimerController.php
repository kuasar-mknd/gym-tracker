<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TimerPreset;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TimerController extends Controller
{
    use AuthorizesRequests;

    public function index(): \Inertia\Response
    {
        $presets = $this->user()->timerPresets()->orderBy('created_at', 'desc')->get();

        return Inertia::render('Tools/IntervalTimer', [
            'presets' => $presets,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'work_seconds' => 'required|integer|min:1',
            'rest_seconds' => 'required|integer|min:0',
            'rounds' => 'required|integer|min:1',
            'warmup_seconds' => 'nullable|integer|min:0',
            'cooldown_seconds' => 'nullable|integer|min:0',
        ]);

        $this->user()->timerPresets()->create([
            'name' => $validated['name'],
            'work_seconds' => $validated['work_seconds'],
            'rest_seconds' => $validated['rest_seconds'],
            'rounds' => $validated['rounds'],
            'warmup_seconds' => $validated['warmup_seconds'] ?? 0,
            'cooldown_seconds' => $validated['cooldown_seconds'] ?? 0,
        ]);

        return redirect()->back();
    }

    public function destroy(TimerPreset $timerPreset): \Illuminate\Http\RedirectResponse
    {
        if ($timerPreset->user_id !== $this->user()->id) {
            abort(403);
        }

        $timerPreset->delete();

        return redirect()->back();
    }
}
