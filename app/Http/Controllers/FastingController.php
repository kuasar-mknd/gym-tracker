<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Fasting\DemarrerUnJeuneAction;
use App\Actions\Fasting\FetchFastingIndexAction;
use App\Actions\Fasting\MettreAJourUnJeuneAction;
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
     * `StoreFastRequest` refuse tôt un second jeûne ; l'action le revérifie
     * sous le verrou du compte, que deux requêtes simultanées franchissent.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'a pas le droit de démarrer un jeûne.
     */
    public function store(StoreFastRequest $request, DemarrerUnJeuneAction $demarrerUnJeune): RedirectResponse
    {
        $this->authorize('create', Fast::class);

        $demarrerUnJeune->execute($this->user(), $request->validated());

        return back()->with('success', 'Jeûne commencé.');
    }

    /**
     * Sert aussi bien à clore un jeûne qu'à en corriger la durée ou le type.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException Si le jeûne n'est pas celui de l'utilisateur.
     */
    public function update(UpdateFastRequest $request, Fast $fast, MettreAJourUnJeuneAction $mettreAJourUnJeune): RedirectResponse
    {
        $this->authorize('update', $fast);

        $mettreAJourUnJeune->execute($this->user(), $fast, $request->validated());

        return back()->with('success', 'Jeûne mis à jour.');
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si le jeûne n'est pas celui de l'utilisateur.
     */
    public function destroy(Fast $fast): RedirectResponse
    {
        $this->authorize('delete', $fast);

        $fast->delete();

        return back()->with('success', 'Jeûne supprimé.');
    }
}
