<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Plate;
use Inertia\Inertia;

/**
 * Controller for managing Plates.
 *
 * This controller handles the CRUD operations for user's weight plates,
 * which are used in the plate calculator tool to determine how to load a barbell.
 */
class PlateController extends Controller
{
    /**
     * Display a listing of the user's plates.
     *
     * Retrieves all plates for the authenticated user, ordered by weight descending.
     *
     * @return \Inertia\Response The Inertia response rendering the Tools/PlateCalculator page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to view plates.
     */
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

    /**
     * Store a newly created plate in storage.
     *
     * Validates and creates a new plate for the authenticated user.
     *
     * @param  \App\Http\Requests\StorePlateRequest  $request  The validated request containing plate details.
     * @return \Illuminate\Http\RedirectResponse A redirect back to the previous page.
     */
    public function store(\App\Http\Requests\StorePlateRequest $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', Plate::class);

        $validated = $request->validated();

        $plate = new Plate($validated);
        $plate->user_id = $this->user()->id;
        $plate->save();

        return redirect()->back();
    }

    /**
     * Update the specified plate in storage.
     *
     * @param  \App\Http\Requests\UpdatePlateRequest  $request  The validated request containing updated fields.
     * @param  \App\Models\Plate  $plate  The plate to update.
     * @return \Illuminate\Http\RedirectResponse A redirect back to the previous page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update this plate.
     */
    public function update(\App\Http\Requests\UpdatePlateRequest $request, Plate $plate): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $plate);

        $plate->update($request->validated());

        return redirect()->back();
    }

    /**
     * Remove the specified plate from storage.
     *
     * @param  \App\Models\Plate  $plate  The plate to delete.
     * @return \Illuminate\Http\RedirectResponse A redirect back to the previous page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete this plate.
     */
    public function destroy(Plate $plate): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $plate);

        $plate->delete();

        return redirect()->back();
    }
}
