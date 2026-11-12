<?php

declare(strict_types=1);

use App\Actions\Diagnostic\CreationDeJetonRefusee;
use App\Actions\Diagnostic\CreeUnJetonDeDiagnosticAction;
use App\Actions\Diagnostic\RevoqueLesJetonsDeDiagnosticAction;
use App\Enums\EvenementDAlerteDeDiagnostic;
use App\Enums\PorteeDeDiagnostic;
use App\Enums\RaisonDeRevocationDuJeton;
use App\Models\Admin;
use App\Models\JetonDeDiagnostic;
use App\Notifications\AlerteDeDiagnostic;
use App\Support\AlertesDeDiagnostic;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\Support\FilamentAdminPanel;

/*
 * Les alertes de l'accès de diagnostic (#2032) : ce qui part, vers qui, avec
 * quoi, et la limite de chaque déclencheur dans le temps. L'horloge est
 * arrêtée, puis avancée : la limite se juge sur elle.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 10:00:00');
    config()->set('app.diagnostic_enabled', true);
    config()->set('health.notifications.mail.to', 'alertes@example.org');
    Cache::flush();
    Notification::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic $evenement): int
{
    /** @var array<string, array<string, array<class-string, list<array{notification: AlerteDeDiagnostic}>>>> $envoyees */
    $envoyees = Notification::sentNotifications();
    $alertes = collect($envoyees[AnonymousNotifiable::class] ?? [])
        ->flatMap(fn (array $parNotification): array => $parNotification[AlerteDeDiagnostic::class] ?? []);

    return $alertes->filter(fn (array $envoi): bool => $envoi['notification']->evenement === $evenement)->count();
}

it('alerte à la création, sans le nom du jeton ni son identifiant entier', function (): void {
    $admin = FilamentAdminPanel::admin(['view-diagnostic']);
    $admin->assignRole(Role::findOrCreate(Admin::roleDuSuperAdministrateur(), 'admin'));
    $secret = new Google2FA()->generateSecretKey();
    $admin->forceFill(['app_authentication_secret' => $secret])->save();

    $cree = resolve(CreeUnJetonDeDiagnosticAction::class)->execute(
        $admin->refresh(),
        'Nom <b>venu de dehors</b>',
        [PorteeDeDiagnostic::Sante, PorteeDeDiagnostic::Exceptions],
        7,
        new Google2FA()->getCurrentOtp($secret),
    );

    Notification::assertSentOnDemand(AlerteDeDiagnostic::class, function (AlerteDeDiagnostic $alerte, array $canaux, AnonymousNotifiable $destinataire) use ($cree): bool {
        $courriel = $alerte->toMail($destinataire);
        $texte = $courriel->subject.' '.implode(' ', array_map(static fn (mixed $ligne): string => is_string($ligne) ? $ligne : '', $courriel->introLines));

        expect($destinataire->routes['mail'])->toBe(['alertes@example.org'])
            ->and($alerte->evenement)->toBe(EvenementDAlerteDeDiagnostic::Creation)
            ->and($alerte->jetons)->toBe([substr($cree['jeton']->identifiant, -4)])
            ->and($alerte->portees)->toBe(['sante', 'exceptions'])
            ->and($texte)->toContain('…'.substr($cree['jeton']->identifiant, -4), 'sante, exceptions', '19/11/2026 à 10:00')
            ->and($texte)->not->toContain('venu de dehors')
            ->and($texte)->not->toContain($cree['jeton']->identifiant)
            ->and($texte)->not->toContain($cree['en_clair']);

        return true;
    });
});

it('n’alerte pas d’une création refusée', function (): void {
    $admin = FilamentAdminPanel::admin(['view-diagnostic']);

    expect(fn () => resolve(CreeUnJetonDeDiagnosticAction::class)->execute($admin, 'Agent', [PorteeDeDiagnostic::Sante], 7, '000000'))
        ->toThrow(CreationDeJetonRefusee::class);

    Notification::assertNothingSent();
});

