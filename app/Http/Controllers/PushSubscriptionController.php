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
     * Le nombre d'appareils qu'un compte garde abonnés. Chaque notification
     * les joint en série dans une seule tâche de la file `default` : sans
     * plafond, un compte qui en enregistre des centaines occupe un processus
     * pendant des minutes. Le plus ancien cède sa place plutôt que de refuser
     * le nouveau : un refus fermerait l'appareil que la personne vient
     * d'activer, quand les abonnements les plus anciens sont ceux d'un
     * navigateur réinstallé ou d'un appareil rendu.
     */
    public const int ABONNEMENTS_MAX = 10;

    /**
     * Enregistre l'abonnement push de l'appareil, si sa session tient encore
     * au moment d'écrire.
     *
     * Le middleware vérifie la session à l'entrée de la requête ; la
     * validation résout ensuite le nom d'hôte de l'adresse
     * (`PublicPushEndpoint`), ce qui peut durer. Un mot de passe changé
     * pendant ce temps retirait les abonnements du compte
     * (`DetacheSesAppareilsPush`) AVANT que celui-ci ne s'écrive : la session
     * fermée gardait le sien, et l'appareil continuait de recevoir.
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

            $abonnement = $compte->updatePushSubscription(
                $donneesValidees['endpoint'],
                $donneesValidees['keys']['p256dh'],
                $donneesValidees['keys']['auth']
            );

            $gardes = $compte->pushSubscriptions()
                ->whereKeyNot($abonnement->getKey())
                ->latest('id')
                ->limit(self::ABONNEMENTS_MAX - 1)
                ->pluck('id');

            $compte->pushSubscriptions()
                ->whereKeyNot($abonnement->getKey())
                ->whereNotIn('id', $gardes)
                ->delete();
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
