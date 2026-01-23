<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BodyMeasurementStoreRequest;
use App\Models\BodyMeasurement;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;

class BodyMeasurementController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected \App\Services\StatsService $statsService) {}

    public function index(): \Inertia\Response
    {
        $this->authorize('viewAny', BodyMeasurement::class);

        $measurements = $this->user()->bodyMeasurements()
            ->orderBy('measured_at', 'asc')
            ->get();

        $weightHistory = $this->statsService->getWeightHistory($this->user(), 365);

        return Inertia::render('Measurements/Index', [
            'measurements' => $measurements,
            'weightHistory' => $weightHistory,
        ]);
    }

    public function store(BodyMeasurementStoreRequest $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', BodyMeasurement::class);

        $this->user()->bodyMeasurements()->create($request->validated());

        $this->statsService->clearBodyMeasurementStats($this->user());

        return redirect()->back();
    }

    public function destroy(BodyMeasurement $bodyMeasurement): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $bodyMeasurement);

        $user = $this->user();
        $bodyMeasurement->delete();

        $this->statsService->clearBodyMeasurementStats($user);

        return redirect()->back();
    }
}
