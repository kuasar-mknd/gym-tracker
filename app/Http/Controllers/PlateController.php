<?php

namespace App\Http\Controllers;

use App\Models\Plate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;

class PlateController extends Controller
{
    use AuthorizesRequests;

    public function index(): \Inertia\Response
    {
        $this->authorize('viewAny', Plate::class);

        $plates = $this->user()->plates()
            ->orderBy('weight', 'desc')
            ->get();

        return Inertia::render('Tools/PlateCalculator', [
            'plates' => $plates,
        ]);
    }

    public function store(\App\Http\Requests\StorePlateRequest $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validated();

        $plate = new Plate($validated);
        $plate->user_id = $this->user()->id;
        $plate->save();

        return redirect()->back();
    }

    public function update(\App\Http\Requests\UpdatePlateRequest $request, Plate $plate): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $plate);

        $plate->update($request->validated());

        return redirect()->back();
    }

    public function destroy(Plate $plate): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $plate);

        $plate->delete();

        return redirect()->back();
    }
}
