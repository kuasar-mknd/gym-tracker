<?php

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
        $request->validate([
            'endpoint' => 'required|url',
            'keys.auth' => 'required',
            'keys.p256dh' => 'required',
        ]);

        $request->user()->updatePushSubscription(
            $request->endpoint,
            $request->keys['p256dh'],
            $request->keys['auth']
        );

        return response()->json(['message' => 'Abonnement enregistré avec succès.']);
    }

    /**
     * Delete a push subscription.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'endpoint' => 'required|url',
        ]);

        $request->user()->deletePushSubscription($request->endpoint);

        return response()->json(['message' => 'Abonnement supprimé avec succès.']);
    }
}
