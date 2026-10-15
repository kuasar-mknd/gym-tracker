<?php

declare(strict_types=1);

use App\Filament\Widgets\RecentUsersTable;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\UserActivityChart;
use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;
use Tests\Support\HorlogeQuiPasseMinuit;

beforeEach(function (): void {
    $this->actingAs(FilamentAdminPanel::admin(), 'admin');
});

/**
 * getStats() est protégé : on l'appelle depuis une closure liée à l'instance du
 * widget, ce qui exécute le vrai calcul sans passer par du mock.
 *
 * @return array<array-key, bool|float|\Illuminate\Contracts\Support\Htmlable|int|string|null>
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

    expect($values['Comptes'])->toBe(8)
        ->and($values['Nouveaux comptes (7 j)'])->toBe(5);
});

/*
 * Un compte ouvert par l'inscription attend la preuve de son adresse, et
 * s'efface s'il ne la reçoit jamais : ce n'est pas encore un compte.
 * « Comptes » et « Nouveaux comptes (7 j) » ne comptent que les comptes
 * ouverts, dont ceux qu'une preuve de l'adresse a ouverts et ceux qui n'ont
 * jamais attendu, vérifiés ou non ; « Comptes » dit à part combien attendent.
 */
it('ne compte pas les comptes en attente du lien dans les tuiles, et dit combien attendent', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:00'));
    $hier = Carbon::parse('2026-05-19 09:00:00');

    User::factory()->count(2)->create(['created_at' => $hier]);
    User::factory()->unverified()->create(['created_at' => Carbon::parse('2026-05-01 09:00:00')]);
    User::factory()->enAttente()->count(3)->create(['created_at' => $hier, 'en_attente_depuis' => $hier]);
    User::factory()->enAttente()->create(['created_at' => $hier, 'en_attente_depuis' => $hier])->markEmailAsVerified();

    $values = filamentStatsOverviewValues();

    expect($values['Comptes'])->toBe(4)
        ->and($values['Nouveaux comptes (7 j)'])->toBe(3);

    Livewire::test(StatsOverview::class)
        ->assertSee('Comptes ouverts, et 3 en attente du lien')
        ->assertSee('Ouverts, inscrits ces sept derniers jours');
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

    expect(filamentStatsOverviewValues()['Séances du jour'])->toBe(3);
});

/*
 * Le jour compté se tire d'une seule lecture de l'horloge : son début et sa fin
 * venaient de deux, et quand minuit passait entre elles, « Séances du jour »
 * comptait la veille avec le jour qui commençait (#2033).
 */
it('compte les séances d’un seul jour quand minuit passe pendant le calcul', function (): void {
    $user = User::factory()->create();
    Workout::factory()->count(2)->create([
        'user_id' => $user->getKey(),
        'started_at' => Carbon::parse('2026-05-20 07:15:00'),
    ]);
    Workout::factory()->create([
        'user_id' => $user->getKey(),
        'started_at' => Carbon::parse('2026-05-21 00:00:00'),
    ]);
    HorlogeQuiPasseMinuit::apres(1, '2026-05-20 23:59:59.999999', '2026-05-21 00:00:00.000001');

    $seancesDuJour = new ReflectionMethod(StatsOverview::class, 'getWorkoutsTodayCount')->invoke(new StatsOverview());

    // Les deux du 20 mai, ou celle du 21, jamais les trois réunies.
    expect($seancesDuJour)->toBeIn([2, 1]);
});

it('ne compte comme exercices système que ceux sans propriétaire', function (): void {
    $owner = User::factory()->create();
    Exercise::factory()->count(5)->create(['user_id' => null]);
    Exercise::factory()->count(2)->create(['user_id' => $owner->getKey()]);

    expect(filamentStatsOverviewValues()['Exercices communs'])->toBe(5);
});

it('rend les quatre tuiles de statistiques avec leurs libellés', function (): void {
    User::factory()->create();

    Livewire::test(StatsOverview::class)
        ->assertSee('Comptes')
        ->assertSee('Nouveaux comptes (7 j)')
        ->assertSee('Séances du jour')
        ->assertSee('Exercices communs')
        ->assertSee('Comptes ouverts, et 0 en attente du lien')
        ->assertDontSee('Comptes inscrits')
        ->assertDontSee('Total Users')
        ->assertDontSee('Workouts Today');
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
        ->and($data['datasets'][0]['label'])->toBe('Nouveaux comptes')
        ->and($data['labels'])->toBe(['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.']);
});

/*
 * Le graphique « Inscriptions » ne range que les comptes ouverts, au mois de
 * leur inscription : un compte en attente du lien n'y entre qu'une fois
 * ouvert, et compte alors au mois où il s'est inscrit. Un compte qui n'a
 * jamais attendu y compte, vérifié ou non : ouvert ne veut pas dire vérifié.
 */
