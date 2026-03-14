<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Fasting\FetchFastingIndexAction;
use App\Http\Requests\Api\StoreFastRequest;
use App\Http\Requests\Api\UpdateFastRequest;
use App\Models\Fast;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing User Fasts.
 *
 * This controller handles the creation, retrieval, updating, and deletion
 * of fasting records. It provides the data for the fasting tracker frontend
 * and ensures proper authorization for all operations.
 */
class FastingController extends Controller
{
    /**
     * Display a listing of the user's fasts.
     *
     * Retrieves the fasting history and any currently active fast for
     * the authenticated user to render the fasting index page.
     *
     * @param  \App\Actions\Fasting\FetchFastingIndexAction  $fetchFastingIndexAction  The action to fetch fasting data.
     * @return \Inertia\Response The Inertia response rendering the 'Tools/Fasting/Index' page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to view fasts.
     */
    public function index(FetchFastingIndexAction $fetchFastingIndexAction): Response
    {
        $this->authorize('viewAny', Fast::class);

        $data = $fetchFastingIndexAction->execute($this->user());

        return Inertia::render('Tools/Fasting/Index', $data);
    }

    /**
     * Store a newly created fast in storage.
     *
     * Validates the request data and starts a new fast for the user.
     * Prevents starting a new fast if one is already currently active.
     *
     * @param  \App\Http\Requests\Api\StoreFastRequest  $request  The validated request containing fast details.
     * @return \Illuminate\Http\RedirectResponse Redirects back with a success message or an error if a fast is already active.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to create a fast.
     */
    public function store(StoreFastRequest $request): RedirectResponse
    {
        $this->authorize('create', Fast::class);

        $user = $this->user();

        // Check if there is already an active fast
        if ($user->fasts()->where('status', 'active')->exists()) {
            return back()->withErrors(['message' => 'You already have an active fast.']);
        }

        $user->fasts()->create([
            ...$request->validated(),
            'status' => 'active',
        ]);

        return back()->with('success', 'Fast started successfully.');
    }

    /**
     * Update the specified fast in storage.
     *
     * Validates the request and updates the details of an existing fast,
     * such as ending it or modifying its duration/type.
     *
     * @param  \App\Http\Requests\Api\UpdateFastRequest  $request  The validated request with updated fast details.
     * @param  \App\Models\Fast  $fast  The fast record to update.
     * @return \Illuminate\Http\RedirectResponse Redirects back with a success message.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update the fast.
     */
    public function update(UpdateFastRequest $request, Fast $fast): RedirectResponse
    {
        $this->authorize('update', $fast);

        $fast->update($request->validated());

        return back()->with('success', 'Fast updated successfully.');
    }

    /**
     * Remove the specified fast from storage.
     *
     * Permanently deletes a fasting record from the database.
     *
     * @param  \App\Models\Fast  $fast  The fast record to delete.
     * @return \Illuminate\Http\RedirectResponse Redirects back with a success message.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete the fast.
     */
    public function destroy(Fast $fast): RedirectResponse
    {
        $this->authorize('delete', $fast);

        $fast->delete();

        return back()->with('success', 'Fast deleted successfully.');
    }
}
