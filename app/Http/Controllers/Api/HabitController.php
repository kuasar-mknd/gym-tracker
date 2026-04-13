<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Habits\CreateHabitAction;
use App\Actions\Habits\FetchHabitsIndexApiAction;
use App\Http\Requests\Api\StoreHabitRequest;
use App\Http\Requests\Api\UpdateHabitRequest;
use App\Http\Resources\HabitResource;
use App\Models\Habit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * Controller for managing User Habits via API.
 *
 * This controller handles the CRUD operations for habits.
 * It provides endpoints to list, create, view, update, and delete habits
 * for the authenticated user.
 */
class HabitController extends Controller
{
    /**
     * Display a listing of the user's habits.
     *
     * Retrieves all active habits for the authenticated user, supporting
     * optional pagination.
     *
     * @param  \Illuminate\Http\Request  $request  The incoming HTTP request containing optional query parameters.
     * @param  \App\Actions\Habits\FetchHabitsIndexApiAction  $fetchHabitsIndexApiAction  The action to fetch habits.
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection A collection of habit resources.
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to view habits.
     */
    #[OA\Get(
        path: '/habits',
        summary: 'Get list of habits',
        tags: ['Habits']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index(Request $request, FetchHabitsIndexApiAction $fetchHabitsIndexApiAction): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', Habit::class);

        $validated = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $habits = $fetchHabitsIndexApiAction->execute($this->user(), $validated);

        return HabitResource::collection($habits);
    }

    /**
     * Store a newly created habit in storage.
     *
     * Validates the request data and uses the CreateHabitAction to persist
     * the habit.
     *
     * @param  \App\Http\Requests\Api\StoreHabitRequest  $request  The validated request containing habit data.
     * @param  \App\Actions\Habits\CreateHabitAction  $createHabitAction  Action to handle habit creation logic.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the created habit resource.
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to create habits.
     */
    #[OA\Post(
        path: '/habits',
        summary: 'Create a new habit',
        tags: ['Habits']
    )]
    #[OA\Response(response: 201, description: 'Created successfully')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(StoreHabitRequest $request, CreateHabitAction $createHabitAction): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', Habit::class);

        $validated = $request->validated();

        $habit = $createHabitAction->execute($this->user(), $validated);

        return (new HabitResource($habit))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified habit.
     *
     * Retrieves a single habit by its ID, along with its most recent logs.
     *
     * @param  \App\Models\Habit  $habit  The habit model instance.
     * @return \App\Http\Resources\HabitResource The habit resource including its recent logs.
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to view the habit.
     */
    #[OA\Get(
        path: '/habits/{habit}',
        summary: 'Get a specific habit',
        tags: ['Habits']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 404, description: 'Not found')]
    public function show(Habit $habit): HabitResource
    {
        $this->authorize('view', $habit);

        $habit->load([
            'logs' => function ($query): void {
                $query->latest('date')->limit(10);
            },
        ]);

        return new HabitResource($habit);
    }

    /**
     * Update the specified habit in storage.
     *
     * Validates the request data and updates the existing habit model.
     *
     * @param  \App\Http\Requests\Api\UpdateHabitRequest  $request  The validated request containing updated habit data.
     * @param  \App\Models\Habit  $habit  The habit model instance to update.
     * @return \App\Http\Resources\HabitResource The updated habit resource.
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update the habit.
     */
    #[OA\Put(
        path: '/habits/{habit}',
        summary: 'Update a habit',
        tags: ['Habits']
    )]
    #[OA\Response(response: 200, description: 'Updated successfully')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function update(UpdateHabitRequest $request, Habit $habit): HabitResource
    {
        $this->authorize('update', $habit);

        $validated = $request->validated();

        $habit->update($validated);

        return new HabitResource($habit);
    }

    /**
     * Remove the specified habit from storage.
     *
     * Deletes the given habit model from the database.
     *
     * @param  \App\Models\Habit  $habit  The habit model instance to delete.
     * @return \Illuminate\Http\Response An empty response with a 204 status code indicating successful deletion.
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete the habit.
     */
    #[OA\Delete(
        path: '/habits/{habit}',
        summary: 'Delete a habit',
        tags: ['Habits']
    )]
    #[OA\Response(response: 204, description: 'Deleted successfully')]
    public function destroy(Habit $habit): \Illuminate\Http\Response
    {
        $this->authorize('delete', $habit);

        $habit->delete();

        return response()->noContent();
    }
}
