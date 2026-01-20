<?php

namespace App\Http\Controllers;

use App\Models\Supplement;
use App\Models\SupplementLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class SupplementController extends Controller
{
    public function index()
    {
        $supplements = Supplement::forUser(Auth::id())
            ->with('latestLog')
            ->get()
            ->map(function ($supplement) {
                return [
                    'id' => $supplement->id,
                    'name' => $supplement->name,
                    'brand' => $supplement->brand,
                    'dosage' => $supplement->dosage,
                    'servings_remaining' => $supplement->servings_remaining,
                    'low_stock_threshold' => $supplement->low_stock_threshold,
                    'last_taken_at' => $supplement->latestLog?->consumed_at,
                ];
            });

        return Inertia::render('Supplements/Index', [
            'supplements' => $supplements,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'servings_remaining' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['required', 'integer', 'min:0'],
        ]);

        Supplement::create(array_merge($validated, ['user_id' => Auth::id()]));

        return redirect()->back()->with('success', 'Complément ajouté.');
    }

    public function update(Request $request, Supplement $supplement)
    {
        if ($supplement->user_id !== Auth::id()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'servings_remaining' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['required', 'integer', 'min:0'],
        ]);

        $supplement->update($validated);

        return redirect()->back()->with('success', 'Complément mis à jour.');
    }

    public function destroy(Supplement $supplement)
    {
        if ($supplement->user_id !== Auth::id()) {
            abort(403);
        }

        $supplement->delete();

        return redirect()->back()->with('success', 'Complément supprimé.');
    }

    public function consume(Request $request, Supplement $supplement)
    {
        if ($supplement->user_id !== Auth::id()) {
            abort(403);
        }

        // Create log
        SupplementLog::create([
            'user_id' => Auth::id(),
            'supplement_id' => $supplement->id,
            'quantity' => 1,
            'consumed_at' => now(),
        ]);

        if ($supplement->servings_remaining > 0) {
            $supplement->decrement('servings_remaining');
        }

        return redirect()->back()->with('success', 'Consommation enregistrée.');
    }
}
