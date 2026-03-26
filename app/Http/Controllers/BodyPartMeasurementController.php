<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Measurements\FetchBodyPartMeasurementShowAction;
use App\Actions\Measurements\FetchBodyPartMeasurementsIndexAction;
use App\Http\Requests\BodyPartMeasurementStoreRequest;
use App\Models\BodyPartMeasurement;
use Inertia\Inertia;

class BodyPartMeasurementController extends Controller
{
    public function index(FetchBodyPartMeasurementsIndexAction $action): \Inertia\Response
    {
        $this->authorize('viewAny', BodyPartMeasurement::class);

        return Inertia::render('Measurements/Parts/Index', $action->execute($this->user()));
    }

    public function show(string $part, FetchBodyPartMeasurementShowAction $action): \Illuminate\Http\RedirectResponse|\Inertia\Response
    {
        $this->authorize('viewAny', BodyPartMeasurement::class);

        /** @var \App\Models\User $user */
        $user = $this->user();

        $data = $action->execute($user, $part);

        if ($data === null) {
            return redirect()->route('body-parts.index');
        }

        return Inertia::render('Measurements/Parts/Show', $data);
    }

    public function store(BodyPartMeasurementStoreRequest $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', BodyPartMeasurement::class);

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
}
