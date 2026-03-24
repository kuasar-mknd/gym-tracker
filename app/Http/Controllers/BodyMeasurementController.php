<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BodyMeasurementStoreRequest;
use App\Models\BodyMeasurement;
use Inertia\Inertia;

/**
 * Controller for managing user body measurements.
 *
 * This controller handles the CRUD operations for overall body measurements
 * like weight and body fat percentage. It integrates with Inertia.js for
 * frontend rendering and manages the clearing of related statistics caches
 * upon updates or deletions.
 */
class BodyMeasurementController extends Controller
{
    /**
     * Create a new BodyMeasurementController instance.
     *
     * @param  \App\Services\StatsService  $statsService  Service for fetching and clearing user measurement statistics.
     */
    public function __construct(protected \App\Services\StatsService $statsService)
    {
    }

    /**
     * Display a listing of the user's body measurements.
     *
     * Retrieves the most recent measurements for the authenticated user
     * and fetches their historical weight data spanning the past year to
     * populate the frontend graphs and list view.
     *
     * @return \Inertia\Response The Inertia response rendering the 'Measurements/Index' page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to view measurements.
     */
    public function index(): \Inertia\Response
    {
        $this->authorize('viewAny', BodyMeasurement::class);

        $measurements = $this->user()->bodyMeasurements()
            ->orderBy('measured_at', 'desc')
            ->limit(100)
            ->get();

        $weightHistory = $this->statsService->getWeightHistory($this->user(), 365);
        $bodyFatHistory = $this->statsService->getBodyFatHistory($this->user(), 365);

        return Inertia::render('Measurements/Index', [
            'measurements' => $measurements,
            'weightHistory' => $weightHistory,
            'bodyFatHistory' => $bodyFatHistory,
        ]);
    }

    /**
     * Store a newly created body measurement in storage.
     *
     * Creates a new body measurement record for the authenticated user based
     * on the validated incoming request. After creation, clears the
     * related body measurement stats cache to ensure fresh data.
     *
     * @param  \App\Http\Requests\BodyMeasurementStoreRequest  $request  The validated request containing measurement data.
     * @return \Illuminate\Http\RedirectResponse Redirects back to the previous page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to create a measurement.
     */
    public function store(BodyMeasurementStoreRequest $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', BodyMeasurement::class);

        $this->user()->bodyMeasurements()->create($request->validated());

        $this->statsService->clearBodyMeasurementStats($this->user());

        return redirect()->back();
    }

    /**
     * Remove the specified body measurement from storage.
     *
     * Deletes the given body measurement record, provided the authenticated
     * user is authorized to do so (i.e., they own the measurement).
     * Afterward, clears the relevant body measurement stats cache.
     *
     * @param  \App\Models\BodyMeasurement  $bodyMeasurement  The body measurement model instance to delete.
     * @return \Illuminate\Http\RedirectResponse Redirects back to the previous page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete the measurement.
     */
    public function destroy(BodyMeasurement $bodyMeasurement): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $bodyMeasurement);

        $user = $this->user();
        $bodyMeasurement->delete();

        $this->statsService->clearBodyMeasurementStats($user);

        return redirect()->back();
    }
}
