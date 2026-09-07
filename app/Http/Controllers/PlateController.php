<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Plate;
use Inertia\Inertia;

/**
 * L'inventaire de disques de l'utilisateur, dont le calculateur se sert pour
 * dire comment charger une barre.
 */
class PlateController extends Controller
{
    /**
     * Du plus lourd au plus léger : c'est l'ordre dans lequel le calculateur
     * les essaie, et celui dans lequel on les enfile sur la barre.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'a pas le droit de consulter ses disques.
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

    public function store(\App\Http\Requests\PlateRequest $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', Plate::class);

        $donneesValidees = $request->validated();

        $plate = new Plate($donneesValidees);
        $plate->user_id = $this->user()->id;
        $plate->save();

        return redirect()->back();
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si le disque n'est pas celui de l'utilisateur.
     */
    public function update(\App\Http\Requests\PlateRequest $request, Plate $plate): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $plate);

        $plate->update($request->validated());

        return redirect()->back();
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si le disque n'est pas celui de l'utilisateur.
     */
    public function destroy(Plate $plate): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $plate);

        $plate->delete();

        return redirect()->back();
    }
}
