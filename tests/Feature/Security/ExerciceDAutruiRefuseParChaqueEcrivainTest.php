<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Goal;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

/*
 * `exercise_id` n'a pas de contrainte SQL au propriétaire : les exercices
 * communs ont un `user_id` nul, et les lignes, objectifs et gabarits d'un
 * compte peuvent viser l'un d'eux. Seule la validation de chaque écriture
 * garde donc l'exercice privé d'autrui. Ce contrat joue chaque écrivain
 * avec un tel exercice : refusé sur son champ, sans rien écrire. Un nouvel
 * écrivain de `exercise_id` s'ajoute à `ecrivainDeLExercice()`.
 */

/**
 * @return TestResponse<Response>
 */
function ecrivainDeLExercice(string $ecrivain, User $compte, Exercise $exercice): TestResponse
{
    $gabarit = ['name' => 'Gabarit', 'exercises' => [['id' => $exercice->id, 'sets' => [['reps' => 5, 'weight' => 50]]]]];
    $objectif = ['title' => 'Développé couché', 'type' => 'weight', 'target_value' => 100, 'exercise_id' => $exercice->id];

    return match ($ecrivain) {
        'ligne de séance' => postJson(route('api.v1.workout-lines.store'), [
            'workout_id' => Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => null])->id,
            'exercise_id' => $exercice->id,
        ]),
        'objectif créé' => postJson(route('goals.store'), $objectif),
        'objectif modifié' => putJson(route('goals.update', Goal::factory()->create(['user_id' => $compte->id, 'type' => 'frequency'])), $objectif),
        'gabarit créé' => postJson(route('templates.store'), $gabarit),
        default => putJson(route('templates.update', WorkoutTemplate::factory()->create(['user_id' => $compte->id])), $gabarit),
    };
}

/**
 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
 */
function ecrivainsDeLExercice(): array
{
    return [
        'ligne de séance' => ['ligne de séance', 'workout_lines', 'exercise_id', 'sanctum', 'exercise_id'],
        'objectif créé' => ['objectif créé', 'goals', 'exercise_id', 'web', 'exercise_id'],
        'objectif modifié' => ['objectif modifié', 'goals', 'exercise_id', 'web', 'exercise_id'],
        'gabarit créé' => ['gabarit créé', 'workout_template_lines', 'exercises.0.id', 'web', 'exercise_id'],
        'gabarit modifié' => ['gabarit modifié', 'workout_template_lines', 'exercises.0.id', 'web', 'exercise_id'],
    ];
}

it('refuse l’exercice privé d’un autre compte, sans rien écrire', function (
    string $ecrivain,
    string $table,
    string $champ,
    string $garde,
    string $colonne,
): void {
    $compte = User::factory()->create();
    $exerciceDAutrui = Exercise::factory()->create(['user_id' => User::factory()->create()->id]);
    actingAs($compte, $garde);

    ecrivainDeLExercice($ecrivain, $compte, $exerciceDAutrui)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$champ]);

    expect(DB::table($table)->where($colonne, $exerciceDAutrui->id)->count())->toBe(0);
})->with(ecrivainsDeLExercice());

it('accepte l’exercice commun et celui du compte', function (
    string $ecrivain,
    string $table,
    string $champ,
    string $garde,
    string $colonne,
): void {
    $compte = User::factory()->create();
    actingAs($compte, $garde);

    foreach ([Exercise::factory()->create(['user_id' => null]), Exercise::factory()->create(['user_id' => $compte->id])] as $exercice) {
        $reponse = ecrivainDeLExercice($ecrivain, $compte, $exercice);

        expect($reponse->isSuccessful() || $reponse->isRedirect())->toBeTrue();
        expect(DB::table($table)->where($colonne, $exercice->id)->count())->toBeGreaterThan(0);
    }
})->with(ecrivainsDeLExercice());
