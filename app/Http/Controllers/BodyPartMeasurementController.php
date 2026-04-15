<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Measurements\FetchBodyPartMeasurementShowAction;
use App\Actions\Measurements\FetchBodyPartMeasurementsIndexAction;
use App\Http\Requests\BodyPartMeasurementStoreRequest;
use App\Models\BodyPartMeasurement;
use Inertia\Inertia;

/**
 * Controller for managing user body part measurements.
 *
 * Handles the CRUD operations for tracking specific body part circumferences
 * or measurements over time.
 */
class BodyPartMeasurementController extends Controller
{
    /**
     * Display a listing of body part measurements.
     *
     * @param  \App\Actions\Measurements\FetchBodyPartMeasurementsIndexAction  $action  The action to fetch the measurements data.
     * @return \Inertia\Response The Inertia response rendering the index view.
     */
    public function index(FetchBodyPartMeasurementsIndexAction $action): \Inertia\Response
    {
        $this->authorize('viewAny', BodyPartMeasurement::class);

        return Inertia::render('Measurements/Parts/Index', $action->execute($this->user()));
    }

    /**
     * Display the measurement history for a specific body part.
     *
     * @param  string  $part  The name or identifier of the body part.
     * @param  \App\Actions\Measurements\FetchBodyPartMeasurementShowAction  $action  The action to fetch specific body part data.
     * @return \Illuminate\Http\RedirectResponse|\Inertia\Response The Inertia response rendering the show view, or a redirect if no history exists.
     */
    public function show(string $part, FetchBodyPartMeasurementShowAction $action): \Illuminate\Http\RedirectResponse|\Inertia\Response
    {
        $this->authorize('viewAny', BodyPartMeasurement::class);

        /** @var \App\Models\User $user */
        $user = $this->user();

        $history = $action->execute($user, $part);

        if ($history->isEmpty()) {
            return redirect()->route('body-parts.index');
        }

        return Inertia::render('Measurements/Parts/Show', [
            'part' => $part,
            'history' => $history,
        ]);
    }

    /**
     * Store a newly created body part measurement in storage.
     *
     * @param  \App\Http\Requests\BodyPartMeasurementStoreRequest  $request  The validated request containing measurement data.
     * @return \Illuminate\Http\RedirectResponse A redirect back with a success message.
     */
    public function store(BodyPartMeasurementStoreRequest $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', BodyPartMeasurement::class);

        /** @var \App\Models\User $user */
        $user = $request->user();
        $user->bodyPartMeasurements()->create($request->validated());

        return redirect()->back()->with('success', 'Measurement added.');
    }

    /**
     * Remove the specified body part measurement from storage.
     *
     * @param  \App\Models\BodyPartMeasurement  $bodyPartMeasurement  The body part measurement to delete.
     * @return \Illuminate\Http\RedirectResponse A redirect back with a success message.
     */
    public function destroy(BodyPartMeasurement $bodyPartMeasurement): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $bodyPartMeasurement);

        $bodyPartMeasurement->delete();

        return redirect()->back()->with('success', 'Measurement deleted.');
    }
}
