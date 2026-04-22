<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DailyJournalStoreRequest;
use App\Http\Requests\DailyJournalUpdateRequest;
use App\Http\Resources\DailyJournalResource;
use App\Models\DailyJournal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Controller for managing daily journals via the API.
 *
 * Provides endpoints for retrieving, creating, updating, and deleting
 * daily journals for the authenticated user.
 */
class DailyJournalController extends Controller
{
    /**
     * Display a listing of the user's daily journals.
     *
     * @param  Request  $request  The incoming request.
     * @return AnonymousResourceCollection A paginated collection of daily journals.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized.
     */
    #[OA\Get(
        path: '/api/v1/daily-journals',
        summary: 'List daily journals',
        tags: ['Daily Journals'],
    )]
    #[OA\Parameter(
        name: 'per_page',
        in: 'query',
        required: false,
        description: 'Number of items per page',
        schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)
    )]
    #[OA\Response(
        response: 200,
        description: 'Successful operation'
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthenticated'
    )]
    #[OA\Response(
        response: 403,
        description: 'Forbidden'
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DailyJournal::class);

        $validated = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        /** @var int $perPage */
        $perPage = $validated['per_page'] ?? 15;

        $journals = $this->user()->dailyJournals()
            ->orderBy('date', 'desc')
            ->paginate($perPage);

        return DailyJournalResource::collection($journals);
    }

    /**
     * Store a newly created daily journal.
     *
     * @param  DailyJournalStoreRequest  $request  The validated request data.
     * @return DailyJournalResource The created daily journal resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized.
     */
    #[OA\Post(
        path: '/api/v1/daily-journals',
        summary: 'Create a new daily journal',
        tags: ['Daily Journals'],
    )]
    #[OA\RequestBody(
        required: true,
        description: 'Daily journal data'
    )]
    #[OA\Response(
        response: 201,
        description: 'Journal created successfully'
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthenticated'
    )]
    #[OA\Response(
        response: 403,
        description: 'Forbidden'
    )]
    #[OA\Response(
        response: 422,
        description: 'Validation error'
    )]
    public function store(DailyJournalStoreRequest $request): DailyJournalResource
    {
        $this->authorize('create', DailyJournal::class);

        $validated = $request->validated();

        $journal = new DailyJournal($validated);
        $journal->user_id = $this->user()->id;
        $journal->save();

        return new DailyJournalResource($journal);
    }

    /**
     * Display the specified daily journal.
     *
     * @param  DailyJournal  $dailyJournal  The daily journal to display.
     * @return DailyJournalResource The daily journal resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized.
     */
    #[OA\Get(
        path: '/api/v1/daily-journals/{id}',
        summary: 'Get a specific daily journal',
        tags: ['Daily Journals'],
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'Journal ID',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(
        response: 200,
        description: 'Successful operation'
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthenticated'
    )]
    #[OA\Response(
        response: 403,
        description: 'Forbidden'
    )]
    #[OA\Response(
        response: 404,
        description: 'Journal not found'
    )]
    public function show(DailyJournal $dailyJournal): DailyJournalResource
    {
        $this->authorize('view', $dailyJournal);

        return new DailyJournalResource($dailyJournal);
    }

    /**
     * Update the specified daily journal in storage.
     *
     * @param  DailyJournalUpdateRequest  $request  The validated request data.
     * @param  DailyJournal  $dailyJournal  The daily journal to update.
     * @return DailyJournalResource The updated daily journal resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized.
     */
    #[OA\Put(
        path: '/api/v1/daily-journals/{id}',
        summary: 'Update an existing daily journal',
        tags: ['Daily Journals'],
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'Journal ID',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\RequestBody(
        required: true,
        description: 'Updated daily journal data'
    )]
    #[OA\Response(
        response: 200,
        description: 'Journal updated successfully'
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthenticated'
    )]
    #[OA\Response(
        response: 403,
        description: 'Forbidden'
    )]
    #[OA\Response(
        response: 404,
        description: 'Journal not found'
    )]
    #[OA\Response(
        response: 422,
        description: 'Validation error'
    )]
    public function update(DailyJournalUpdateRequest $request, DailyJournal $dailyJournal): DailyJournalResource
    {
        $this->authorize('update', $dailyJournal);

        $dailyJournal->update($request->validated());

        return new DailyJournalResource($dailyJournal);
    }

    /**
     * Remove the specified daily journal from storage.
     *
     * @param  DailyJournal  $dailyJournal  The daily journal to delete.
     * @return \Illuminate\Http\Response A no-content response.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized.
     */
    #[OA\Delete(
        path: '/api/v1/daily-journals/{id}',
        summary: 'Delete a daily journal',
        tags: ['Daily Journals'],
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'Journal ID',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(
        response: 204,
        description: 'Journal deleted successfully'
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthenticated'
    )]
    #[OA\Response(
        response: 403,
        description: 'Forbidden'
    )]
    #[OA\Response(
        response: 404,
        description: 'Journal not found'
    )]
    public function destroy(DailyJournal $dailyJournal): \Illuminate\Http\Response
    {
        $this->authorize('delete', $dailyJournal);

        $dailyJournal->delete();

        return response()->noContent();
    }
}
