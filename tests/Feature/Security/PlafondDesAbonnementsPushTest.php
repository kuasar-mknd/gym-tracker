<?php

declare(strict_types=1);

use App\Http\Controllers\PushSubscriptionController;
use App\Models\User;

function transmettreUnAbonnement(User $compte, string $nom): void
{
    test()->actingAs($compte)->postJson(route('push-subscriptions.update'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$nom,
        'keys' => ['p256dh' => 'clef-'.$nom, 'auth' => 'auth-'.$nom],
    ])->assertOk();
}

it('garde les plus récents et retire le plus ancien au-delà du plafond', function (): void {
    $compte = User::factory()->create();

    foreach (range(1, PushSubscriptionController::ABONNEMENTS_MAX + 1) as $numero) {
        transmettreUnAbonnement($compte, 'appareil-'.$numero);
    }

    $adresses = $compte->pushSubscriptions()->orderBy('id')->pluck('endpoint');

    expect($adresses)->toHaveCount(PushSubscriptionController::ABONNEMENTS_MAX)
        ->and($adresses->first())->toEndWith('appareil-2')
        ->and($adresses->last())->toEndWith('appareil-'.(PushSubscriptionController::ABONNEMENTS_MAX + 1));
});

it('ramène un compte déjà au-delà du plafond sans retirer l’appareil qui transmet', function (): void {
    $compte = User::factory()->create();

    foreach (range(1, 15) as $numero) {
        $compte->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/ancien-'.$numero,
            'public_key' => 'k',
            'auth_token' => 'a',
        ]);
    }

    transmettreUnAbonnement($compte, 'ancien-1');

    expect($compte->pushSubscriptions()->count())->toBe(PushSubscriptionController::ABONNEMENTS_MAX)
        ->and($compte->pushSubscriptions()->where('endpoint', 'like', '%/ancien-1')->exists())->toBeTrue();
});

it('ne touche ni aux autres comptes ni à une retransmission sous le plafond', function (): void {
    $compte = User::factory()->create();
    $autre = User::factory()->create();

    foreach (range(1, PushSubscriptionController::ABONNEMENTS_MAX) as $numero) {
        transmettreUnAbonnement($autre, 'autre-'.$numero);
    }
    transmettreUnAbonnement($compte, 'seul');
    transmettreUnAbonnement($compte, 'seul');

    expect($autre->pushSubscriptions()->count())->toBe(PushSubscriptionController::ABONNEMENTS_MAX)
        ->and($compte->pushSubscriptions()->count())->toBe(1);
});
