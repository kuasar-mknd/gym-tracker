<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;

/*
 * Sur le serveur de production, chaque instruction modifiante coûte de
 * 0,35 à 1,7 s (audit du 2026-09-02). Le nombre d'écritures par opération
 * est donc une propriété du produit, figée ici : une écriture de plus se
 * décide, elle ne s'ajoute pas par mégarde.
 */

/**
 * @return array{0: User, 1: Workout, 2: WorkoutLine}
 */
function seanceOuvertePourLesEcritures(): array
{
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    return [$user, $workout, $line];
}

/**
 * Compte les instructions modifiantes émises pendant l'action, et les requêtes en tout.
 *
 * @return array{ecritures: int, requetes: int, sql: list<string>}
 */
function ecrituresPendant(callable $action): array
{
    /** @var list<string> $sql */
    $sql = [];
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    $action();

    DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

    $ecritures = array_values(array_filter($sql, fn (string $s): bool => preg_match('/^\s*(insert|update|delete)\b/i', $s) === 1));

    return ['ecritures' => count($ecritures), 'requetes' => count($sql), 'sql' => $ecritures];
}

beforeEach(function (): void {
    // Le chemin de production : les tâches partent en file, elles ne s'exécutent pas dans la requête.
    config(['app.env' => 'local']);
    Queue::fake();
});

it('crée une série en deux écritures : la série et le volume de la séance', function (): void {
    [$user, , $line] = seanceOuvertePourLesEcritures();

    $mesure = ecrituresPendant(fn () => actingAs($user, 'sanctum')
        ->postJson(route('api.v1.sets.store'), ['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 5, 'is_completed' => true])
        ->assertCreated());

    expect($mesure['ecritures'])->toBe(2, implode("\n", $mesure['sql']))
        // Lectures comprises : le contrôleur ne cherche plus la ligne que l'action
        // cherche déjà (#1676).
        ->and($mesure['requetes'])->toBe(14);
});

it('modifie une série en deux écritures', function (): void {
    [$user, , $line] = seanceOuvertePourLesEcritures();
    $set = Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 5]);

    $mesure = ecrituresPendant(fn () => actingAs($user, 'sanctum')
        ->patchJson(route('api.v1.sets.update', $set), ['weight' => 85])
        ->assertOk());

    expect($mesure['ecritures'])->toBe(2, implode("\n", $mesure['sql']));
});

it('supprime une série en deux écritures', function (): void {
    [$user, , $line] = seanceOuvertePourLesEcritures();
    $set = Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 5]);

    $mesure = ecrituresPendant(fn () => actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.sets.destroy', $set))
        ->assertNoContent());

    expect($mesure['ecritures'])->toBe(2, implode("\n", $mesure['sql']));
});

it('retire un exercice de la séance en deux écritures, sans journal d activité', function (): void {
    [$user, , $line] = seanceOuvertePourLesEcritures();
    Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 5]);

    $mesure = ecrituresPendant(fn () => actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.workout-lines.destroy', $line))
        ->assertNoContent());

    expect($mesure['ecritures'])->toBe(2, implode("\n", $mesure['sql']))
        ->and(implode("\n", $mesure['sql']))->not->toContain('activity_log');
});

