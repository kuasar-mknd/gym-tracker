<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Models\Goal;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Une séance terminée dont la seule série cochée est un échauffement n'est plus
 * faite (#2333). `2026_11_17_090000_recalculer_la_serie_de_jours_sans_les_echauffements_seuls.php`
 * refait, une fois, la série stockée des comptes qui en portent une, par
 * l'écrivain, et laisse les autres comptes tels quels.
 */

/**
 * Joue `up()` de la migration, comme `migrate`.
 */
function echauffementSeulMigrer(): void
{
    $migration = require database_path('migrations/2026_11_17_090000_recalculer_la_serie_de_jours_sans_les_echauffements_seuls.php');
    assert($migration instanceof Migration && method_exists($migration, 'up'));

    $migration->up();
}

/**
 * Une séance terminée un jour donné, avec les séries cochées dites, sans
 * écouteurs : rien ne recalcule la série avant la migration.
 *
 * @param  list<bool>  $echauffements  Une série cochée par valeur ; `true` : un échauffement.
 */
function echauffementSeulSeance(User $compte, string $debut, array $echauffements): void
{
    Workout::withoutEvents(function () use ($compte, $debut, $echauffements): void {
        $seance = Workout::factory()->create([
            'user_id' => $compte->id,
            'started_at' => Carbon::parse($debut),
            'ended_at' => Carbon::parse($debut)->addHour(),
        ]);
        $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id]);

        foreach ($echauffements as $echauffement) {
            Set::factory()->create(['workout_line_id' => $ligne->id, 'is_completed' => true, 'is_warmup' => $echauffement]);
        }
    });
}

/**
 * Pose sur le compte la série qu'aurait écrite l'écrivain d'avant.
 */
function echauffementSeulPoser(User $compte, ?string $derniere, int $enCours, int $plusLongue): void
{
    DB::table('users')->where('id', $compte->id)->update([
        'last_workout_at' => $derniere,
        'current_streak' => $enCours,
        'longest_streak' => $plusLongue,
    ]);
}

/**
 * @return array{0: string|null, 1: int, 2: int}
 */
function echauffementSeulStockee(User $compte): array
{
    $enBase = User::query()->findOrFail($compte->id);

    return [$enBase->last_workout_at?->toDateTimeString(), $enBase->current_streak, $enBase->longest_streak];
}

it('refait la série d\'un compte dont une séance terminée n\'a qu\'un échauffement coché', function (): void {
    $compte = User::factory()->create();
    echauffementSeulSeance($compte, '2026-10-05 10:00:00', [false]);
    echauffementSeulSeance($compte, '2026-10-06 10:00:00', [true]);
    echauffementSeulSeance($compte, '2026-10-07 10:00:00', [true, false]);
    echauffementSeulPoser($compte, '2026-10-07 10:00:00', 3, 3);

    echauffementSeulMigrer();

    expect(echauffementSeulStockee($compte))->toBe(['2026-10-07 10:00:00', 1, 1]);
});

it('ne touche ni un compte dont les séances ont une série de travail, ni un compte sans séance', function (): void {
    $fait = User::factory()->create();
    echauffementSeulSeance($fait, '2026-10-06 10:00:00', [true, false]);
    echauffementSeulPoser($fait, '2026-10-06 10:00:00', 5, 9);

    $vide = User::factory()->create();
    echauffementSeulPoser($vide, null, 4, 4);

    echauffementSeulMigrer();

    expect(echauffementSeulStockee($fait))->toBe(['2026-10-06 10:00:00', 5, 9])
        ->and(echauffementSeulStockee($vide))->toBe([null, 4, 4]);
});

it('se rejoue sans rien changer', function (): void {
    $compte = User::factory()->create();
    echauffementSeulSeance($compte, '2026-10-06 10:00:00', [true]);
    echauffementSeulPoser($compte, '2026-10-06 10:00:00', 2, 2);

    echauffementSeulMigrer();
    $apres = echauffementSeulStockee($compte);
    echauffementSeulMigrer();

    expect($apres)->toBe([null, 0, 0])
        ->and(echauffementSeulStockee($compte))->toBe($apres);
});

it('recalcule les objectifs de fréquence des comptes touchés, et laisse ceux des autres', function (): void {
    $objectifDe = fn (User $compte): Goal => Goal::factory()->create([
        'user_id' => $compte->id,
        'type' => GoalType::Frequency,
        'exercise_id' => null,
        'start_value' => 0,
        'current_value' => 3,
        'target_value' => 10,
        'completed_at' => null,
    ]);

    $touche = User::factory()->create();
    echauffementSeulSeance($touche, '2026-10-05 10:00:00', [false]);
    echauffementSeulSeance($touche, '2026-10-06 10:00:00', [true]);
    echauffementSeulSeance($touche, '2026-10-07 10:00:00', [true]);
    $objectifTouche = $objectifDe($touche);

    $intact = User::factory()->create();
    echauffementSeulSeance($intact, '2026-10-05 10:00:00', [false]);
    $objectifIntact = $objectifDe($intact);

    // L'avancement d'avant, que le compte tenait en base.
    DB::table('goals')->update(['current_value' => 3]);

    echauffementSeulMigrer();

    expect($objectifTouche->refresh()->current_value)->toBe(1.0)
        ->and($objectifIntact->refresh()->current_value)->toBe(3.0);
});
