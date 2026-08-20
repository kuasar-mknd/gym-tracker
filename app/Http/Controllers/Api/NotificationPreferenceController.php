<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NotificationPreferenceStoreRequest;
use App\Http\Requests\Api\NotificationPreferenceUpdateRequest;
use App\Http\Resources\NotificationPreferenceResource;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\QueryBuilder;

class NotificationPreferenceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', NotificationPreference::class);

        /*
         * Les methodes propres a Spatie d'abord, `where()` ensuite.
         *
         * `where()` est declaree par `Eloquent\Builder` et rend un `Builder`,
         * pas un `QueryBuilder` : l'appeler en premier perdait le type, et le
         * `@var QueryBuilder<...>` pose ensuite le CONTREDISAIT au lieu de le
         * preciser — d'ou l'entree au baseline (#1482). Dans cet ordre, plus
         * rien a annoter. Le `clone` partait avec : `for()` rend deja une
         * instance neuve.
         */
        $preferences = QueryBuilder::for(NotificationPreference::class)
            ->allowedSorts('type', 'created_at')
            ->where('user_id', $this->user()->id)
            ->get();

        return NotificationPreferenceResource::collection($preferences);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(NotificationPreferenceStoreRequest $request): JsonResponse
    {
        $this->authorize('create', NotificationPreference::class);

        $validated = $request->validated();

        $preference = new NotificationPreference($validated);
        // @phpstan-ignore-next-line
        $preference->user_id = (int) $this->user()->id;
        $preference->save();

        return new NotificationPreferenceResource($preference)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(NotificationPreference $notification_preference): NotificationPreferenceResource
    {
        $this->authorize('view', $notification_preference);

        return new NotificationPreferenceResource($notification_preference);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(NotificationPreferenceUpdateRequest $request, NotificationPreference $notification_preference): NotificationPreferenceResource
    {
        $this->authorize('update', $notification_preference);

        $notification_preference->update($request->validated());

        return new NotificationPreferenceResource($notification_preference);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(NotificationPreference $notification_preference): Response
    {
        $this->authorize('delete', $notification_preference);

        $notification_preference->delete();

        return response()->noContent();
    }
}
