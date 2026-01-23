<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    /**
     * Store a new push subscription.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|url',
            'keys.auth' => 'required',
            'keys.p256dh' => 'required',
        ]);

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
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|url',
        ]);

        $this->user()->deletePushSubscription($validated['endpoint']);

        return response()->json(['message' => 'Abonnement supprimé avec succès.']);
    }
}
