<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Corps\FetchCorpsAction;
use App\Models\BodyMeasurement;
use App\Models\BodyPartMeasurement;
use Inertia\Inertia;

/**
 * Le corps en une page : le poids, la masse grasse et les dernières
 * mensurations. Chacune de ces mesures s'enregistre sur sa page
 * (`BodyMeasurementController`, `BodyPartMeasurementController`) ; celle-ci ne
 * fait que les lire.
 */
class CorpsController extends Controller
{
    /**
     * Les courbes arrivent en prop différée, une fois l'écran affiché ; les
     * chiffres immédiats sont des fermetures que la requête de cette prop ne
     * calcule plus (#2148, étape 3a).
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'a pas le droit de consulter ses mensurations.
     */
    public function index(FetchCorpsAction $corps): \Inertia\Response
    {
        $this->authorize('viewAny', BodyMeasurement::class);
        $this->authorize('viewAny', BodyPartMeasurement::class);

        $props = $corps->execute($this->user());

        return Inertia::render('Corps/Index', [
            ...$props,
            'bodyStats' => Inertia::defer($props['bodyStats']),
        ]);
    }
}
