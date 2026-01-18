<?php

namespace App\Http\Controllers;

use App\Models\WarmupPreference;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WarmupController extends Controller
{
    public function index()
    {
        $preferences = auth()->user()->warmupPreferences()
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Tools/WarmupCalculator', [
            'preferences' => $preferences,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sets_config' => 'required|array|min:1',
            'sets_config.*.reps' => 'required|integer|min:1',
            'sets_config.*.type' => 'required|in:bar,percentage,weight',
            'sets_config.*.value' => 'nullable|numeric',
        ]);

        $request->user()->warmupPreferences()->create($validated);

        return redirect()->back();
    }

    public function update(Request $request, WarmupPreference $warmup)
    {
        if ($warmup->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sets_config' => 'required|array|min:1',
            'sets_config.*.reps' => 'required|integer|min:1',
            'sets_config.*.type' => 'required|in:bar,percentage,weight',
            'sets_config.*.value' => 'nullable|numeric',
        ]);

        $warmup->update($validated);

        return redirect()->back();
    }

    public function destroy(Request $request, WarmupPreference $warmup)
    {
        if ($warmup->user_id !== $request->user()->id) {
            abort(403);
        }

        $warmup->delete();

        return redirect()->back();
    }
}
