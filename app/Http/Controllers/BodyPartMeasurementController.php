<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BodyPartMeasurementStoreRequest;
use App\Models\BodyPartMeasurement;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class BodyPartMeasurementController extends Controller
{
    use AuthorizesRequests;

    public function index(): \Inertia\Response
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        return Inertia::render('Measurements/Parts/Index', [
            'latestMeasurements' => $this->getLatestMeasurements($user),
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
     * Get the latest measurements for all body parts.
     *
     * @return \Illuminate\Support\Collection<int, array{part: string, current: float, unit: string, date: string, diff: float}>
     */
    private function getLatestMeasurements(\App\Models\User $user): \Illuminate\Support\Collection
    {
        $table = (new BodyPartMeasurement())->getTable();
        $measurements = BodyPartMeasurement::fromQuery("
            SELECT * FROM (
                SELECT *, ROW_NUMBER() OVER (PARTITION BY part ORDER BY measured_at DESC) as rn
                FROM {$table}
                WHERE user_id = ?
            ) as ranked
            WHERE rn <= 2
        ", [$user->id]);

        return $measurements
            ->groupBy('part')
            ->map(fn ($group): array => $this->formatLatestMeasurement($group))
            ->values();
    }

    /**
     * Format a group of measurements for display.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\BodyPartMeasurement>  $group
     * @return array{part: string, current: float|int, unit: string|null, date: string, diff: float|int}
     */
    private function formatLatestMeasurement(\Illuminate\Support\Collection $group): array
    {
        /** @var \App\Models\BodyPartMeasurement $latest */
        $latest = $group->first();
        /** @var \App\Models\BodyPartMeasurement|null $previous */
        $previous = $group->skip(1)->first();

        return [
            'part' => $latest->part,
            'current' => $latest->value,
            'unit' => $latest->unit,
            'date' => \Illuminate\Support\Carbon::parse($latest->measured_at)->format('Y-m-d'),
            'diff' => $previous ? round($latest->value - $previous->value, 2) : 0,
        ];
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
