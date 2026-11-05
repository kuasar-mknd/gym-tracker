<?php

declare(strict_types=1);

use App\Filament\Resources\Workouts\Pages\EditWorkout;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\StreakService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

/*
 * #1983 : déplacer une séance existante vers l'avant gonflait la série de
 * jours et son record.
 *
 * Changer `started_at` repassait par l'avance d'un cran, faite pour une
 * séance NOUVELLE : elle ajoutait le jour d'arrivée sans retirer celui de
 * départ. Seul un déplacement vers l'arrière reconstruisait la série. Une
 * séance déplacée se reconstruit désormais depuis les séances, comme après
 * une suppression, et le contrôle nocturne compare la série stockée à cette
 * reconstruction.
 *
 * Seules les séances faites, terminées avec une série cochée, comptent pour
 * la série depuis #2171. Deux chemins déplacent une séance :
 *
 * - Ses réglages, dans l'application, tant qu'elle n'est pas terminée. En
 *   cours, elle ne compte pas, où qu'on la déplace : le déplacement ne change
 *   rien à la série, et ne la lit pas (`EcrituresParOperationTest`). Elle
 *   compte au jour où elle se trouve quand elle se termine, et c'est la fin
 *   qui reconstruit la série. Ces cas-là tiennent donc la fin d'une séance
 *   déplacée : chacun la termine après l'avoir déplacée. Elle ne passe pas
 *   maintenant (#2049) : l'horloge s'arrête après le dernier jour où une
 *   séance avance.
 * - Le panneau, pour une séance déjà faite : la politique refuse de modifier
 *   une séance terminée dans l'application. Seul son début change, sa fin
 *   reste la sienne. Les deux cas « le panneau ne change que le début d'une
 *   séance faite » tiennent le déplacement lui-même, la défense de #1983 : le
 *   début qui change sur une séance terminée reconstruit la série
 *   (`Workout::saved`).
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-07 18:00:00', 'Europe/Paris'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Une séance à 10 h ce jour-là, avec une série cochée : faite, terminée une
 * heure plus tard, ou laissée ouverte.
 */
function seanceDeplaceeLe(User $user, string $jour, bool $ouverte = false): Workout
{
    $debut = Carbon::parse("{$jour} 10:00:00");

    if (! $ouverte) {
        return Workout::factory()->faite()->create(['user_id' => $user->id, 'started_at' => $debut]);
    }

    $seance = Workout::factory()->create(['user_id' => $user->id, 'started_at' => $debut, 'ended_at' => null]);
    Set::factory()->create([
        'workout_line_id' => WorkoutLine::factory()->create(['workout_id' => $seance->id])->id,
        'weight' => 20,
        'reps' => 5,
    ]);

    return $seance;
}

/**
 * Termine la séance par la page : elle devient faite au jour où elle se trouve.
 */
function terminerLaSeanceDeplacee(User $user, Workout $workout): void
{
    test()->actingAs($user)
        ->patch(route('workouts.update', $workout), ['is_finished' => true])
        ->assertRedirect(route('dashboard'));
}

/**
 * La série stockée : dernière séance, série en cours, plus longue série.
 *
 * @return array{0: string|null, 1: int, 2: int}
 */
function serieDeplaceeStockee(User $user): array
{
    $user->refresh();

    return [$user->last_workout_at?->toDateTimeString(), $user->current_streak, $user->longest_streak];
}

/**
 * La série que reconstruit l'écrivain depuis les séances, sur une copie du
 * compte pour ne pas réécrire celle qu'on compare.
 *
 * @return array{0: string|null, 1: int, 2: int}
 */
function serieDeplaceeReconstruite(User $user): array
{
    $copie = User::query()->findOrFail($user->id);
    $serie = app(StreakService::class)->serieDepuisLesFaits($copie);

    return [$serie['derniere'], $serie['enCours'], $serie['plusLongue']];
}

/**
 * Déplace la séance par ses réglages, comme l'écran le fait.
 */
function deplacerLaSeance(User $user, Workout $workout, string $debut): void
{
    test()->actingAs($user)
        ->patch(route('workouts.update', $workout), ['started_at' => $debut])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
}

