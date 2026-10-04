<?php

declare(strict_types=1);

use App\Filament\Resources\TachesPlanifiees\Pages\ListTachesPlanifiees;
use App\Filament\Resources\TachesPlanifiees\TachePlanifieeResource;
use App\Models\Admin;
use App\Models\TachePlanifiee;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Console\Kernel;
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

/**
 * La sauvegarde nocturne telle que le planning la déclare, et sa ligne au
 * moniteur.
 *
 * @return array{0: Event, 1: TachePlanifiee}
 */
function tachesPlanifieesSauvegardeNocturne(): array
{
    expect(Artisan::call('schedule-monitor:sync'))->toBe(0);

    $evenement = collect(app(Schedule::class)->events())
        ->first(fn (Event $evenement): bool => str_contains((string) $evenement->command, 'backup:run'));
    assert($evenement instanceof Event);

    $tache = TachePlanifiee::findForTask($evenement);
    assert($tache instanceof TachePlanifiee);

    return [$evenement, $tache];
}

/**
 * Ce que le processus d'arrière-plan lance quand la sauvegarde se termine :
 * `schedule:finish`, avec l'identifiant du verrou et le code de sortie. En
 * production, la console annonce son démarrage (`CommandStarting`), et c'est
 * là que le moniteur s'accroche à la fin de la tâche ; la suite de tests
 * coupe cette annonce, il faut la rebrancher.
 */
function tachesPlanifieesFinirEnArrierePlan(Event $evenement, int $code): void
{
    $noyau = app(ConsoleKernel::class);
    assert($noyau instanceof Kernel);
    $noyau->rerouteSymfonyCommandEvents();
    $noyau->setArtisan(null);

    expect(Artisan::call('schedule:finish', ['id' => $evenement->mutexName(), 'code' => (string) $code]))->toBe(0);
}

/*
 * Une tâche en arrière-plan rend la main au planificateur dès son départ ; sa
 * fin arrive plus tard, par `schedule:finish` (#1929). Le moniteur doit
 * l'entendre : sans elle, la sauvegarde paraîtrait en retard chaque matin, ou
 * réussie quand elle échoue, et son verrou ne serait jamais rendu.
 */
it('note la fin d’une sauvegarde lancée en arrière-plan, et rend son verrou', function (): void {
    Carbon::setTestNow('2026-10-04 02:30:00');
    [$evenement, $tache] = tachesPlanifieesSauvegardeNocturne();

    expect($evenement->runInBackground)->toBeTrue()
        ->and($evenement->mutex->create($evenement))->toBeTrue();

    // Le planificateur note le départ, puis une fin aussitôt, sans code de
    // sortie : la tâche tourne ailleurs, et le moniteur ne la compte pas.
    event(new ScheduledTaskStarting($evenement));
    event(new ScheduledTaskFinished($evenement, 0.1));

    expect($tache->fresh()?->last_started_at?->toDateTimeString())->toBe('2026-10-04 02:30:00')
        ->and($tache->fresh()?->last_finished_at)->toBeNull();

    Carbon::setTestNow('2026-10-04 02:34:00');
    tachesPlanifieesFinirEnArrierePlan($evenement, 0);

    expect($tache->fresh()?->last_finished_at?->toDateTimeString())->toBe('2026-10-04 02:34:00')
        ->and($tache->fresh()?->etat())->toBe(TachePlanifiee::A_L_HEURE)
        ->and($evenement->mutex->exists($evenement))->toBeFalse();
});

it('note l’échec d’une sauvegarde lancée en arrière-plan', function (): void {
    Carbon::setTestNow('2026-10-04 02:30:00');
    [$evenement, $tache] = tachesPlanifieesSauvegardeNocturne();
    event(new ScheduledTaskStarting($evenement));

    Carbon::setTestNow('2026-10-04 02:31:00');
    tachesPlanifieesFinirEnArrierePlan($evenement, 1);

    expect($tache->fresh()?->last_failed_at?->toDateTimeString())->toBe('2026-10-04 02:31:00')
        ->and($tache->fresh()?->etat())->toBe(TachePlanifiee::ECHOUEE);
});
