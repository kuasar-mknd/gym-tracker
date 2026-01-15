<?php

namespace App\Http\Controllers;

use App\Models\Plate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'weight' => ['required', 'numeric', 'min:0.1', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $plate = new Plate($validated);
        $plate->user_id = $request->user()->id;
        $plate->save();

        return redirect()->back();
    }

    public function update(Request $request, Plate $plate)
    {
        $this->authorize('update', $plate);

        $validated = $request->validate([
            'weight' => ['required', 'numeric', 'min:0.1', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $plate->update($validated);

        return redirect()->back();
    }

    public function destroy(Plate $plate)
    {
        $this->authorize('delete', $plate);

        $plate->delete();

        return redirect()->back();
    }
}
