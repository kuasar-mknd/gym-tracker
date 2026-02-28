<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NotificationPreferenceStoreRequest;
use App\Http\Requests\Api\NotificationPreferenceUpdateRequest;
use App\Http\Resources\NotificationPreferenceResource;
use App\Models\NotificationPreference;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\QueryBuilder;

class NotificationPreferenceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $preferences = QueryBuilder::for(NotificationPreference::class)
            ->where('user_id', $this->user()->id)
            ->allowedSorts(['type', 'created_at']); /** @phpstan-ignore-line */

        $preferences = $preferences->get();

        return NotificationPreferenceResource::collection($preferences);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(NotificationPreferenceStoreRequest $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validated();

        $preference = new NotificationPreference($validated);
        // @phpstan-ignore-next-line
        $preference->user_id = (int) $this->user()->id;
        $preference->save();

        return (new NotificationPreferenceResource($preference))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(NotificationPreference $notification_preference): NotificationPreferenceResource
    {
        if ($notification_preference->user_id !== $this->user()->id) {
            abort(403);
        }

        return new NotificationPreferenceResource($notification_preference);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(NotificationPreferenceUpdateRequest $request, NotificationPreference $notification_preference): NotificationPreferenceResource
    {
        if ($notification_preference->user_id !== $this->user()->id) {
            abort(403);
        }

        $notification_preference->update($request->validated());

        return new NotificationPreferenceResource($notification_preference);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(NotificationPreference $notification_preference): \Illuminate\Http\Response
    {
        if ($notification_preference->user_id !== $this->user()->id) {
            abort(403);
        }

        $notification_preference->delete();

        return response()->noContent();
    }
}
