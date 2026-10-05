<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\AuthentifieLaSessionDuCompte;
use App\Http\Requests\DeletePushSubscriptionRequest;
use App\Http\Requests\UpdatePushSubscriptionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PushSubscriptionController extends Controller
{
    /**
     * Enregistre l'abonnement push de l'appareil, si sa session tient encore
     * au moment d'écrire.
     *
     * Le middleware vérifie la session à l'entrée de la requête ; la
     * validation résout ensuite le nom d'hôte de l'adresse
     * (`PublicPushEndpoint`), ce qui peut durer. Un mot de passe changé
     * pendant ce temps retirait les abonnements du compte (`User::booted()`)
     * AVANT que celui-ci ne s'écrive : la session fermée gardait le sien, et
     * l'appareil continuait de recevoir.
     *
     * Le compte est donc relu sous le verrou de sa ligne, la session
     * revérifiée contre lui, et l'abonnement écrit dans la même transaction.
     * Un changement de mot de passe met cette ligne à jour, donc prend le même
     * verrou : ou bien il attend que l'abonnement soit écrit, et son retrait,
     * qui suit, l'emporte ; ou bien il est déjà fait, et la session est fermée
     * avant toute écriture, comme le middleware l'aurait fait. Un compte
     * supprimé entre-temps est refusé de même, sans laisser de ligne.
     */
    public function update(UpdatePushSubscriptionRequest $request, AuthentifieLaSessionDuCompte $verificationDeLaSession): JsonResponse
    {
        /** @var array{endpoint: string, keys: array{auth: string, p256dh: string}} $donneesValidees */
        $donneesValidees = $request->validated();
        $compte = $this->user();

        DB::transaction(function () use ($request, $verificationDeLaSession, $compte, $donneesValidees): void {
            $verificationDeLaSession->fermerSiLaSessionNeTientPlus(
                $request,
                User::query()->whereKey($compte->getKey())->lockForUpdate()->first(['id', 'password']),
            );

            $compte->updatePushSubscription(
                $donneesValidees['endpoint'],
                $donneesValidees['keys']['p256dh'],
                $donneesValidees['keys']['auth']
            );
        });

        return response()->json(['message' => 'Abonnement enregistré avec succès.']);
    }

    /**
     * Delete a push subscription.
     */
    public function destroy(DeletePushSubscriptionRequest $request): JsonResponse
    {
        /** @var array{endpoint: string} $donneesValidees */
        $donneesValidees = $request->validated();

        $this->user()->deletePushSubscription($donneesValidees['endpoint']);

        return response()->json(['message' => 'Abonnement supprimé avec succès.']);
    }
}
