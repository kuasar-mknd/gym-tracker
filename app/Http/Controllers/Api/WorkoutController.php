<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\CreateWorkoutAction;
use App\Http\Requests\WorkoutStoreRequest;
use App\Http\Requests\WorkoutUpdateRequest;
use App\Http\Resources\WorkoutResource;
use App\Models\Workout;
use App\Models\WorkoutLine;
use OpenApi\Attributes as OA;

/**
 * Controller for managing user workouts via API.
 *
 * This controller handles CRUD operations for the `Workout` model, ensuring
 * only authorized users can view, create, update, and delete their own workouts.
 * It also triggers background jobs to recalculate user statistics when a workout
 * is created, updated, or deleted.
 */
class WorkoutController extends Controller
{
    /**
     * Display a listing of the user's workouts.
     *
     * Retrieves a paginated list of workouts belonging to the authenticated user.
     * Supports sorting (e.g., `-started_at`) and eager loading of relationships
     * such as `workoutLines`, `workoutLines.exercise`, and `workoutLines.sets`.
     *
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[OA\Get(
        path: '/workouts',
        summary: 'Get list of workouts',
        tags: ['Workouts']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', Workout::class);

        $workouts = \Spatie\QueryBuilder\QueryBuilder::for(Workout::class)
            ->allowedIncludes('workoutLines', 'workoutLines.exercise', 'workoutLines.sets')
            ->allowedSorts('started_at', 'ended_at', 'created_at')
            ->defaultSort('-started_at')
            ->where('user_id', $this->user()->id)
            ->paginate();

        return WorkoutResource::collection($workouts);
    }

    /**
     * Store a newly created workout in storage.
     *
     * Validates the request data and creates a new workout for the authenticated user.
     * Dispatches a background job to recalculate user statistics after creation.
     *
     * @param  \App\Http\Requests\WorkoutStoreRequest  $request  The incoming validated request.
     * @return \App\Http\Resources\WorkoutResource The newly created workout resource.
     */
    #[OA\Post(
        path: '/workouts',
        summary: 'Create a new workout',
        tags: ['Workouts']
    )]
    #[OA\Response(response: 201, description: 'Created successfully')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(WorkoutStoreRequest $request, CreateWorkoutAction $createWorkoutAction): WorkoutResource
    {
        $this->authorize('create', Workout::class);

        $workout = $createWorkoutAction->execute($this->user(), $request->validated());

        return new WorkoutResource($workout);
    }

    /**
     * Display the specified workout.
     *
     * Retrieves the details of a specific workout, eager loading its lines, exercises,
     * and sets. It also batch-loads recommended values for the workout lines to
     * optimize performance.
     *
     * @param  \App\Models\Workout  $workout  The workout instance to display.
     * @return \App\Http\Resources\WorkoutResource The requested workout resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[OA\Get(
        path: '/workouts/{workout}',
        summary: 'Get a specific workout',
        tags: ['Workouts']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Not found')]
    public function show(Workout $workout): WorkoutResource
    {
        $this->authorize('view', $workout);

        $workout->load(['workoutLines.exercise', 'workoutLines.sets']);

        // ⚡ Perf: Batch-load recommended values in 1-2 queries instead of N+1
        WorkoutLine::batchRecommendedValues($workout->workoutLines, $this->user()->id);

        return new WorkoutResource($workout);
    }

    /**
     * Update the specified workout in storage.
     *
     * Validates the request data and updates the existing workout.
     * Dispatches a background job to recalculate the user's statistics
     * based on the updated workout data.
     *
     * @param  \App\Http\Requests\WorkoutUpdateRequest  $request  The incoming validated request.
     * @param  \App\Models\Workout  $workout  The workout instance to update.
     * @return \App\Http\Resources\WorkoutResource The updated workout resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[OA\Put(
        path: '/workouts/{workout}',
        summary: 'Update a workout',
        tags: ['Workouts']
    )]
    #[OA\Response(response: 200, description: 'Updated successfully')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Not found')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function update(WorkoutUpdateRequest $request, Workout $workout): WorkoutResource
    {
        $this->authorize('update', $workout);

        $validated = $request->validated();

        $workout->update($validated);

        \App\Jobs\RecalculateUserStats::dispatch($workout->user);

        return new WorkoutResource($workout);
    }

    /**
     * Remove the specified workout from storage.
     *
     * Deletes the given workout and dispatches a background job to recalculate
     * the user's statistics, removing the impact of the deleted workout.
     *
     * @param  \App\Models\Workout  $workout  The workout instance to delete.
     * @return \Illuminate\Http\Response An empty response indicating successful deletion.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[OA\Delete(
        path: '/workouts/{workout}',
        summary: 'Delete a workout',
        tags: ['Workouts']
    )]
    #[OA\Response(response: 204, description: 'Deleted successfully')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Not found')]
    public function destroy(Workout $workout): \Illuminate\Http\Response
    {
        $this->authorize('delete', $workout);

        $user = $workout->user;
        $workout->delete();

        \App\Jobs\RecalculateUserStats::dispatch($user);

        return response()->noContent();
    }

    /**
     * Renumerote les exercices d'une seance depuis l'ordre soumis.
     *
     * Un point d'entree a part plutot qu'un champ de plus sur `update` : la
     * page de seance ecrit par petites touches — une serie, une valeur — et
     * renvoyer la seance entiere pour deplacer un exercice se battrait avec
     * l'ordonnancement optimiste qui tient ces ecritures.
     */
    #[OA\Patch(
        path: '/workouts/{workout}/line-order',
        summary: 'Reorder the exercises of a workout',
        tags: ['Workouts']
    )]
    #[OA\Response(response: 200, description: 'Reordered successfully')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Not found')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function reorderLines(
        \App\Http\Requests\Api\WorkoutLineOrderRequest $request,
        Workout $workout,
        \App\Actions\Workouts\ReorderWorkoutLinesAction $reordonner
    ): WorkoutResource {
        $this->authorize('update', $workout);

        /** @var list<int> $lignes */
        $lignes = array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, (array) $request->validated('lines'));

        /*
         * L'ordre soumis doit etre une PERMUTATION des lignes de la seance : une
         * liste partielle laisserait les absentes sur leur rang d'origine, donc
         * en double avec celles qu'on vient de renumeroter.
         *
         * Verifie ICI, apres l'autorisation, et non dans la requete de
         * validation : une lecture faite avant elle couterait une requete de
         * plus pour une seance qui existe mais n'appartient pas a l'appelant
         * que pour une seance inconnue. Le contrat de non-divulgation compte
         * ces requetes.
         */
        $attendues = $workout->workoutLines()->pluck('id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        $soumises = $lignes;
        sort($soumises);
        sort($attendues);

        if ($soumises !== $attendues) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'lines' => 'La liste doit contenir exactement les exercices de la séance, une fois chacun.',
            ]);
        }

        $reordonner->execute($workout, $lignes);

        return new WorkoutResource($workout->load(['workoutLines.exercise', 'workoutLines.sets']));
    }
}
