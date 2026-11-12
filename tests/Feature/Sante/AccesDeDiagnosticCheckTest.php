<?php

declare(strict_types=1);

use App\Models\JetonDeDiagnostic;
use App\Support\AlertesDeDiagnostic;
use App\Support\Sante\AccesDeDiagnosticCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/*
 * Le contrôle « Accès de diagnostic » (#2032) dans chacun de ses états.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 10:00:00');
    config()->set('app.diagnostic_enabled', false);
    config()->set('health.notifications.mail.to', 'alertes@example.org');
    Cache::flush();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('dit « Fermé » quand l’accès est fermé et qu’aucun jeton n’est actif', function (): void {
    JetonDeDiagnostic::factory()->revoque()->create();
    JetonDeDiagnostic::factory()->expire()->create();

    $resultat = AccesDeDiagnosticCheck::new()->run();

    expect($resultat->status->value)->toBe('ok')
        ->and($resultat->getShortSummary())->toBe('Fermé')
        ->and($resultat->meta)->toBe(['ouvert' => false, 'jetons_actifs' => 0]);
});

it('compte les jetons actifs et dit jusqu’à quand, accès ouvert', function (): void {
    config()->set('app.diagnostic_enabled', true);
    JetonDeDiagnostic::factory()->create(['expire_le' => now()->addDays(3)]);
    JetonDeDiagnostic::factory()->create(['expire_le' => now()->addDays(7)]);
    JetonDeDiagnostic::factory()->revoque()->create();

    $resultat = AccesDeDiagnosticCheck::new()->run();

    expect($resultat->status->value)->toBe('ok')
        ->and($resultat->getShortSummary())->toBe("2 jeton(s) actif(s) jusqu'au 19/11/2026 à 10:00");
});

it('dit « Ouvert, aucun jeton actif » quand l’accès est ouvert sans jeton', function (): void {
    config()->set('app.diagnostic_enabled', true);

    $resultat = AccesDeDiagnosticCheck::new()->run();

    expect($resultat->status->value)->toBe('ok')
        ->and($resultat->getShortSummary())->toBe('Ouvert, aucun jeton actif');
});

it('passe à l’orange pendant vingt-quatre heures après la présentation d’un jeton mort', function (): void {
    config()->set('app.diagnostic_enabled', true);
    resolve(AlertesDeDiagnostic::class)->jetonInvalidePresente(JetonDeDiagnostic::factory()->revoque()->create());

    expect(AccesDeDiagnosticCheck::new()->run()->status->value)->toBe('warning');

    Carbon::setTestNow(now()->addHours(24)->subSecond());
    expect(AccesDeDiagnosticCheck::new()->run()->status->value)->toBe('warning');

    Carbon::setTestNow(now()->addSecond());
    expect(AccesDeDiagnosticCheck::new()->run()->status->value)->toBe('ok');
});

it('passe à l’orange quand l’accès est ouvert sans HEALTH_TO_ADDRESS', function (): void {
    config()->set('app.diagnostic_enabled', true);
    config()->set('health.notifications.mail.to', '');

    $resultat = AccesDeDiagnosticCheck::new()->run();

    expect($resultat->status->value)->toBe('warning')
        ->and($resultat->getNotificationMessage())->toContain('HEALTH_TO_ADDRESS');
});

it('reste vert accès fermé sans HEALTH_TO_ADDRESS : rien ne s’ouvre', function (): void {
    config()->set('health.notifications.mail.to', '');

    expect(AccesDeDiagnosticCheck::new()->run()->status->value)->toBe('ok');
});

it('passe à l’orange quand des jetons restent actifs alors que l’accès est fermé', function (): void {
    JetonDeDiagnostic::factory()->create();

    $resultat = AccesDeDiagnosticCheck::new()->run();

    expect($resultat->status->value)->toBe('warning')
        ->and($resultat->getNotificationMessage())->toContain('alors que l\'accès est fermé')
        ->and($resultat->meta)->toBe(['ouvert' => false, 'jetons_actifs' => 1]);
});

it('porte un libellé français et un nom stable', function (): void {
    $controle = AccesDeDiagnosticCheck::new();

    expect($controle->getLabel())->toBe('Accès de diagnostic')
        ->and($controle->getName())->toBe('AccesDeDiagnostic');
});
