<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreHabitRequest;
use App\Http\Requests\Api\UpdateHabitRequest;
use App\Http\Resources\HabitResource;
use App\Models\Habit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

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
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', Habit::class);

        $habits = QueryBuilder::for(Habit::class)
            ->allowedIncludes(['logs'])
            ->allowedFilters([
                AllowedFilter::exact('archived'),
                'name',
            ])
            ->allowedSorts(['name', 'created_at', 'goal_times_per_week'])
            ->defaultSort('name')
            ->where('user_id', $this->user()->id)
            ->paginate($request->get('per_page', 15));

        return HabitResource::collection($habits);
    }

    #[OA\Post(
        path: '/habits',
        summary: 'Create a new habit',
        tags: ['Habits']
    )]
    #[OA\Response(response: 201, description: 'Created successfully')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(StoreHabitRequest $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', Habit::class);

        $validated = $request->validated();

        if (($validated['color'] ?? null) === null) {
            $validated['color'] = 'bg-slate-500';
        }
        if (($validated['icon'] ?? null) === null) {
            $validated['icon'] = 'check_circle';
        }

        $habit = new Habit($validated);
        $habit->user_id = $this->user()->id;
        $habit->save();

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
