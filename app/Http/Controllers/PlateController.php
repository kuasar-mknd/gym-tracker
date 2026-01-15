<?php

namespace App\Http\Controllers;

use App\Models\Plate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class PlateController extends Controller
{
    public function index()
    {
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

        $request->user()->plates()->create($validated);

        return redirect()->back();
    }

    public function update(Request $request, Plate $plate)
    {
        if ($plate->user_id !== Auth::id()) {
            abort(403);
        }

        $validated = $request->validate([
            'weight' => ['required', 'numeric', 'min:0.1', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $plate->update($validated);

        return redirect()->back();
    }

    public function destroy(Plate $plate)
    {
        if ($plate->user_id !== Auth::id()) {
            abort(403);
        }

        $plate->delete();

        return redirect()->back();
    }
}
