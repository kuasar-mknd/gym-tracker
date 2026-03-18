<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Habits\CreateHabitAction;
use App\Actions\Habits\FetchHabitsIndexApiAction;
use App\Http\Requests\Api\StoreHabitRequest;
use App\Http\Requests\Api\UpdateHabitRequest;
use App\Http\Resources\HabitResource;
use App\Models\Habit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

class HabitController extends Controller
{
    use AuthorizesRequests;

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
