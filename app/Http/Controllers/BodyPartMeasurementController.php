<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BodyMeasurements\FetchBodyPartMeasurementsAction;
use App\Http\Requests\BodyPartMeasurementStoreRequest;
use App\Models\BodyPartMeasurement;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class BodyPartMeasurementController extends Controller
{
    use AuthorizesRequests;

    public function index(FetchBodyPartMeasurementsAction $fetchBodyPartMeasurements): \Inertia\Response
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $latestMeasurements = $fetchBodyPartMeasurements->execute($user);

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
