<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Exercises\CreateExerciseAction;
use App\Actions\Exercises\FetchExerciseHistoryAction;
use App\Http\Requests\ExerciseStoreRequest;
use App\Http\Requests\ExerciseUpdateRequest;
use App\Models\Exercise;
use App\Services\Stats\ExerciseStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La bibliothèque d'exercices de l'utilisateur.
 *
 * Toute écriture invalide le cache de la liste, par les crochets `saved` et
 * `deleted` du modèle : cette liste est lue à l'ouverture de chaque séance, et
 * périmée, elle ne montre pas l'exercice qu'on vient de créer.
 */
class ExerciseController extends Controller
{
    public function show(Exercise $exercise, ExerciseStatsService $exerciseStats, FetchExerciseHistoryAction $fetchExerciseHistory): Response
    {
        $this->authorize('view', $exercise);

        $progress = $exerciseStats->getExercise1RMProgress($this->user(), $exercise->id, 365);
        $history = $fetchExerciseHistory->execute($this->user(), $exercise);

        return Inertia::render('Exercises/Show', [
            'exercise' => $exercise,
            'progress' => $progress,
            'history' => $history,
        ]);
    }

    public function index(): Response
    {
        $this->authorize('viewAny', Exercise::class);

        $exercises = Exercise::getCachedForUser($this->user()->id);

        return Inertia::render('Exercises/Index', [
            'exercises' => $exercises,
        ]);
    }

    /**
     * Répond en JSON à qui le demande : la fenêtre de création rapide, ouverte
     * au milieu d'une séance, a besoin de l'exercice créé sans quitter la page.
     *
     * @return RedirectResponse|JsonResponse L'exercice créé en JSON, ou un retour en arrière.
     */
    public function store(ExerciseStoreRequest $request, CreateExerciseAction $createExerciseAction): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Exercise::class);

        $data = $request->validated();
        $exercise = $createExerciseAction->execute($this->user(), $data);

        if ($request->wantsJson()) {
            return response()->json(['exercise' => $exercise], 201);
        }

        return redirect()->back()->with('success', 'Exercice créé avec succès');
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'exercice n'est pas celui de l'utilisateur.
     */
    public function update(ExerciseUpdateRequest $request, Exercise $exercise): RedirectResponse
    {
        $this->authorize('update', $exercise);

        $exercise->update($request->validated());
        $exercise->invalidateCache();

        return redirect()->back();
    }

    /**
     * Un exercice déjà utilisé dans une séance est refusé ici plutôt que
     * supprimé : `workout_lines.exercise_id` est une clef étrangère sans
     * ON DELETE, donc la base rejetterait l'effacement de toute façon — mais
     * par une erreur de contrainte, c'est-à-dire un 500 au lieu d'un message.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'exercice n'est pas celui de l'utilisateur.
     */
    public function destroy(Exercise $exercise): RedirectResponse
    {
        $this->authorize('delete', $exercise);

        if ($exercise->workoutLines()->exists()) {
            return redirect()->back()->withErrors([
                'exercise' => 'Cet exercice est utilisé dans une séance et ne peut pas être supprimé.',
            ]);
        }

        $exercise->delete();
        $exercise->invalidateCache();

        return redirect()->back();
    }
}
