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

class FastingController extends Controller
{
    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'a pas le droit de consulter les jeûnes.
     */
    public function index(FetchFastingIndexAction $fetchFastingIndexAction): Response
    {
        $this->authorize('viewAny', Fast::class);

        $data = $fetchFastingIndexAction->execute($this->user());

        return Inertia::render('Tools/Fasting/Index', $data);
    }

    /**
     * Rien ici n'empêche un second jeûne simultané : la garde est une règle de
     * `StoreFastRequest`, qui refuse la requête avant d'arriver jusqu'ici.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'a pas le droit de démarrer un jeûne.
     */
    public function store(StoreFastRequest $request): RedirectResponse
    {
        $this->authorize('create', Fast::class);

        $user = $this->user();

        $user->fasts()->create([
            ...$request->validated(),
            'status' => 'active',
        ]);

        return back()->with('success', 'Fast started successfully.');
    }

    /**
     * Sert aussi bien à clore un jeûne qu'à en corriger la durée ou le type.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException Si le jeûne n'est pas celui de l'utilisateur.
     */
    public function update(UpdateFastRequest $request, Fast $fast): RedirectResponse
    {
        $this->authorize('update', $fast);

        $fast->update($request->validated());

        return back()->with('success', 'Fast updated successfully.');
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si le jeûne n'est pas celui de l'utilisateur.
     */
    public function destroy(Fast $fast): RedirectResponse
    {
        $this->authorize('delete', $fast);

        $fast->delete();

        return back()->with('success', 'Fast deleted successfully.');
    }
}
