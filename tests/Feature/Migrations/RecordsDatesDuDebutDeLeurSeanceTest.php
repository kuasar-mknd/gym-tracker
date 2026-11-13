<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * `2026_11_13_100000_dater_les_records_du_debut_de_leur_seance.php` date
 * chaque record du début de la séance dont la série le tient, en une seule
 * instruction. Elle laisse tels quels un record dont la série a disparu, un
 * record dont le compte n'existe plus (réécrire `achieved_at` réécrit l'index
 * `(user_id, achieved_at)`, que 8.4 revérifie contre la clé du compte, 1452) et
 * une séance dont le début sort de la plage d'un `TIMESTAMP` (le mode strict
 * arrêterait l'instruction, donc le démarrage), et un second passage n'écrit
 * rien.
 */

/**
 * Joue `up()` de la migration, comme `migrate`.
 */
function recordsDatesMigrer(): void
{
    $migration = require database_path('migrations/2026_11_13_100000_dater_les_records_du_debut_de_leur_seance.php');
    assert($migration instanceof Migration && method_exists($migration, 'up'));

    $migration->up();
}

/**
 * Un record d'un type donné, écrit en base à la date voulue.
 */
function recordsDatesPlanter(int $idCompte, int $idExercice, ?int $idSerie, ?int $idSeance, string $type, string $date): int
{
    return DB::table('personal_records')->insertGetId([
        'user_id' => $idCompte,
        'exercise_id' => $idExercice,
        'type' => $type,
        'value' => 100,
        'workout_id' => $idSeance,
        'set_id' => $idSerie,
        'achieved_at' => $date,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function recordsDatesLire(int $idRecord): string
{
    $date = DB::table('personal_records')->where('id', $idRecord)->value('achieved_at');

    return is_string($date) ? $date : '';
}

/**
 * Une séance au début voulu, une ligne et une série faite, sans événement :
 * aucun record n'est établi, la scène les plante à sa façon.
 *
 * @return array{0: User, 1: Exercise, 2: Workout, 3: Set}
 */
function recordsDatesSeance(string $debut, ?User $compte = null): array
{
    $compte ??= User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $compte->id]);
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => Carbon::parse($debut)]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
    $serie = Set::withoutEvents(fn (): Set => Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 100, 'reps' => 5, 'is_completed' => true]));

    return [$compte, $exercice, $seance, $serie];
}

it('date chaque record du début de la séance de sa série, qu\'il porte l\'heure du rejeu ou celle de la création', function (): void {
    [$compte, $exercice, $seance, $serie] = recordsDatesSeance('2026-11-10 09:30:00');
    [, $exerciceDeux, $seanceDeux, $serieDeux] = recordsDatesSeance('2026-02-03 18:00:00', $compte);

    $rejeu = recordsDatesPlanter($compte->id, $exercice->id, $serie->id, $seance->id, 'max_weight', '2026-11-13 21:40:00');
    $creation = recordsDatesPlanter($compte->id, $exercice->id, $serie->id, $seance->id, 'max_1rm', '2026-11-10 09:41:00');
    $juste = recordsDatesPlanter($compte->id, $exercice->id, $serie->id, $seance->id, 'max_volume_set', '2026-11-10 09:30:00');
    $autre = recordsDatesPlanter($compte->id, $exerciceDeux->id, $serieDeux->id, $seanceDeux->id, 'max_weight', '2026-11-13 21:40:00');

    $avant = DB::table('personal_records')->where('id', $juste)->value('updated_at');

    recordsDatesMigrer();

    expect(recordsDatesLire($rejeu))->toBe('2026-11-10 09:30:00')
        ->and(recordsDatesLire($creation))->toBe('2026-11-10 09:30:00')
        ->and(recordsDatesLire($juste))->toBe('2026-11-10 09:30:00')
        ->and(recordsDatesLire($autre))->toBe('2026-02-03 18:00:00')
        ->and(DB::table('personal_records')->where('id', $juste)->value('updated_at'))->toBe($avant);
});

