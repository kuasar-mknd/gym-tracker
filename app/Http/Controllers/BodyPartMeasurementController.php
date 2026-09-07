<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Measurements\FetchBodyPartMeasurementShowAction;
use App\Actions\Measurements\FetchBodyPartMeasurementsIndexAction;
use App\Http\Requests\BodyPartMeasurementStoreRequest;
use App\Models\BodyPartMeasurement;
use Inertia\Inertia;

/**
 * Les tours de bras, de taille, de cuisse — chaque partie est désignée par son
 * nom en texte libre, à la différence du poids et de la masse grasse qui ont
 * chacun leur colonne dans `BodyMeasurementController`.
 */
class BodyPartMeasurementController extends Controller
{
    public function index(FetchBodyPartMeasurementsIndexAction $action): \Inertia\Response
    {
        $this->authorize('viewAny', BodyPartMeasurement::class);

        return Inertia::render('Measurements/Parts/Index', $action->execute($this->user()));
    }

    /**
     * La partie vient de l'URL, pas d'une table : un nom sans relevé donne un
     * historique vide, et on renvoie à la liste plutôt que d'afficher une page
     * de graphiques sans rien dedans.
     */
    public function show(string $part, FetchBodyPartMeasurementShowAction $action): \Illuminate\Http\RedirectResponse|\Inertia\Response
    {
        $this->authorize('viewAny', BodyPartMeasurement::class);

        $user = $this->user();

        $historique = $action->execute($user, $part);

        if ($historique->isEmpty()) {
            return redirect()->route('body-parts.index');
        }

        return Inertia::render('Measurements/Parts/Show', [
            'part' => $part,
            'history' => $historique,
        ]);
    }

    public function store(BodyPartMeasurementStoreRequest $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', BodyPartMeasurement::class);

        /** @var \App\Models\User $user */
        $user = $request->user();
        $user->bodyPartMeasurements()->create($request->validated());

        return redirect()->back()->with('success', 'Measurement added.');
    }

    public function destroy(BodyPartMeasurement $bodyPartMeasurement): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $bodyPartMeasurement);

        $bodyPartMeasurement->delete();

        return redirect()->back()->with('success', 'Measurement deleted.');
    }
}
