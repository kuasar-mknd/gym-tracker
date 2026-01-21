<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBodyPartMeasurementRequest;
use App\Models\BodyPartMeasurement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BodyPartMeasurementController extends Controller
{
    public function store(StoreBodyPartMeasurementRequest $request): RedirectResponse
    {
        $this->user()->bodyPartMeasurements()->create($request->validated());

        return redirect()->back();
    }

    // Optional: Index method for API use or future UI
    public function index(Request $request)
    {
        $query = $this->user()->bodyPartMeasurements();

        if ($request->has('part')) {
            $query->where('part', $request->input('part'));
        }

        return $query->orderBy('measured_at', 'desc')->paginate(20);
    }
}