it('ne compte plus le jour que quitte la séance en cours avancée d’un jour', function (): void {
    $user = User::factory()->create();
    seanceDeplaceeLe($user, '2026-09-01');
    $resteeOuverte = seanceDeplaceeLe($user, '2026-09-02', ouverte: true);

    // En cours, elle ne compte pas.
    expect(serieDeplaceeStockee($user))->toBe(['2026-09-01 10:00:00', 1, 1]);

    deplacerLaSeance($user, $resteeOuverte, '2026-09-03T10:00');
    terminerLaSeanceDeplacee($user, $resteeOuverte);

    // Il reste le 01/09 et le 03/09 : aucun jour consécutif.
    expect(serieDeplaceeStockee($user))->toBe(['2026-09-03 10:00:00', 1, 1])
        ->and(serieDeplaceeReconstruite($user))->toBe(serieDeplaceeStockee($user));
});

it('ne gonfle pas la série quand la même séance avance plusieurs fois', function (): void {
    $user = User::factory()->create();
    $seule = seanceDeplaceeLe($user, '2026-10-03', ouverte: true);

    foreach (['2026-10-04', '2026-10-05', '2026-10-06'] as $jour) {
        deplacerLaSeance($user, $seule, "{$jour}T10:00");

        expect(serieDeplaceeStockee($user))->toBe([null, 0, 0])
            ->and(serieDeplaceeReconstruite($user))->toBe(serieDeplaceeStockee($user));
    }

    terminerLaSeanceDeplacee($user, $seule);

    expect(serieDeplaceeStockee($user))->toBe(['2026-10-06 10:00:00', 1, 1])
        ->and(serieDeplaceeReconstruite($user))->toBe(serieDeplaceeStockee($user));
});

it('reconstruit la série quand une séance avance depuis le début ou la fin d’une suite', function (string $jourDeplace, array $avant, array $attendue): void {
    $user = User::factory()->create();
    $seances = [];

    foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $jour) {
        $seances[$jour] = seanceDeplaceeLe($user, $jour, ouverte: $jour === $jourDeplace);
    }

    expect(serieDeplaceeStockee($user))->toBe($avant);

    deplacerLaSeance($user, $seances[$jourDeplace], '2026-10-04T10:00');
    terminerLaSeanceDeplacee($user, $seances[$jourDeplace]);

    expect(serieDeplaceeStockee($user))->toBe(['2026-10-04 10:00:00', ...$attendue])
        ->and(serieDeplaceeReconstruite($user))->toBe(serieDeplaceeStockee($user));
})->with([
    // Avant, le 2 et le 3, faits. Restent le 2, le 3 et le 4 : trois jours, pas quatre.
    'depuis le début' => ['2026-10-01', ['2026-10-03 10:00:00', 2, 2], [3, 3]],
    // Avant, le 1 et le 2. Restent le 1, le 2 et le 4 : la suite du 1 et du 2 reste la plus longue.
    'depuis la fin' => ['2026-10-03', ['2026-10-02 10:00:00', 2, 2], [1, 2]],
]);

it('reconstruit la série quand une séance recule', function (): void {
    $user = User::factory()->create();
    seanceDeplaceeLe($user, '2026-10-01');
    seanceDeplaceeLe($user, '2026-10-02');
    $derniere = seanceDeplaceeLe($user, '2026-10-03', ouverte: true);

    deplacerLaSeance($user, $derniere, '2026-09-30T10:00');
    terminerLaSeanceDeplacee($user, $derniere);

    // Restent le 30/09, le 1 et le 2 : trois jours, finis le 2.
    expect(serieDeplaceeStockee($user))->toBe(['2026-10-02 10:00:00', 3, 3])
        ->and(serieDeplaceeReconstruite($user))->toBe(serieDeplaceeStockee($user));
});

/**
 * Change par le panneau le début de cette séance, et rien d'autre : la fin
 * reste celle qu'elle avait.
 */
function deplacerLaSeanceParLePanneau(Workout $workout, string $debut): void
{
    $fin = $workout->refresh()->ended_at?->toDateTimeString();

    test()->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Workout')), 'admin');

    Livewire::test(EditWorkout::class, ['record' => $workout->getKey()])
        ->fillForm(['started_at' => $debut])
        ->call('save')
        ->assertHasNoFormErrors();

    $workout->refresh();

    expect($workout->started_at->toDateTimeString())->toBe($debut)
        ->and($workout->ended_at?->toDateTimeString())->toBe($fin);
}

/*
 * Le panneau déplace une séance déjà faite, sans toucher à sa fin. Sans
 * reconstruction au début qui change, la série et le record restaient ceux
 * d'avant le déplacement.
 */
