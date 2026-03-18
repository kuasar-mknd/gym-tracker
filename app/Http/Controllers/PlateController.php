<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Plate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;

/**
 * Controller for managing user-specific weight plates.
 *
 * Handles the calculation, creation, updating, and deletion of plates
 * used in the Plate Calculator tool.
 */
class PlateController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display the plate calculator and the user's saved plates.
     *
     * @return \Inertia\Response The Inertia response rendering the Tools/PlateCalculator page.
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
     * @param  \App\Http\Requests\StorePlateRequest  $request  The validated request containing plate data.
     * @return \Illuminate\Http\RedirectResponse A redirect back to the previous page.
     */
    public function store(\App\Http\Requests\StorePlateRequest $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validated();

        $plate = new Plate($validated);
        $plate->user_id = $this->user()->id;
        $plate->save();

        return redirect()->back();
    }

    /**
     * Update the specified plate in storage.
     *
     * @param  \App\Http\Requests\UpdatePlateRequest  $request  The validated request containing updated plate data.
     * @param  \App\Models\Plate  $plate  The plate instance to update.
     * @return \Illuminate\Http\RedirectResponse A redirect back to the previous page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update the plate.
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
     * @param  \App\Models\Plate  $plate  The plate instance to delete.
     * @return \Illuminate\Http\RedirectResponse A redirect back to the previous page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete the plate.
     */
    public function destroy(Plate $plate): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $plate);

        $plate->delete();

        return redirect()->back();
    }
}
