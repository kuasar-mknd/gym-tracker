<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreFastRequest;
use App\Http\Requests\Api\UpdateFastRequest;
use App\Http\Resources\FastResource;
use App\Models\Fast;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * Controller for managing user fasts via API.
 *
 * Provides endpoints for creating, retrieving, updating, and deleting
 * fasting records for the authenticated user.
 */
class FastController extends Controller
{
    /**
     * Display a listing of the user's fasts.
     *
     * Retrieves a paginated list of fasting records belonging to the authenticated user.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection A collection of fast resources.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to view fasts.
     */
    #[OA\Get(
        path: '/fasts',
        summary: 'List user fasts',
        tags: ['Fasts']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Fast::class);

        $fasts = $this->user()->fasts()
            ->latest()
            ->paginate();

        return FastResource::collection($fasts);
    }

    /**
     * Store a newly created fast in storage.
     *
     * Validates the request data and starts a new active fast for the user.
     *
     * @param  \App\Http\Requests\Api\StoreFastRequest  $request  The incoming validated request.
     * @return \App\Http\Resources\FastResource The newly created fast resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to create a fast.
     */
    #[OA\Post(
        path: '/fasts',
        summary: 'Create a new fast',
        tags: ['Fasts']
    )]
    #[OA\Response(response: 201, description: 'Fast created successfully')]
    #[OA\Response(response: 400, description: 'Bad request')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(StoreFastRequest $request): FastResource
    {
        $this->authorize('create', Fast::class);

        $validated = $request->validated();

        /** @var Fast $fast */
        $fast = $this->user()->fasts()->create(array_merge(
            $validated,
            ['status' => 'active']
        ));

        $fast->refresh();

        return new FastResource($fast);
    }

    /**
     * Display the specified fast.
     *
     * Retrieves the details of a specific fasting record.
     *
     * @param  \App\Models\Fast  $fast  The fast instance to display.
     * @return \App\Http\Resources\FastResource The requested fast resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to view the fast.
     */
    #[OA\Get(
        path: '/fasts/{id}',
        summary: 'Get a specific fast',
        tags: ['Fasts']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID of the fast',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Fast not found')]
    public function show(Fast $fast): FastResource
    {
        $this->authorize('view', $fast);

        return new FastResource($fast);
    }

    /**
     * Update the specified fast in storage.
     *
     * Modifies the details of an existing fast, for example ending an active fast.
     *
     * @param  \App\Http\Requests\Api\UpdateFastRequest  $request  The incoming validated request.
     * @param  \App\Models\Fast  $fast  The fast instance to update.
     * @return \App\Http\Resources\FastResource The updated fast resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update the fast.
     */
    #[OA\Put(
        path: '/fasts/{id}',
        summary: 'Update a fast',
        tags: ['Fasts']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID of the fast',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 200, description: 'Fast updated successfully')]
    #[OA\Response(response: 400, description: 'Bad request')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Fast not found')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function update(UpdateFastRequest $request, Fast $fast): FastResource
    {
        $this->authorize('update', $fast);

        $fast->update($request->validated());

        return new FastResource($fast);
    }

    /**
     * Remove the specified fast from storage.
     *
     * Permanently deletes a fasting record from the user's history.
     *
     * @param  \App\Models\Fast  $fast  The fast instance to delete.
     * @return \Illuminate\Http\Response An empty HTTP response indicating success.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete the fast.
     */
    #[OA\Delete(
        path: '/fasts/{id}',
        summary: 'Delete a fast',
        tags: ['Fasts']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID of the fast to delete',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 204, description: 'Fast deleted successfully')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Fast not found')]
    public function destroy(Fast $fast): Response
    {
        $this->authorize('delete', $fast);

        $fast->delete();

        return response()->noContent();
    }
}
