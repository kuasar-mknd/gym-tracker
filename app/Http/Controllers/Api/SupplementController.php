<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\SupplementStoreRequest;
use App\Http\Requests\Api\SupplementUpdateRequest;
use App\Http\Resources\SupplementResource;
use App\Models\Supplement;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Spatie\QueryBuilder\QueryBuilder;

class SupplementController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/supplements',
        summary: 'Get list of supplements',
        tags: ['Supplements']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', Supplement::class);

        $supplements = QueryBuilder::for(Supplement::class)
            ->allowedIncludes(['latestLog'])
            ->allowedFilters(['name', 'brand'])
            ->allowedSorts(['name', 'created_at', 'servings_remaining'])
            ->defaultSort('name')
            ->where('user_id', $this->user()->id)
            ->paginate();

        return SupplementResource::collection($supplements);
    }

    #[OA\Post(
        path: '/supplements',
        summary: 'Create a new supplement',
        tags: ['Supplements']
    )]
    #[OA\Response(response: 201, description: 'Created successfully')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(SupplementStoreRequest $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', Supplement::class);

        $validated = $request->validated();

        $supplement = new Supplement($validated);
        /** @var int<0, max> $userId */
        $userId = $this->user()->id;
        $supplement->user_id = $userId;
        $supplement->save();

        return (new SupplementResource($supplement))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/supplements/{supplement}',
        summary: 'Get a specific supplement',
        tags: ['Supplements']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 404, description: 'Not found')]
    public function show(Supplement $supplement): SupplementResource
    {
        $this->authorize('view', $supplement);

        $supplement->load(['latestLog']);

        return new SupplementResource($supplement);
    }

    #[OA\Put(
        path: '/supplements/{supplement}',
        summary: 'Update a supplement',
        tags: ['Supplements']
    )]
    #[OA\Response(response: 200, description: 'Updated successfully')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function update(SupplementUpdateRequest $request, Supplement $supplement): SupplementResource
    {
        $this->authorize('update', $supplement);

        $validated = $request->validated();

        $supplement->update($validated);

        return new SupplementResource($supplement);
    }

    #[OA\Delete(
        path: '/supplements/{supplement}',
        summary: 'Delete a supplement',
        tags: ['Supplements']
    )]
    #[OA\Response(response: 204, description: 'Deleted successfully')]
    public function destroy(Supplement $supplement): \Illuminate\Http\Response
    {
        $this->authorize('delete', $supplement);

        $supplement->delete();

        return response()->noContent();
    }
}
