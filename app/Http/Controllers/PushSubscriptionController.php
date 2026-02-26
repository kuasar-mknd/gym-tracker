<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DeletePushSubscriptionRequest;
use App\Http\Requests\UpdatePushSubscriptionRequest;
use Illuminate\Http\JsonResponse;

class PushSubscriptionController extends Controller
{
    /**
     * Store a new push subscription.
     */
    public function update(UpdatePushSubscriptionRequest $request): JsonResponse
    {
        /** @var array{endpoint: string, keys: array{auth: string, p256dh: string}} $validated */
        $validated = $request->validated();

        $this->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth']
        );

        return response()->json(['message' => 'Abonnement enregistré avec succès.']);
    }

    /**
     * Delete a push subscription.
     */
    public function destroy(DeletePushSubscriptionRequest $request): JsonResponse
    {
        /** @var array{endpoint: string} $validated */
        $validated = $request->validated();

        $this->user()->deletePushSubscription($validated['endpoint']);

        return response()->json(['message' => 'Abonnement supprimé avec succès.']);
    }
}
