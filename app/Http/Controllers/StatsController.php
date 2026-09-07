<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Stats\GetStatsDashboardAction;
use App\Models\Exercise;
use App\Services\Stats\ExerciseStatsService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StatsController extends Controller
{
    public function __construct(protected ExerciseStatsService $exerciseStats)
    {
    }

    /**
     * La page part avec le strict nécessaire ; les calculs lourds — tendances,
     * répartitions — arrivent en prop différée, une fois l'écran affiché.
     */
    public function index(Request $request, GetStatsDashboardAction $getStatsDashboard): \Inertia\Response
    {
        $data = $getStatsDashboard->execute($this->user(), $request);

        // ⚡ Bolt : une seule prop différée plutôt que deux — une requête XHR au
        // lieu de deux, et des graphiques apparentés qui apparaissent ensemble
        // au lieu de s'afficher l'un après l'autre.
        /** @var callable(): mixed $deferredDataCallable */
        $deferredDataCallable = $data['deferredData'];
        $data['deferredData'] = Inertia::defer($deferredDataCallable);

        return Inertia::render('Stats/Index', $data);
    }

    /**
     * La progression du 1RM d'un exercice, en JSON : le graphique la redemande
     * quand on change d'exercice, sans recharger la page.
     */
    public function exercise(Exercise $exercise): \Illuminate\Http\JsonResponse
    {
        $this->authorize('view', $exercise);

        return response()->json([
            'progress' => $this->exerciseStats->getExercise1RMProgress($this->user(), $exercise->id),
        ]);
    }
}
