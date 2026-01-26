<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFastingLogRequest;
use App\Http\Requests\UpdateFastingLogRequest;
use App\Models\FastingLog;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FastingController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        $activeFast = $this->user()->fastingLogs()
            ->where('status', 'active')
            ->latest()
            ->first();

        $history = $this->user()->fastingLogs()
            ->where('status', '!=', 'active')
            ->orderBy('start_time', 'desc')
            ->limit(20)
            ->get();

        return Inertia::render('Tools/FastingTracker', [
            'activeFast' => $activeFast,
            'history' => $history,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreFastingLogRequest $request): RedirectResponse
    {
        // Check if already fasting
        $existing = $this->user()->fastingLogs()
            ->where('status', 'active')
            ->exists();

        if ($existing) {
            return redirect()->back()->withErrors(['message' => 'You already have an active fast.']);
        }

        $validated = $request->validated();

        $this->user()->fastingLogs()->create(array_merge($validated, [
            'status' => 'active',
        ]));

        return redirect()->back();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateFastingLogRequest $request, FastingLog $fasting): RedirectResponse
    {
        // Ensure the user owns this log (Policy check handled in Request authorize, but double check usually handled by policy)
        if ($fasting->user_id !== $this->user()->id) {
            abort(403);
        }

        $validated = $request->validated();

        $fasting->update($validated);

        return redirect()->back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FastingLog $fasting): RedirectResponse
    {
         if ($fasting->user_id !== $this->user()->id) {
            abort(403);
        }

        $fasting->delete();

        return redirect()->back();
    }
}
