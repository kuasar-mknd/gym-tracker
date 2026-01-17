<?php

namespace App\Http\Controllers;

use App\Models\BodyMeasurement;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class BodyMeasurementController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected \App\Services\StatsService $statsService) {}

    public function index()
    {
        $this->authorize('viewAny', BodyMeasurement::class);

        $measurements = Auth::user()->bodyMeasurements()
            ->orderBy('measured_at', 'asc')
            ->get();

        $weightHistory = $this->statsService->getWeightHistory(Auth::user(), 365);

        return Inertia::render('Measurements/Index', [
            'measurements' => $measurements,
            'weightHistory' => $weightHistory,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', BodyMeasurement::class);

        $validated = $request->validate([
            'weight' => ['required', 'numeric', 'min:1', 'max:500'],
            'body_fat' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'measured_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $request->user()->bodyMeasurements()->create($validated);

        $this->statsService->clearUserStatsCache($request->user());

        return redirect()->back();
    }

    public function destroy(BodyMeasurement $bodyMeasurement)
    {
        $this->authorize('delete', $bodyMeasurement);

        $user = Auth::user();
        $bodyMeasurement->delete();

        $this->statsService->clearUserStatsCache($user);

        return redirect()->back();
    }
}
