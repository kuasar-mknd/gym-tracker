<?php

namespace App\Http\Controllers;

use App\Http\Requests\BodyMeasurementStoreRequest;
use App\Models\BodyMeasurement;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
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

        DB::transaction(function () use ($request) {
            $this->user()->bodyMeasurements()->create($request->only(['weight', 'body_fat', 'measured_at', 'notes']));

            if ($request->has('parts')) {
                foreach ($request->input('parts') as $part) {
                    $this->user()->bodyPartMeasurements()->create([
                        'part' => $part['part'],
                        'value' => $part['value'],
                        'measured_at' => $request->input('measured_at'),
                    ]);
                }
            }
        });

        $this->statsService->clearUserStatsCache($this->user());

        return redirect()->back();
    }

    public function destroy(BodyMeasurement $bodyMeasurement): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $bodyMeasurement);

        $user = $this->user();
        $bodyMeasurement->delete();

        $this->statsService->clearUserStatsCache($user);

        return redirect()->back();
    }
}
