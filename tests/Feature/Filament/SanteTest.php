<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\TachePlanifiee;
use App\Support\Sante\TachesPlanifieesCheck;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use ShuvroRoy\FilamentSpatieLaravelHealth\Pages\HealthCheckResults;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Facades\Health;
use Spatie\Health\Notifications\CheckFailedNotification;
use Spatie\Health\Notifications\Notifiable;
use Spatie\Permission\Models\Role;

/**
 * La page « Santé » du panneau : le pendant en production de ce que Doctor
 * regarde dans la CI. Les résultats vivent dans le cache, pas en base : le
 * NAS paie chaque écriture SQL entre 350 ms et 1,7 s, et neuf lignes toutes
 * les cinq minutes n'apprendraient rien que le dernier passage ne dise déjà.
 */
it('ouvre la page de santé au super administrateur, et à lui seul', function (): void {
    $superAdmin = Admin::factory()->create();
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'admin'));

    $this->actingAs($superAdmin, 'admin')
        ->get(HealthCheckResults::getUrl(panel: 'admin'))
        ->assertOk();

    $this->actingAs(Admin::factory()->create(), 'admin')
        ->get(HealthCheckResults::getUrl(panel: 'admin'))
        ->assertForbidden();
});

it('regarde la base, le cache, la file, le planificateur, Horizon, le disque, les sauvegardes et la configuration', function (): void {
    $noms = Health::registeredChecks()
        ->map(fn (Check $check): string => $check->getName())
        ->all();

    expect($noms)->toBe([
        'Database',
        'Redis',
        'Cache',
        'Queue',
        'Schedule',
        'TachesPlanifiees',
        'Horizon',
        'UsedDiskSpace',
        'Backups',
        'DebugMode',
        'Environment',
        'OptimizedApp',
    ]);
});

it('trouve la base en bonne santé', function (): void {
    expect(DatabaseCheck::new()->run()->status->value)->toBe('ok');
});

/**
 * Les deux battements sont ce que `ScheduleCheck` et `QueueCheck` attendent ;
 * le contrôle lui-même tourne toutes les cinq minutes et range ses résultats
 * pour la page.
 */
it('planifie les deux battements et le contrôle', function (): void {
    $commandes = collect(app(Schedule::class)->events())
        ->map(fn ($evenement): string => (string) $evenement->command)
        ->all();

    foreach (['health:schedule-check-heartbeat', 'health:queue-check-heartbeat', 'health:check'] as $commande) {
        expect(array_filter($commandes, fn (string $ligne): bool => str_contains($ligne, $commande)))
            ->not->toBeEmpty("{$commande} n'est pas planifiée");
    }
});

/**
 * Ce que Sentry surveillait par ses moniteurs de tâches, lu ici dans le
 * moniteur local (#1511).
 */
it('met les tâches planifiées au vert, à l’orange quand l’une est en retard, au rouge quand l’une a échoué', function (): void {
    $this->travelTo('2026-09-05 20:00:00');

    $quotidienne = TachePlanifiee::query()->create([
        'name' => 'app:remind-training',
        'type' => 'command',
        'cron_expression' => '0 18 * * *',
        'grace_time_in_minutes' => 5,
        'last_started_at' => '2026-09-05 18:00:00',
        'last_finished_at' => '2026-09-05 18:00:03',
    ]);

    $verte = TachesPlanifieesCheck::new()->run();
    expect($verte->status->value)->toBe('ok')
        ->and($verte->shortSummary)->toBe('1 suivies, 0 échouées, 0 en retard');

    TachePlanifiee::query()->create([
        'name' => 'backup:run',
        'type' => 'command',
        'cron_expression' => '30 2 * * *',
        'grace_time_in_minutes' => 5,
        'created_at' => '2026-09-03 09:00:00',
    ]);

    $orange = TachesPlanifieesCheck::new()->run();
    expect($orange->status->value)->toBe('warning')
        ->and($orange->notificationMessage)->toBe('En retard : backup:run')
        ->and($orange->meta['en_retard'])->toBe(['backup:run']);

    $quotidienne->forceFill(['last_failed_at' => '2026-09-05 18:00:02'])->save();

    $rouge = TachesPlanifieesCheck::new()->run();
    expect($rouge->status->value)->toBe('failed')
        ->and($rouge->notificationMessage)->toBe('Échouée : app:remind-training')
        ->and($rouge->meta['echouees'])->toBe(['app:remind-training'])
        ->and($rouge->shortSummary)->toBe('2 suivies, 1 échouées, 1 en retard');
});

/**
 * Une adresse suffit à allumer les courriels ; sans adresse rien ne part, et
 * seul un contrôle au rouge écrit : un orange qui dure écrirait chaque heure.
 */
it('n’écrit un courriel que si une adresse est posée, et seulement pour un rouge', function (): void {
    expect(config('health.notifications.enabled'))->toBeFalse()
        ->and(config('health.notifications.mail.to'))->toBe('')
        ->and(config('health.notifications.only_on_failure'))->toBeTrue();

    Notification::fake();
    Config::set('health.notifications.enabled', true);
    Config::set('health.notifications.mail.to', 'sam@exemple.test');
    Health::clearChecks();
    Health::checks([TachesPlanifieesCheck::new()]);

    TachePlanifiee::query()->create([
        'name' => 'backup:run',
        'type' => 'command',
        'cron_expression' => '30 2 * * *',
        'grace_time_in_minutes' => 5,
        'created_at' => '2026-09-03 09:00:00',
    ]);

    $this->artisan('health:check')->assertSuccessful();
    Notification::assertNothingSent();

    TachePlanifiee::query()->create([
        'name' => 'app:remind-training',
        'type' => 'command',
        'cron_expression' => '0 18 * * *',
        'grace_time_in_minutes' => 5,
        'last_started_at' => '2026-09-05 18:00:00',
        'last_failed_at' => '2026-09-05 18:00:02',
    ]);

    $this->artisan('health:check')->assertSuccessful();
    Notification::assertSentTo(app(Notifiable::class), CheckFailedNotification::class);
});