it('alerte d’une révocation par un seul courriel pour tous les jetons révoqués, avec sa raison', function (): void {
    $premier = JetonDeDiagnostic::factory()->create();
    $second = JetonDeDiagnostic::factory()->create();
    JetonDeDiagnostic::factory()->revoque()->create();

    resolve(RevoqueLesJetonsDeDiagnosticAction::class)->execute(RaisonDeRevocationDuJeton::Interrupteur);

    Notification::assertSentOnDemandTimes(AlerteDeDiagnostic::class, 1);
    Notification::assertSentOnDemand(AlerteDeDiagnostic::class, function (AlerteDeDiagnostic $alerte) use ($premier, $second): bool {
        expect($alerte->evenement)->toBe(EvenementDAlerteDeDiagnostic::Revocation)
            ->and($alerte->jetons)->toEqualCanonicalizing([substr($premier->identifiant, -4), substr($second->identifiant, -4)])
            ->and($alerte->raison)->toBe('interrupteur');

        return true;
    });
});

it('n’alerte pas d’une révocation qui ne révoque rien', function (): void {
    resolve(RevoqueLesJetonsDeDiagnosticAction::class)->execute(RaisonDeRevocationDuJeton::Manuelle);

    Notification::assertNothingSent();
});

it('alerte aussi d’une révocation automatique, par le mot de passe de l’administrateur', function (): void {
    $jeton = JetonDeDiagnostic::factory()->create();

    $jeton->createur()->firstOrFail()->forceFill(['password' => 'un-autre-mot-de-passe'])->save();

    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::Revocation))->toBe(1);
    Notification::assertSentOnDemand(AlerteDeDiagnostic::class, fn (AlerteDeDiagnostic $alerte): bool => $alerte->raison === 'mot-de-passe-change');
});

it('ne part nulle part, sans rien planter, quand HEALTH_TO_ADDRESS est vide', function (): void {
    config()->set('health.notifications.mail.to', '');
    $jeton = JetonDeDiagnostic::factory()->create();
    $alertes = resolve(AlertesDeDiagnostic::class);

    resolve(RevoqueLesJetonsDeDiagnosticAction::class)->execute(RaisonDeRevocationDuJeton::Manuelle);
    $alertes->jetonCree($jeton);
    $alertes->premierUsage($jeton);
    $alertes->jetonInvalidePresente($jeton);
    $alertes->debitDepasse($jeton);

    Notification::assertNothingSent();
    expect(AlertesDeDiagnostic::adresses())->toBe([]);
});

it('lit une liste d’adresses et ignore les vides', function (): void {
    config()->set('health.notifications.mail.to', [' a@example.org ', '', 'b@example.org']);

    expect(AlertesDeDiagnostic::adresses())->toBe(['a@example.org', 'b@example.org']);
});

it('ne défait pas ce qu’il annonce quand l’envoi échoue', function (): void {
    Notification::shouldReceive('route')->andThrow(new RuntimeException('file injoignable'));
    $jeton = JetonDeDiagnostic::factory()->create();

    resolve(AlertesDeDiagnostic::class)->jetonCree($jeton);
    $revoques = resolve(RevoqueLesJetonsDeDiagnosticAction::class)->execute(RaisonDeRevocationDuJeton::Manuelle);

    expect($revoques)->toHaveCount(1)
        ->and($jeton->refresh()->revoque_le)->not->toBeNull();
});

it('alerte du premier usage une seule fois par jeton, quel que soit le temps passé', function (): void {
    $jeton = JetonDeDiagnostic::factory()->create();
    $autre = JetonDeDiagnostic::factory()->create();
    $alertes = resolve(AlertesDeDiagnostic::class);

    $alertes->premierUsage($jeton);
    Carbon::setTestNow(now()->addDays(3));
    $alertes->premierUsage($jeton);
    $alertes->premierUsage($autre);

    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::PremierUsage))->toBe(2);
});

