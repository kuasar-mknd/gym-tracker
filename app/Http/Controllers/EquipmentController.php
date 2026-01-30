<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreEquipmentRequest;
use App\Http\Requests\UpdateEquipmentRequest;
use App\Models\Equipment;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;

class EquipmentController extends Controller
{
    use AuthorizesRequests;

    public function index(): \Inertia\Response
    {
        $this->authorize('viewAny', Equipment::class);

        $equipment = $this->user()->equipment()
            ->orderBy('is_active', 'desc') // Active first
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Equipment/Index', [
            'equipment' => $equipment,
        ]);
    }

    public function store(StoreEquipmentRequest $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', Equipment::class);

        $validated = $request->validated();

        // Ensure user_id is set
        $equipment = new Equipment($validated);
        $equipment->user_id = $this->user()->id;
        $equipment->save();

        return redirect()->back()->with('success', 'Equipment created successfully.');
    }

    public function update(UpdateEquipmentRequest $request, Equipment $equipment): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $equipment);

        $equipment->update($request->validated());

        return redirect()->back()->with('success', 'Equipment updated successfully.');
    }

    public function destroy(Equipment $equipment): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $equipment);

        $equipment->delete();

        return redirect()->back()->with('success', 'Equipment deleted successfully.');
    }
}
