<?php

declare(strict_types=1);

use App\Filament\Resources\TachesPlanifiees\Pages\ListTachesPlanifiees;
use App\Filament\Resources\TachesPlanifiees\TachePlanifieeResource;
use App\Models\Admin;
use App\Models\TachePlanifiee;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\FilamentAdminPanel;

/**
 * Une tâche planifiée qui cesse de tourner ne lève aucune erreur (#1443).
 * Le moniteur note chaque passage, et la page dit lesquelles sont en retard.
 */
it('relit le planning : les tâches quotidiennes, pas les battements de santé', function (): void {
    expect(Artisan::call('schedule-monitor:sync'))->toBe(0);

    // Le nom porte les options de la commande (`backup:run --only-db='1' …`).
    $commandes = TachePlanifiee::query()->get()->map(fn (TachePlanifiee $tache): string => explode(' ', $tache->name)[0])->all();

    expect($commandes)->toContain('app:remind-training', 'app:verify-data-coherence', 'backup:run', 'backup:clean')
        ->and(array_filter($commandes, fn (string $nom): bool => str_starts_with($nom, 'health:')))->toBe([]);
});

it('dit la fréquence en français, et l’état d’après le dernier passage et la marge', function (): void {
    Carbon::setTestNow('2026-09-06 10:00:00');

    $quotidienne = TachePlanifiee::query()->create([
        'name' => 'app:remind-training',
        'type' => 'command',
        'cron_expression' => '0 18 * * *',
        'grace_time_in_minutes' => 5,
        'last_started_at' => '2026-09-05 18:00:00',
        'last_finished_at' => '2026-09-05 18:00:03',
    ]);

    expect($quotidienne->expressionLisible())->toBe('Chaque jour à 18:00')
        ->and($quotidienne->etat())->toBe(TachePlanifiee::A_L_HEURE);

    // Finie avant-hier : celle d'hier soir manque, et la marge est passée.
    $quotidienne->forceFill(['last_started_at' => '2026-09-04 18:00:00', 'last_finished_at' => '2026-09-04 18:00:03'])->save();
    expect($quotidienne->fresh()?->etat())->toBe(TachePlanifiee::EN_RETARD);

    // Jamais passée depuis sa création il y a deux jours : en retard aussi.
    $muette = TachePlanifiee::query()->create([
        'name' => 'backup:run',
        'type' => 'command',
        'cron_expression' => '30 2 * * *',
        'grace_time_in_minutes' => 5,
        'created_at' => '2026-09-04 09:00:00',
    ]);
    expect($muette->etat())->toBe(TachePlanifiee::EN_RETARD);

    // Un échec postérieur au dernier départ l'emporte sur tout le reste.
    $quotidienne->forceFill(['last_started_at' => '2026-09-05 18:00:00', 'last_finished_at' => '2026-09-05 18:00:03', 'last_failed_at' => '2026-09-05 18:00:02'])->save();
    expect($quotidienne->fresh()?->etat())->toBe(TachePlanifiee::ECHOUEE);
});

it('ouvre la liste au super administrateur, sans permission Shield', function (): void {
    $superAdmin = Admin::factory()->create();
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'admin'));

    $this->actingAs($superAdmin, 'admin')
        ->get(TachePlanifieeResource::getUrl('index', panel: 'admin'))
        ->assertOk();
});

it('ouvre la liste à qui porte la permission Shield', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:TachePlanifiee']), 'admin')
        ->get(TachePlanifieeResource::getUrl('index', panel: 'admin'))
        ->assertOk();
});

it('se refuse à un administrateur ordinaire', function (): void {
    $this->actingAs(Admin::factory()->create(), 'admin')
        ->get(TachePlanifieeResource::getUrl('index', panel: 'admin'))
        ->assertForbidden();
});

it('relit le planning d’un bouton', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:TachePlanifiee']), 'admin');

    Livewire::test(ListTachesPlanifiees::class)->callAction('synchroniser');

    expect(TachePlanifiee::query()->where('name', 'app:remind-training')->exists())->toBeTrue();
});

it('purge le journal du moniteur chaque nuit, sous surveillance', function (): void {
    $purges = collect(app(Schedule::class)->events())
        ->map(fn ($evenement): string => (string) $evenement->command)
        ->filter(fn (string $ligne): bool => str_contains($ligne, 'model:prune') && str_contains($ligne, 'MonitoredScheduledTaskLogItem'))
        ->all();

    expect($purges)->not->toBeEmpty();
});
