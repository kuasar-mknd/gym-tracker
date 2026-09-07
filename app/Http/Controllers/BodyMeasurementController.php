<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BodyMeasurementStoreRequest;
use App\Models\BodyMeasurement;
use Inertia\Inertia;

/**
 * Le poids et la masse grasse, par opposition aux tours de bras et de taille
 * qui vivent dans `BodyPartMeasurementController`.
 *
 * Toute écriture vide le cache des statistiques de mensuration : sans quoi les
 * graphiques continuent d'afficher la courbe d'avant.
 */
class BodyMeasurementController extends Controller
{
    public function __construct(protected \App\Services\Stats\BodyStatsService $bodyStats, protected \App\Services\Stats\StatsCacheManager $statsCache)
    {
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'a pas le droit de consulter les mensurations.
     */
    public function index(): \Inertia\Response
    {
        $this->authorize('viewAny', BodyMeasurement::class);

        $measurements = $this->user()->bodyMeasurements()
            ->orderBy('measured_at', 'desc')
            ->limit(100)
            ->get();

        return Inertia::render('Measurements/Index', [
            'measurements' => $measurements,
            // ⚡ Bolt : les statistiques corporelles tiennent en une seule prop
            // différée plutôt que deux — une requête asynchrone au lieu de deux,
            // et une seule requête SQL et clef de cache pour l'historique du
            // poids comme pour celui de la masse grasse.
            'bodyStats' => Inertia::defer(fn (): array => $this->bodyStats->getBodyProgressOverview($this->user(), 365)),
        ]);
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'a pas le droit d'enregistrer une mensuration.
     */
    public function store(BodyMeasurementStoreRequest $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', BodyMeasurement::class);

        $this->user()->bodyMeasurements()->create($request->validated());

        $this->statsCache->clearBodyMeasurementStats($this->user());

        return redirect()->back();
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si la mensuration n'est pas celle de l'utilisateur.
     */
    public function destroy(BodyMeasurement $bodyMeasurement): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $bodyMeasurement);

        $user = $this->user();
        $bodyMeasurement->delete();

        $this->statsCache->clearBodyMeasurementStats($user);

        return redirect()->back();
    }
}