it('alerte d’un jeton mort présenté une fois par quinze minutes', function (): void {
    $jeton = JetonDeDiagnostic::factory()->expire()->create();
    $autre = JetonDeDiagnostic::factory()->revoque()->create();
    $alertes = resolve(AlertesDeDiagnostic::class);

    $alertes->jetonInvalidePresente($jeton);
    $alertes->jetonInvalidePresente($jeton);
    $alertes->jetonInvalidePresente($autre);

    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::JetonInvalidePresente))->toBe(2);

    Carbon::setTestNow(now()->addSeconds(AlertesDeDiagnostic::SECONDES_ENTRE_DEUX_PRESENTATIONS - 1));
    $alertes->jetonInvalidePresente($jeton);
    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::JetonInvalidePresente))->toBe(2);

    Carbon::setTestNow(now()->addSeconds(1));
    $alertes->jetonInvalidePresente($jeton);
    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::JetonInvalidePresente))->toBe(3);
});

it('passe à une alerte par jour dès la onzième présentation d’un jeton mort', function (): void {
    $jeton = JetonDeDiagnostic::factory()->revoque()->create();
    $alertes = resolve(AlertesDeDiagnostic::class);
    $envoyees = fn (): int => alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::JetonInvalidePresente);

    foreach (range(1, AlertesDeDiagnostic::PRESENTATIONS_AVANT_LA_CADENCE_JOURNALIERE) as $presentation) {
        $alertes->jetonInvalidePresente($jeton);
        Carbon::setTestNow(now()->addSeconds(AlertesDeDiagnostic::SECONDES_ENTRE_DEUX_PRESENTATIONS));
    }

    expect($envoyees())->toBe(10);

    $alertes->jetonInvalidePresente($jeton);
    expect($envoyees())->toBe(11);

    Carbon::setTestNow(now()->addSeconds(AlertesDeDiagnostic::SECONDES_ENTRE_DEUX_PRESENTATIONS * 4));
    $alertes->jetonInvalidePresente($jeton);
    expect($envoyees())->toBe(11);

    Carbon::setTestNow(now()->addSeconds(AlertesDeDiagnostic::SECONDES_PAR_JOUR));
    $alertes->jetonInvalidePresente($jeton);
    expect($envoyees())->toBe(12);
});

it('pose la trace que lit le contrôle de santé à chaque présentation, même quand le courriel ne part pas', function (): void {
    $jeton = JetonDeDiagnostic::factory()->revoque()->create();
    $alertes = resolve(AlertesDeDiagnostic::class);

    $alertes->jetonInvalidePresente($jeton);
    Carbon::setTestNow(now()->addMinutes(5));
    $alertes->jetonInvalidePresente($jeton);

    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::JetonInvalidePresente))->toBe(1)
        ->and(Cache::get(AlertesDeDiagnostic::CLE_DE_LA_DERNIERE_PRESENTATION))->toBe(now()->toIso8601String());
});

it('alerte d’un débit dépassé une fois par heure et par jeton', function (): void {
    $jeton = JetonDeDiagnostic::factory()->create();
    $autre = JetonDeDiagnostic::factory()->create();
    $alertes = resolve(AlertesDeDiagnostic::class);

    $alertes->debitDepasse($jeton);
    $alertes->debitDepasse($jeton);
    $alertes->debitDepasse($autre);
    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::DebitDepasse))->toBe(2);

    Carbon::setTestNow(now()->addSeconds(AlertesDeDiagnostic::SECONDES_ENTRE_DEUX_DEBITS_DEPASSES - 1));
    $alertes->debitDepasse($jeton);
    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::DebitDepasse))->toBe(2);

    Carbon::setTestNow(now()->addSeconds(1));
    $alertes->debitDepasse($jeton);
    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::DebitDepasse))->toBe(3);
});

it('ne porte dans la file que des scalaires et une énumération fermée', function (): void {
    $jeton = JetonDeDiagnostic::factory()->create(['nom' => 'Nom venu de dehors']);

    resolve(AlertesDeDiagnostic::class)->jetonCree($jeton);

    Notification::assertSentOnDemand(AlerteDeDiagnostic::class, function (AlerteDeDiagnostic $alerte): bool {
        $serialisee = serialize($alerte);

        expect($serialisee)->not->toContain('venu de dehors')
            ->and($serialisee)->not->toContain(JetonDeDiagnostic::class);

        return true;
    });
});