it('reconstruit la série quand le panneau ne change que le début d’une séance faite, vers l’arrière', function (): void {
    $user = User::factory()->create();
    $deplacee = seanceDeplaceeLe($user, '2026-10-05');
    seanceDeplaceeLe($user, '2026-10-06');

    expect(serieDeplaceeStockee($user))->toBe(['2026-10-06 10:00:00', 2, 2]);

    deplacerLaSeanceParLePanneau($deplacee, '2026-10-03 10:00:00');

    // Restent le 3 et le 6 : la suite du 5 et du 6 est coupée.
    expect(serieDeplaceeStockee($user))->toBe(['2026-10-06 10:00:00', 1, 1])
        ->and(serieDeplaceeReconstruite($user))->toBe(serieDeplaceeStockee($user));
});

it('reconstruit la série quand le panneau ne change que le début d’une séance faite, vers l’avant au-delà de minuit', function (): void {
    $user = User::factory()->create();
    $deplacee = Workout::factory()->faite()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-10-01 23:00:00'),
        'ended_at' => Carbon::parse('2026-10-02 01:00:00'),
    ]);
    seanceDeplaceeLe($user, '2026-10-02');
    seanceDeplaceeLe($user, '2026-10-03');

    expect(serieDeplaceeStockee($user))->toBe(['2026-10-03 10:00:00', 3, 3]);

    deplacerLaSeanceParLePanneau($deplacee, '2026-10-02 00:30:00');

    // Le 1er n'a plus de séance : restent le 2 et le 3, deux jours et non trois.
    expect(serieDeplaceeStockee($user))->toBe(['2026-10-03 10:00:00', 2, 2])
        ->and(serieDeplaceeReconstruite($user))->toBe(serieDeplaceeStockee($user));
});

it('laisse la série telle quelle quand les réglages ne changent pas la date', function (): void {
    $user = User::factory()->create();
    seanceDeplaceeLe($user, '2026-10-02');
    $ouverte = seanceDeplaceeLe($user, '2026-10-03', ouverte: true);

    $this->actingAs($user)
        ->patch(route('workouts.update', $ouverte), ['name' => 'Jambes'])
        ->assertRedirect();

    // La séance ouverte ne compte pas encore : seul le 2 est fait.
    expect(serieDeplaceeStockee($user))->toBe(['2026-10-02 10:00:00', 1, 1]);
});

describe('le contrôle nocturne', function (): void {
    it('signale une série stockée qui diffère de la reconstruction, même sous une date juste', function (): void {
        $user = User::factory()->create();
        seanceDeplaceeLe($user, '2026-10-01');
        seanceDeplaceeLe($user, '2026-10-03');

        // Ce que laissait un déplacement avant le correctif : la date juste,
        // la série et le record gonflés.
        DB::table('users')->where('id', $user->id)->update(['current_streak' => 3, 'longest_streak' => 3]);

        $this->artisan('app:verify-data-coherence')
            ->assertExitCode(1)
            ->expectsOutputToContain('série de jours')
            ->expectsOutputToContain("utilisateur {$user->id} : stocké 3 jour(s) en cours et 3 au plus long, ses séances en donnent 1 et 1");
    });

    it('répare la série avec --repair, puis repasse au vert', function (): void {
        $user = User::factory()->create();
        seanceDeplaceeLe($user, '2026-10-01');
        seanceDeplaceeLe($user, '2026-10-02');
        seanceDeplaceeLe($user, '2026-10-03');

        DB::table('users')->where('id', $user->id)->update(['current_streak' => 1, 'longest_streak' => 5]);

        $this->artisan('app:verify-data-coherence', ['--repair' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('1 série(s) refaite(s)');

        expect(serieDeplaceeStockee($user))->toBe(['2026-10-03 10:00:00', 3, 3]);

        $this->artisan('app:verify-data-coherence')
            ->assertExitCode(0)
            ->expectsOutputToContain('Aucun écart');
    });

    it('ne compte pas deux fois un compte dont seule la date s’écarte', function (): void {
        $user = User::factory()->create();
        seanceDeplaceeLe($user, '2026-10-03');

        DB::table('users')->where('id', $user->id)->update(['last_workout_at' => '2026-10-02 10:00:00']);

        $this->artisan('app:verify-data-coherence')
            ->assertExitCode(1)
            ->expectsOutputToContain('date de dernière séance')
            ->expectsOutputToContain('OK série de jours');
    });
});
