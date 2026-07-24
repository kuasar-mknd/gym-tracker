<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Measurements\FetchBodyPartMeasurementShowAction;
use App\Actions\Measurements\FetchBodyPartMeasurementsIndexAction;
use App\Http\Requests\BodyPartMeasurementStoreRequest;
use App\Models\BodyPartMeasurement;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

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
     * @param  FetchBodyPartMeasurementsIndexAction  $action  The action to fetch the measurements data.
     * @return Response The Inertia response rendering the index view.
     */
    public function index(FetchBodyPartMeasurementsIndexAction $action): Response
    {
        $this->authorize('viewAny', BodyPartMeasurement::class);

        return Inertia::render('Measurements/Parts/Index', $action->execute($this->user()));
    }

    /**
     * Display the measurement history for a specific body part.
     *
     * @param  string  $part  The name or identifier of the body part.
     * @param  FetchBodyPartMeasurementShowAction  $action  The action to fetch specific body part data.
     * @return RedirectResponse|Response The Inertia response rendering the show view, or a redirect if no history exists.
     */
    public function show(string $part, FetchBodyPartMeasurementShowAction $action): RedirectResponse|Response
    {
        $this->authorize('viewAny', BodyPartMeasurement::class);

        /** @var User $user */
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
     * @param  BodyPartMeasurementStoreRequest  $request  The validated request containing measurement data.
     * @return RedirectResponse A redirect back with a success message.
     */
    public function store(BodyPartMeasurementStoreRequest $request): RedirectResponse
    {
        $this->authorize('create', BodyPartMeasurement::class);

        /** @var User $user */
        $user = $request->user();
        $user->bodyPartMeasurements()->create($request->validated());

        return redirect()->back()->with('success', 'Measurement added.');
    }

    /**
     * Remove the specified body part measurement from storage.
     *
     * @param  BodyPartMeasurement  $bodyPartMeasurement  The body part measurement to delete.
     * @return RedirectResponse A redirect back with a success message.
     */
    public function destroy(BodyPartMeasurement $bodyPartMeasurement): RedirectResponse
    {
        $this->authorize('delete', $bodyPartMeasurement);

        $bodyPartMeasurement->delete();

        return redirect()->back()->with('success', 'Measurement deleted.');
    }
}
