<?php

declare(strict_types=1);

use App\Filament\Widgets\RecentUsersTable;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\UserActivityChart;
use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

beforeEach(function (): void {
    $this->actingAs(FilamentAdminPanel::admin(), 'admin');
});

/**
 * getStats() est protégé : on l'appelle depuis une closure liée à l'instance du
 * widget, ce qui exécute le vrai calcul sans passer par du mock.
 *
 * @return array<string, int|string>
 */
function filamentStatsOverviewValues(): array
{
    $widget = Livewire::test(StatsOverview::class)->instance();

    /** @var array<int, \Filament\Widgets\StatsOverviewWidget\Stat> $stats */
    $stats = (fn () => $this->getStats())->call($widget);

    $values = [];
    foreach ($stats as $stat) {
        $values[(string) $stat->getLabel()] = $stat->getValue();
    }

    return $values;
}

it('compte le total des utilisateurs et ceux inscrits dans les 7 derniers jours', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:00'));

    User::factory()->count(4)->create(['created_at' => Carbon::parse('2026-05-19 09:00:00')]); // dans la fenêtre
    User::factory()->create(['created_at' => Carbon::parse('2026-05-13 13:00:00')]);           // pile dans la fenêtre
    User::factory()->count(3)->create(['created_at' => Carbon::parse('2026-05-12 09:00:00')]); // hors fenêtre, de peu

    $values = filamentStatsOverviewValues();

    expect($values['Total Users'])->toBe(8)
        ->and($values['New Users (7d)'])->toBe(5);
});

it('compte les séances démarrées aujourd’hui, et elles seules', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:00'));

    $user = User::factory()->create();
    Workout::factory()->count(2)->create([
        'user_id' => $user->getKey(),
        'started_at' => Carbon::parse('2026-05-20 07:15:00'),
    ]);
    Workout::factory()->create([
        'user_id' => $user->getKey(),
        'started_at' => Carbon::parse('2026-05-20 23:59:59'),
    ]);
    Workout::factory()->create([
        'user_id' => $user->getKey(),
        'started_at' => Carbon::parse('2026-05-19 23:59:59'),
    ]);
    Workout::factory()->create([
        'user_id' => $user->getKey(),
        'started_at' => Carbon::parse('2026-05-21 00:00:01'),
    ]);

    expect(filamentStatsOverviewValues()['Workouts Today'])->toBe(3);
});

it('ne compte comme exercices système que ceux sans propriétaire', function (): void {
    $owner = User::factory()->create();
    Exercise::factory()->count(5)->create(['user_id' => null]);
    Exercise::factory()->count(2)->create(['user_id' => $owner->getKey()]);

    expect(filamentStatsOverviewValues()['System Exercises'])->toBe(5);
});

it('rend les quatre tuiles de statistiques avec leurs libellés', function (): void {
    User::factory()->create();

    Livewire::test(StatsOverview::class)
        ->assertSee('Total Users')
        ->assertSee('New Users (7d)')
        ->assertSee('Workouts Today')
        ->assertSee('System Exercises')
        ->assertSee('Total registered users');
});

it('ventile les inscriptions par mois sur les douze mois de l’année en cours', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:00'));

    User::factory()->count(2)->create(['created_at' => Carbon::parse('2026-01-10 09:00:00')]);
    User::factory()->count(3)->create(['created_at' => Carbon::parse('2026-03-05 09:00:00')]);
    User::factory()->create(['created_at' => Carbon::parse('2026-12-31 23:00:00')]);
    // Hors de l'année en cours : ne doit apparaître dans aucune barre.
    User::factory()->count(4)->create(['created_at' => Carbon::parse('2025-03-05 09:00:00')]);

    $widget = Livewire::test(UserActivityChart::class)->instance();
    $data = (fn () => $this->getData())->call($widget);

    expect($data['datasets'][0]['data'])->toBe([2, 0, 3, 0, 0, 0, 0, 0, 0, 0, 0, 1])
        ->and($data['datasets'][0]['label'])->toBe('New Users')
        ->and($data['labels'])->toBe(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']);
});

it('renvoie douze barres à zéro quand aucun utilisateur n’est inscrit', function (): void {
    $widget = Livewire::test(UserActivityChart::class)->instance();
    $data = (fn () => $this->getData())->call($widget);

    expect($data['datasets'][0]['data'])->toBe(array_fill(0, 12, 0));
});

it('affiche les inscrits du plus récent au plus ancien', function (): void {
    // Inscrits du plus récent (index 0) au plus ancien (index 11).
    $users = collect(range(0, 11))->map(fn (int $index): User => User::factory()->create([
        'created_at' => Carbon::now()->subDays($index),
    ]));

    // Seul l'ORDRE est asserté ici. Vérifier que les deux plus anciens sont
    // absents ne prouverait rien : la pagination par défaut de Filament s'arrête
    // à dix, donc l'assertion passerait même si le widget ne triait pas du tout.
    // inOrder échoue en revanche dès que le latest() du widget devient oldest().
    Livewire::test(RecentUsersTable::class)
        ->assertCanSeeTableRecords($users->take(10), inOrder: true);
});

it('affiche le nom, l’email et la dernière activité de chaque inscrit récent', function (): void {
    $user = User::factory()->create([
        'name' => 'Récente Recrue',
        'email' => 'recente@example.com',
        'last_workout_at' => Carbon::parse('2026-05-18 20:00:00'),
    ]);

    Livewire::test(RecentUsersTable::class)
        ->assertCanSeeTableRecords([$user])
        ->assertTableColumnStateSet('name', 'Récente Recrue', $user)
        ->assertTableColumnStateSet('email', 'recente@example.com', $user)
        ->assertTableColumnStateSet('last_workout_at', Carbon::parse('2026-05-18 20:00:00'), $user)
        ->assertSee('recente@example.com');
});
