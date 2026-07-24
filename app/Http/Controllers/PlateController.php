<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StorePlateRequest;
use App\Http\Requests\UpdatePlateRequest;
use App\Models\Plate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

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
     * @return Response The Inertia response rendering the Tools/PlateCalculator page.
     *
     * @throws AuthorizationException If the user is not authorized to view plates.
     */
    public function index(): Response
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
     * @param  StorePlateRequest  $request  The validated request containing plate details.
     * @return RedirectResponse A redirect back to the previous page.
     */
    public function store(StorePlateRequest $request): RedirectResponse
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
     * @param  UpdatePlateRequest  $request  The validated request containing updated fields.
     * @param  Plate  $plate  The plate to update.
     * @return RedirectResponse A redirect back to the previous page.
     *
     * @throws AuthorizationException If the user is not authorized to update this plate.
     */
    public function update(UpdatePlateRequest $request, Plate $plate): RedirectResponse
    {
        $this->authorize('update', $plate);

        $plate->update($request->validated());

        return redirect()->back();
    }

    /**
     * Remove the specified plate from storage.
     *
     * @param  Plate  $plate  The plate to delete.
     * @return RedirectResponse A redirect back to the previous page.
     *
     * @throws AuthorizationException If the user is not authorized to delete this plate.
     */
    public function destroy(Plate $plate): RedirectResponse
    {
        $this->authorize('delete', $plate);

        $plate->delete();

        return redirect()->back();
    }
}
