<?php

declare(strict_types=1);

use App\Models\Exercise;
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

it('crée une série en trois écritures : la série, le volume de la séance, le total de l utilisateur', function (): void {
    [$user, , $line] = seanceOuvertePourLesEcritures();

    $mesure = ecrituresPendant(fn () => actingAs($user, 'sanctum')
        ->postJson(route('api.v1.sets.store'), ['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 5, 'is_completed' => true])
        ->assertCreated());

    expect($mesure['ecritures'])->toBe(3, implode("\n", $mesure['sql']))
        // Lectures comprises : le contrôleur ne cherche plus la ligne que l'action
        // cherche déjà (#1676).
        ->and($mesure['requetes'])->toBe(15);
});

it('modifie une série en trois écritures', function (): void {
    [$user, , $line] = seanceOuvertePourLesEcritures();
    $set = Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 5]);

    $mesure = ecrituresPendant(fn () => actingAs($user, 'sanctum')
        ->patchJson(route('api.v1.sets.update', $set), ['weight' => 85])
        ->assertOk());

    expect($mesure['ecritures'])->toBe(3, implode("\n", $mesure['sql']));
});

it('supprime une série en trois écritures', function (): void {
    [$user, , $line] = seanceOuvertePourLesEcritures();
    $set = Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 5]);

    $mesure = ecrituresPendant(fn () => actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.sets.destroy', $set))
        ->assertNoContent());

    expect($mesure['ecritures'])->toBe(3, implode("\n", $mesure['sql']));
});

it('retire un exercice de la séance en trois écritures, sans journal d activité', function (): void {
    [$user, , $line] = seanceOuvertePourLesEcritures();
    Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 5]);

    $mesure = ecrituresPendant(fn () => actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.workout-lines.destroy', $line))
        ->assertNoContent());

    expect($mesure['ecritures'])->toBe(3, implode("\n", $mesure['sql']))
        ->and(implode("\n", $mesure['sql']))->not->toContain('activity_log');
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

    // Le compte des exercices distincts coûte une lecture par exercice (décision
    // mesurée, .ai/rules/actions.md) : à exercices constants, le nombre de
    // séances ne doit rien ajouter.
    expect($compter(8))->toBe($compter(2));
});
