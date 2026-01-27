<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BodyPartMeasurementStoreRequest;
use App\Models\BodyPartMeasurement;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class BodyPartMeasurementController extends Controller
{
    use AuthorizesRequests;

    public function index(): \Inertia\Response
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        // Optimized query: Get only the latest 2 measurements per part using window functions
        // This avoids fetching the entire history into memory
        $sub = BodyPartMeasurement::query()
            ->select('part', 'value', 'unit', 'measured_at')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY part ORDER BY measured_at DESC) as rn')
            ->where('user_id', $user->id);

        $measurements = DB::table(DB::raw("({$sub->toSql()}) as sub"))
            ->mergeBindings($sub->getQuery())
            ->where('rn', '<=', 2)
            ->get();

        $latestMeasurements = $measurements->groupBy('part')->map(function ($group) {
            $latest = $group->firstWhere('rn', 1);
            $previous = $group->firstWhere('rn', 2);

            return [
                'part' => $latest->part,
                'current' => $latest->value,
                'unit' => $latest->unit,
                'date' => \Illuminate\Support\Carbon::parse($latest->measured_at)->format('Y-m-d'),
                'diff' => $previous ? round($latest->value - $previous->value, 2) : 0,
            ];
        })->values();

        return Inertia::render('Measurements/Parts/Index', [
            'latestMeasurements' => $latestMeasurements,
            'commonParts' => $this->getCommonParts(),
        ]);
    }

    public function show(string $part): \Illuminate\Http\RedirectResponse|\Inertia\Response
    {
        /** @var \App\Models\User $user */
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

    public function store(BodyPartMeasurementStoreRequest $request): \Illuminate\Http\RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $user->bodyPartMeasurements()->create($request->validated());

        return redirect()->back()->with('success', 'Measurement added.');
    }

    public function destroy(BodyPartMeasurement $bodyPartMeasurement): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $bodyPartMeasurement);

        $bodyPartMeasurement->delete();

        return redirect()->back()->with('success', 'Measurement deleted.');
    }

    /**
     * @return array<int, string>
     */
    private function getCommonParts(): array
    {
        return [
            'Neck',
            'Shoulders',
            'Chest',
            'Biceps L',
            'Biceps R',
            'Forearm L',
            'Forearm R',
            'Waist',
            'Hips',
            'Thigh L',
            'Thigh R',
            'Calf L',
            'Calf R',
        ];
    }
}
