<?php

namespace App\Http\Controllers;

use App\Http\Requests\BodyPartMeasurementStoreRequest;
use App\Models\BodyPartMeasurement;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class BodyPartMeasurementController extends Controller
{
    use AuthorizesRequests;

    public function index()
    {
        $user = Auth::user();

        // Group by part, get latest for card display
        $latestMeasurements = $user->bodyPartMeasurements()
            ->orderBy('measured_at', 'desc')
            ->get()
            ->groupBy('part')
            ->map(function ($group) {
                $latest = $group->first();
                $previous = $group->skip(1)->first();

                return [
                    'part' => $latest->part,
                    'current' => $latest->value,
                    'unit' => $latest->unit,
                    'date' => $latest->measured_at->format('Y-m-d'),
                    'diff' => $previous ? round($latest->value - $previous->value, 2) : 0,
                ];
            })->values();

        return Inertia::render('Measurements/Parts/Index', [
            'latestMeasurements' => $latestMeasurements,
            'commonParts' => $this->getCommonParts(),
        ]);
    }

    public function show(string $part)
    {
        $user = Auth::user();

        $history = $user->bodyPartMeasurements()
            ->where('part', $part)
            ->orderBy('measured_at', 'asc')
            ->get();

        if ($history->isEmpty()) {
            return redirect()->route('body-parts.index');
        }

        return Inertia::render('Measurements/Parts/Show', [
            'part' => $part,
            'history' => $history,
        ]);
    }

    public function store(BodyPartMeasurementStoreRequest $request)
    {
        $request->user()->bodyPartMeasurements()->create($request->validated());

        return redirect()->back()->with('success', 'Measurement added.');
    }

    public function destroy(BodyPartMeasurement $bodyPartMeasurement)
    {
        $this->authorize('delete', $bodyPartMeasurement);

        $bodyPartMeasurement->delete();

        return redirect()->back()->with('success', 'Measurement deleted.');
    }

    private function getCommonParts()
    {
        return [
            'Neck', 'Shoulders', 'Chest',
            'Biceps L', 'Biceps R',
            'Forearm L', 'Forearm R',
            'Waist', 'Hips',
            'Thigh L', 'Thigh R',
            'Calf L', 'Calf R',
        ];
    }
}
