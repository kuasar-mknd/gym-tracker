<?php

declare(strict_types=1);

use App\Actions\Auth\PrevenirLeTitulaireDeLAdresse;
use App\Enums\MotifDeLaTentative;
use App\Models\User;
use App\Notifications\TentativeSurTonAdresse;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Le créneau d'une heure se prend à la mise en file de l'avis. Si l'envoi
 * échoue pour de bon, le titulaire ne doit pas attendre une heure un
 * avertissement qui n'est jamais parti.
 */

it('rend le creneau quand l envoi de l avis echoue', function (): void {
    Queue::fake();
    $titulaire = User::factory()->create();
    $action = app(PrevenirLeTitulaireDeLAdresse::class);

    expect($action->execute($titulaire, MotifDeLaTentative::Inscription))->toBeTrue()
        ->and($action->execute($titulaire, MotifDeLaTentative::Inscription))->toBeFalse();

    $envoi = null;
    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $travail) use (&$envoi): bool {
        $envoi = $travail;

        return true;
    });

    $envoi->failed(new RuntimeException('SMTP indisponible'));

    expect($action->execute($titulaire, MotifDeLaTentative::Inscription))->toBeTrue();
});

it('garde le creneau d un autre compte quand l envoi echoue', function (): void {
    $titulaire = User::factory()->create();
    $autre = User::factory()->create();
    RateLimiter::hit(PrevenirLeTitulaireDeLAdresse::clefDuCompte($autre->id), 3600);

    new TentativeSurTonAdresse(MotifDeLaTentative::Inscription, $titulaire->id)->failed(new RuntimeException('x'));

    expect(RateLimiter::tooManyAttempts(PrevenirLeTitulaireDeLAdresse::clefDuCompte($autre->id), 1))->toBeTrue();
});

it('ne leve rien pour une charge d avant le correctif, sans compte', function (): void {
    new TentativeSurTonAdresse(MotifDeLaTentative::Inscription)->failed(new RuntimeException('x'));

    expect(true)->toBeTrue();
});