it('se rejoue sans rien écrire, et le dit une seule fois au journal', function (): void {
    [$compte, $exercice, $seance, $serie] = recordsDatesSeance('2026-11-10 09:30:00');
    recordsDatesPlanter($compte->id, $exercice->id, $serie->id, $seance->id, 'max_weight', '2026-11-13 21:40:00');

    $journal = Log::spy();
    recordsDatesMigrer();
    recordsDatesMigrer();

    $journal->shouldHaveReceived('info')->once()->with('Records datés du début de leur séance', ['records' => 1]);
});

it('laisse le record dont la série a disparu à sa date', function (): void {
    [$compte, $exercice, $seance, $serie] = recordsDatesSeance('2026-11-10 09:30:00');
    $orphelin = recordsDatesPlanter($compte->id, $exercice->id, $serie->id, $seance->id, 'max_weight', '2026-11-13 21:40:00');

    DB::table('sets')->where('id', $serie->id)->delete();
    expect(DB::table('personal_records')->where('id', $orphelin)->value('set_id'))->toBeNull();

    recordsDatesMigrer();

    expect(recordsDatesLire($orphelin))->toBe('2026-11-13 21:40:00');
});

it('laisse le record d\'un compte qui n\'existe plus, sans arrêter la migration', function (): void {
    [$compte, $exercice, $seance, $serie] = recordsDatesSeance('2026-11-10 09:30:00');
    $vivant = recordsDatesPlanter($compte->id, $exercice->id, $serie->id, $seance->id, 'max_weight', '2026-11-13 21:40:00');
    $sansCompte = 0;

    Schema::withoutForeignKeyConstraints(function () use ($exercice, $seance, $serie, &$sansCompte): void {
        $sansCompte = recordsDatesPlanter(987654321, $exercice->id, $serie->id, $seance->id, 'max_1rm', '2026-11-13 21:40:00');
    });

    recordsDatesMigrer();

    expect(recordsDatesLire($vivant))->toBe('2026-11-10 09:30:00')
        ->and(recordsDatesLire($sansCompte))->toBe('2026-11-13 21:40:00');
});

it('laisse le record dont la séance commence hors de la plage d\'un TIMESTAMP, et date les autres', function (): void {
    [$compte, $exercice, $seance, $serie] = recordsDatesSeance('2026-11-10 09:30:00');
    [, $exerciceDeux, $seanceDeux, $serieDeux] = recordsDatesSeance('2026-11-10 09:30:00', $compte);
    [, $exerciceTrois, $seanceTrois, $serieTrois] = recordsDatesSeance('2026-11-10 09:30:00', $compte);

    DB::table('workouts')->where('id', $seanceDeux->id)->update(['started_at' => '1969-06-01 12:00:00']);
    DB::table('workouts')->where('id', $seanceTrois->id)->update(['started_at' => '2039-06-01 12:00:00']);

    $dedans = recordsDatesPlanter($compte->id, $exercice->id, $serie->id, $seance->id, 'max_weight', '2026-11-13 21:40:00');
    $avant = recordsDatesPlanter($compte->id, $exerciceDeux->id, $serieDeux->id, $seanceDeux->id, 'max_weight', '2026-11-13 21:40:00');
    $apres = recordsDatesPlanter($compte->id, $exerciceTrois->id, $serieTrois->id, $seanceTrois->id, 'max_weight', '2026-11-13 21:40:00');

    recordsDatesMigrer();

    expect(recordsDatesLire($dedans))->toBe('2026-11-10 09:30:00')
        ->and(recordsDatesLire($avant))->toBe('2026-11-13 21:40:00')
        ->and(recordsDatesLire($apres))->toBe('2026-11-13 21:40:00');
});

it('date aussi le record dont la ligne a perdu la copie du début : la séance fait foi', function (): void {
    [$compte, $exercice, $seance, $serie] = recordsDatesSeance('2026-11-10 09:30:00');
    $record = recordsDatesPlanter($compte->id, $exercice->id, $serie->id, $seance->id, 'max_weight', '2026-11-13 21:40:00');

    DB::table('workout_lines')->where('id', $serie->workout_line_id)->update(['workout_started_at' => null]);

    recordsDatesMigrer();

    expect(recordsDatesLire($record))->toBe('2026-11-10 09:30:00');
});
