<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Equipment;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EquipmentController extends Controller
{
    public function index(): \Inertia\Response
    {
        $equipment = $this->user()->equipment()
            ->orderBy('is_active', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Equipment/Index', [
            'equipment' => $equipment,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'purchased_at' => 'nullable|date',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $equipment = new Equipment($validated);
        $equipment->user_id = $this->user()->id;
        $equipment->save();

        return redirect()->back()->with('success', 'Equipment added successfully.');
    }

    public function update(Request $request, Equipment $equipment): \Illuminate\Http\RedirectResponse
    {
        if ($equipment->user_id !== $this->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'purchased_at' => 'nullable|date',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $equipment->update($validated);

        return redirect()->back()->with('success', 'Equipment updated successfully.');
    }

    public function destroy(Equipment $equipment): \Illuminate\Http\RedirectResponse
    {
        if ($equipment->user_id !== $this->user()->id) {
            abort(403);
        }

        $equipment->delete();

        return redirect()->back()->with('success', 'Equipment deleted successfully.');
    }
}
