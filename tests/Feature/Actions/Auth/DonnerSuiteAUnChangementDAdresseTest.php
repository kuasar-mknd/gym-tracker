<?php

declare(strict_types=1);

use App\Actions\Auth\DonnerSuiteAUnChangementDAdresse;
use App\Models\User;
use App\Notifications\LienDeChangementDAdresse;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

it('borne le lien vers une adresse sous une clé qui ne distingue pas la casse', function (): void {
    Notification::fake();
    $this->freezeSecond();
    $compte = User::factory()->create();
    $action = resolve(DonnerSuiteAUnChangementDAdresse::class);
    $cle = 'lien-de-changement-d-adresse:'.hash('sha256', 'nouvelle@example.org');

    expect($action->execute($compte, 'Nouvelle@Example.org'))->toBeTrue()
        ->and(RateLimiter::attempts($cle))->toBe(1)
        ->and(RateLimiter::availableIn($cle))->toBe(DonnerSuiteAUnChangementDAdresse::SECONDES_ENTRE_DEUX_LIENS)
        ->and(DonnerSuiteAUnChangementDAdresse::SECONDES_ENTRE_DEUX_LIENS)->toBe(900)
        ->and($action->execute($compte, 'nouvelle@example.org'))->toBeFalse();

    Notification::assertSentOnDemandTimes(LienDeChangementDAdresse::class, 1);
});
