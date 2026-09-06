<?php

declare(strict_types=1);

use App\Models\Admin;
use Illuminate\Console\Scheduling\Schedule;
use ShuvroRoy\FilamentSpatieLaravelHealth\Pages\HealthCheckResults;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Facades\Health;
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