it('ne range dans les inscriptions que les comptes ouverts', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:00'));
    $finAvril = Carbon::parse('2026-04-30 23:00:00');
    $mai = Carbon::parse('2026-05-18 09:00:00');

    User::factory()->count(2)->create(['created_at' => Carbon::parse('2026-03-05 09:00:00')]);
    User::factory()->unverified()->create(['created_at' => Carbon::parse('2026-03-20 09:00:00')]);
    User::factory()->enAttente()->count(4)->create(['created_at' => $mai, 'en_attente_depuis' => $mai]);
    User::factory()->enAttente()->create(['created_at' => $finAvril, 'en_attente_depuis' => $finAvril])->markEmailAsVerified();

    $donnees = new ReflectionMethod(UserActivityChart::class, 'getData')->invoke(new UserActivityChart());

    expect(data_get($donnees, 'datasets.0.data'))->toBe([0, 0, 3, 1, 0, 0, 0, 0, 0, 0, 0, 0]);
});

/*
 * L'année des barres se tire d'une seule lecture de l'horloge : son début et sa
 * fin venaient de deux, et quand l'année changeait entre elles, le graphique
 * couvrait deux ans et rangeait les inscrits de janvier prochain avec ceux de
 * l'année qui s'achevait (#2033).
 */
it('ventile les inscriptions d’une seule année quand l’année change pendant le calcul', function (): void {
    User::factory()->create(['created_at' => Carbon::parse('2026-03-05 09:00:00')]);
    User::factory()->create(['created_at' => Carbon::parse('2027-01-01 00:00:00')]);
    HorlogeQuiPasseMinuit::apres(1, '2026-12-31 23:59:59.999999', '2027-01-01 00:00:00.000001');

    $donnees = new ReflectionMethod(UserActivityChart::class, 'getData')->invoke(new UserActivityChart());

    // Mars de l'année qui s'achève, ou janvier de celle qui commence, chacun
    // seul. L'administrateur du panneau n'est pas un compte de l'application.
    expect(data_get($donnees, 'datasets.0.data'))->toBeIn([
        [0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
    ]);
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

/*
 * L'encart « Derniers inscrits parmi les comptes ouverts » ne montre que des
 * comptes ouverts, par date d'inscription : un compte en attente du lien, même
 * plus récent, ne s'y glisse pas, et l'encart dit où il est (la liste des
 * utilisateurs le montre, avec sa colonne « En attente du lien »).
 */
it('n’affiche dans l’encart des derniers inscrits que des comptes ouverts', function (): void {
    $ouvert = User::factory()->create(['created_at' => Carbon::now()->subDays(2)]);
    $jamaisVerifie = User::factory()->unverified()->create(['created_at' => Carbon::now()->subDays(3)]);
    $enAttente = User::factory()->enAttente()->create();

    Livewire::test(RecentUsersTable::class)
        ->assertCanSeeTableRecords([$ouvert, $jamaisVerifie], inOrder: true)
        ->assertCanNotSeeTableRecords([$enAttente])
        ->assertDontSee($enAttente->email)
        ->assertSee('Derniers inscrits parmi les comptes ouverts')
        ->assertSee("Classés par date d'inscription ; les comptes en attente du lien sont dans la liste des utilisateurs.");
});

/*
 * L'encart range par date d'inscription, et rien ne garde le moment où un
 * compte s'ouvre (l'ouverture efface `en_attente_depuis`) : un compte inscrit
 * il y a trois jours et ouvert à l'instant reste à sa date d'inscription,
 * derrière dix comptes ouverts inscrits depuis. Son titre ne promet donc pas
 * les derniers comptes ouverts. Les comptes affichés se lisent par
 * `getTableRecords()` : `assertCanSeeTableRecords()` cherche la clef du compte
 * comme une sous-chaîne, et celle du compte 1 est dans celle du compte 10.
 */
it('range l’encart par date d’inscription, et son titre le dit', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));

    $inscritIlYATroisJours = User::factory()->enAttente()->create([
        'created_at' => Carbon::parse('2026-10-12 09:00:00'),
        'en_attente_depuis' => Carbon::parse('2026-10-12 09:00:00'),
    ]);
    $inscritsDepuis = collect(range(1, 10))->map(static fn (int $heures): User => User::factory()->create([
        'created_at' => Carbon::parse('2026-10-15 12:00:00')->subHours($heures),
    ]));

    Carbon::setTestNow(Carbon::parse('2026-10-15 12:05:00'));
    $inscritIlYATroisJours->markEmailAsVerified();

    $encart = Livewire::test(RecentUsersTable::class)->instance();
    assert($encart instanceof RecentUsersTable);
    $affiches = $encart->getTableRecords();
    assert($affiches instanceof Paginator);

    expect($inscritIlYATroisJours->refresh()->estEnAttente())->toBeFalse()
        ->and($encart->getTable()->getHeading())->toBe('Derniers inscrits parmi les comptes ouverts')
        ->and(collect($affiches->items())->pluck('id')->all())->toBe($inscritsDepuis->pluck('id')->all());
});
