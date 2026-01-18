<?php

namespace App\Http\Controllers;

use App\Models\Plate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class PlateController extends Controller
{
    use AuthorizesRequests;

    public function index()
    {
        $this->authorize('viewAny', Plate::class);

        $plates = Auth::user()->plates()
            ->orderBy('weight', 'desc')
            ->get();

        return Inertia::render('Tools/PlateCalculator', [
            'plates' => $plates,
        ]);
    }

    public function store(\App\Http\Requests\StorePlateRequest $request)
    {
        $validated = $request->validated();

        $plate = new Plate($validated);
        $plate->user_id = $request->user()->id;
        $plate->save();

        return redirect()->back();
    }

    public function update(\App\Http\Requests\UpdatePlateRequest $request, Plate $plate)
    {
        $this->authorize('update', $plate);

        $plate->update($request->validated());

        return redirect()->back();
    }

    public function destroy(Plate $plate)
    {
        $this->authorize('delete', $plate);

        $plate->delete();

        return redirect()->back();
    }
}
