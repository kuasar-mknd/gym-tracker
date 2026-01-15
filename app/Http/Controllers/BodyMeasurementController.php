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

    public function index()
    {
        $this->authorize('viewAny', BodyMeasurement::class);

        $measurements = Auth::user()->bodyMeasurements()
            ->orderBy('measured_at', 'asc')
            ->get();

        return Inertia::render('Measurements/Index', [
            'measurements' => $measurements,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', BodyMeasurement::class);

        $validated = $request->validate([
            'weight' => ['required', 'numeric', 'min:1', 'max:500'],
            'measured_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $request->user()->bodyMeasurements()->create($validated);

        return redirect()->back();
    }

    public function destroy(BodyMeasurement $bodyMeasurement)
    {
        $this->authorize('delete', $bodyMeasurement);

        $bodyMeasurement->delete();

        return redirect()->back();
    }
}