it('retire un exercice qui portait des records en trois écritures : la ligne, ses records d un coup, le volume de la séance', function (): void {
    [$user, $workout, $line] = seanceOuvertePourLesEcritures();
    $sets = Set::factory()->count(3)->create(['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 5, 'is_completed' => true]);

    foreach (['max_weight', 'max_1rm', 'max_volume_set'] as $type) {
        PersonalRecord::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $line->exercise_id,
            'workout_id' => $workout->id,
            'set_id' => $sets->firstOrFail()->id,
            'type' => $type,
        ]);
    }

    $mesure = ecrituresPendant(fn () => actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.workout-lines.destroy', $line))
        ->assertNoContent());

    expect($mesure['ecritures'])->toBe(3, implode("\n", $mesure['sql']))
        ->and(PersonalRecord::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('liste les séances en un nombre de requêtes qui ne dépend pas du nombre de séances', function (): void {
    $compter = function (int $seances): int {
        $user = User::factory()->create();
        $exercises = Exercise::factory()->count(3)->create(['user_id' => $user->id]);

        foreach (range(1, $seances) as $i) {
            $workout = Workout::factory()->create(['user_id' => $user->id]);
            foreach ($exercises as $exercise) {
                WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
            }
        }

        \Illuminate\Support\Facades\Cache::flush();

        return ecrituresPendant(fn () => actingAs($user)->get(route('workouts.index'))->assertOk())['requetes'];
    };

    // Le compte des exercices distincts tient en une requête, quel que soit le
    // nombre d'exercices (décision mesurée, .ai/rules/actions.md, #2106) : à
    // exercices constants, le nombre de séances ne doit rien ajouter.
    expect($compter(8))->toBe($compter(2));
});

/**
 * Toutes les requêtes émises pendant l'action, dans l'ordre.
 *
 * @return list<string>
 */
function serieDeJoursRequetesPendant(callable $action): array
{
    /** @var list<string> $sql */
    $sql = [];
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    $action();

    DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

    return $sql;
}

/**
 * Les lectures de la série de jours parmi ces requêtes : les débuts des
 * séances faites du compte (`StreakService::serieDepuisLesFaits()`).
 *
 * @param  list<string>  $sql
 * @return list<string>
 */
function serieDeJoursLectures(array $sql): array
{
    return array_values(array_filter($sql, fn (string $s): bool => str_starts_with($s, 'select `started_at` from `workouts`')));
}

/**
 * Les écritures de la série de jours parmi ces requêtes.
 *
 * @param  list<string>  $sql
 * @return list<string>
 */
function serieDeJoursEcritures(array $sql): array
{
    return array_values(array_filter($sql, fn (string $s): bool => str_starts_with($s, 'update `users` set') && str_contains($s, '_streak')));
}

/*
 * La série de jours ne compte plus une séance à son démarrage, mais à la fin
 * d'une séance faite (#2171). Le démarrage perd l'écriture de la série et la
 * relecture du compte qui la suit (le journal d'activité relit le compte après
 * chaque écriture, par `fresh()`, même quand il n'en garde rien) : 8 requêtes
 * dont 2 écritures avant, 6 dont 1 après. La fin gagne une lecture des séances
 * faites du compte et, quand la série change, son écriture et cette relecture :
 * 9 requêtes dont 1 écriture avant, 12 dont 2 pour une séance faite, 10 dont 1
 * pour une séance vide. Une séance faite coûte en tout une requête de plus (17
 * puis 18) pour autant d'écritures (3) ; une séance vide, une requête et une
 * écriture de moins.
 */
it('démarre une séance sans lire ni écrire la série de jours', function (): void {
    $user = User::factory()->create();

    $sql = serieDeJoursRequetesPendant(fn () => actingAs($user)->post(route('workouts.store'))->assertRedirect());
    $ecritures = array_values(array_filter($sql, fn (string $s): bool => preg_match('/^\s*(insert|update|delete)\b/i', $s) === 1));

    expect(serieDeJoursLectures($sql))->toBe([])
        ->and(serieDeJoursEcritures($sql))->toBe([])
        ->and(count($ecritures))->toBe(1, implode("\n", $ecritures))
        ->and(count($sql))->toBe(6, implode("\n", $sql));
});

it('termine une séance faite en une lecture de ses séances faites et une écriture de la série', function (): void {
    [$user, $workout, $line] = seanceOuvertePourLesEcritures();
    Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 5, 'is_completed' => true]);

    $sql = serieDeJoursRequetesPendant(fn () => actingAs($user)
        ->patch(route('workouts.update', $workout), ['is_finished' => true])
        ->assertRedirect(route('dashboard')));
    $ecritures = array_values(array_filter($sql, fn (string $s): bool => preg_match('/^\s*(insert|update|delete)\b/i', $s) === 1));

    expect(serieDeJoursLectures($sql))->toHaveCount(1)
        ->and(serieDeJoursEcritures($sql))->toHaveCount(1)
        ->and(count($ecritures))->toBe(2, implode("\n", $ecritures))
        ->and(count($sql))->toBe(12, implode("\n", $sql));
});

it('termine une séance vide en une lecture de ses séances faites, sans écrire la série', function (): void {
    [$user, $workout] = seanceOuvertePourLesEcritures();

    $sql = serieDeJoursRequetesPendant(fn () => actingAs($user)
        ->patch(route('workouts.update', $workout), ['is_finished' => true])
        ->assertRedirect(route('dashboard')));
    $ecritures = array_values(array_filter($sql, fn (string $s): bool => preg_match('/^\s*(insert|update|delete)\b/i', $s) === 1));

    expect(serieDeJoursLectures($sql))->toHaveCount(1)
        ->and(serieDeJoursEcritures($sql))->toBe([])
        ->and(count($ecritures))->toBe(1, implode("\n", $ecritures))
        ->and(count($sql))->toBe(10, implode("\n", $sql));
});
