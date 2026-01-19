<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NotificationPreferenceStoreRequest;
use App\Http\Requests\Api\NotificationPreferenceUpdateRequest;
use App\Http\Resources\NotificationPreferenceResource;
use App\Models\NotificationPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NotificationPreferenceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $preferences = $request->user()->notificationPreferences()->get();

        return NotificationPreferenceResource::collection($preferences);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(NotificationPreferenceStoreRequest $request)
    {
        $preference = $request->user()->notificationPreferences()->create($request->validated());

        return (new NotificationPreferenceResource($preference))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(NotificationPreference $notificationPreference)
    {
        if ($notificationPreference->user_id !== auth()->id()) {
            abort(403);
        }

        return new NotificationPreferenceResource($notificationPreference);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(NotificationPreferenceUpdateRequest $request, NotificationPreference $notificationPreference)
    {
        if ($notificationPreference->user_id !== auth()->id()) {
            abort(403);
        }

        $notificationPreference->update($request->validated());

        return new NotificationPreferenceResource($notificationPreference);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(NotificationPreference $notificationPreference)
    {
        if ($notificationPreference->user_id !== auth()->id()) {
            abort(403);
        }

        $notificationPreference->delete();

        return response()->noContent();
    }
}
