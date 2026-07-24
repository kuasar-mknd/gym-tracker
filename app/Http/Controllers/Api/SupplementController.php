<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\SupplementStoreRequest;
use App\Http\Requests\Api\SupplementUpdateRequest;
use App\Http\Resources\SupplementResource;
use App\Models\Supplement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Controller for managing user supplements via API.
 *
 * Provides endpoints to list, create, retrieve, update, and delete
 * supplement records for the authenticated user.
 */
class SupplementController extends Controller
{
    /**
     * Display a listing of the user's supplements.
     *
     * Retrieves a paginated list of supplements belonging to the authenticated user.
     * Supports filtering by name/brand, sorting, and eager loading the latest log.
     *
     * @return AnonymousResourceCollection A collection of supplement resources.
     *
     * @throws AuthorizationException If the user is not authorized to view supplements.
     */
    #[OA\Get(
        path: '/supplements',
        summary: 'Get list of supplements',
        tags: ['Supplements']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index(): AnonymousResourceCollection
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

    /**
     * Store a newly created supplement in storage.
     *
     * Validates the request data and creates a new supplement record for the authenticated user.
     *
     * @param  SupplementStoreRequest  $request  The incoming validated request.
     * @return JsonResponse A JSON response containing the newly created supplement.
     *
     * @throws AuthorizationException If the user is not authorized to create a supplement.
     */
    #[OA\Post(
        path: '/supplements',
        summary: 'Create a new supplement',
        tags: ['Supplements']
    )]
    #[OA\Response(response: 201, description: 'Created successfully')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(SupplementStoreRequest $request): JsonResponse
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

    /**
     * Display the specified supplement.
     *
     * Retrieves the details of a specific supplement record, along with its latest usage log.
     *
     * @param  Supplement  $supplement  The supplement instance to display.
     * @return SupplementResource The requested supplement resource.
     *
     * @throws AuthorizationException If the user is not authorized to view the supplement.
     */
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

    /**
     * Update the specified supplement in storage.
     *
     * Validates the incoming request and modifies the details of an existing supplement.
     *
     * @param  SupplementUpdateRequest  $request  The incoming validated request.
     * @param  Supplement  $supplement  The supplement instance to update.
     * @return SupplementResource The updated supplement resource.
     *
     * @throws AuthorizationException If the user is not authorized to update the supplement.
     */
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

    /**
     * Remove the specified supplement from storage.
     *
     * Permanently deletes a supplement record from the user's inventory.
     *
     * @param  Supplement  $supplement  The supplement instance to delete.
     * @return Response An empty HTTP response indicating success.
     *
     * @throws AuthorizationException If the user is not authorized to delete the supplement.
     */
    #[OA\Delete(
        path: '/supplements/{supplement}',
        summary: 'Delete a supplement',
        tags: ['Supplements']
    )]
    #[OA\Response(response: 204, description: 'Deleted successfully')]
    public function destroy(Supplement $supplement): Response
    {
        $this->authorize('delete', $supplement);

        $supplement->delete();

        return response()->noContent();
    }
}
