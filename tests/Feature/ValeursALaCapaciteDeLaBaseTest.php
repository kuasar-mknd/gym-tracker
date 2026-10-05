<?php

declare(strict_types=1);

use App\Models\Habit;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

use function Pest\Laravel\actingAs;

/*
 * #1986 : la description d'une habitude et les valeurs d'une série acceptaient
 * des valeurs que la base refuse. La requête finissait en 500, sans message
 * sur le champ, et la file hors ligne prenait l'écriture pour une erreur
 * passagère à réessayer. Elles rendent désormais une erreur de validation sur
 * le champ, et une valeur à la limite s'enregistre entière.
 */

/**
 * Une valeur lue en base, comme nombre.
 */
function valeursCapaciteNombre(mixed $valeur): float
{
    return is_numeric($valeur) ? (float) $valeur : NAN;
}

/**
 * Une ligne de séance en cours du compte, prête à recevoir des séries.
 */
function valeursCapaciteLigneDe(User $compte): WorkoutLine
{
    $seance = Workout::factory()->create(['user_id' => $compte->id]);

    return WorkoutLine::factory()->create(['workout_id' => $seance->id]);
}

it('refuse une description d’habitude plus longue que sa colonne, à la création comme à la modification', function (): void {
    $compte = User::factory()->create();
    $habitude = Habit::factory()->create(['user_id' => $compte->id, 'description' => 'avant']);
    $tropLongue = str_repeat('é', 256);

    actingAs($compte)
        ->post(route('habits.store'), ['name' => 'Lecture', 'goal_times_per_week' => 3, 'description' => $tropLongue])
        ->assertRedirect()
        ->assertInvalid(['description']);

    actingAs($compte)
        ->put(route('habits.update', $habitude), ['description' => $tropLongue])
        ->assertRedirect()
        ->assertInvalid(['description']);

    expect(Habit::query()->count())->toBe(1)
        ->and($habitude->fresh()?->description)->toBe('avant');
});

it('garde entière une description d’habitude à la limite de sa colonne', function (): void {
    $compte = User::factory()->create();
    $aLaLimite = str_repeat('é', 255);

    actingAs($compte)
        ->post(route('habits.store'), ['name' => 'Lecture', 'goal_times_per_week' => 3, 'description' => $aLaLimite])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $habitude = Habit::query()->sole();

    expect($habitude->description)->toBe($aLaLimite);

    actingAs($compte)
        ->put(route('habits.update', $habitude), ['description' => str_repeat('è', 255)])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($habitude->fresh()?->description)->toBe(str_repeat('è', 255));
});

/*
 * Chaque champ chiffré d'une série : une valeur au-delà de la capacité de sa
 * colonne, une juste au-delà du plafond métier, et le plafond lui-même.
 */
dataset('valeurs capacite series', [
    'poids hors capacité' => ['weight', 1_000_000, Set::POIDS_MAX_KG],
    'poids au-delà du plafond des records' => ['weight', Set::POIDS_MAX_KG + 0.01, Set::POIDS_MAX_KG],
    'distance hors capacité' => ['distance_km', 100_000, Set::DISTANCE_MAX_KM],
    'distance au-delà du plafond' => ['distance_km', Set::DISTANCE_MAX_KM + 0.001, Set::DISTANCE_MAX_KM],
    'répétitions hors capacité' => ['reps', 2_147_483_648, Set::REPETITIONS_MAX],
    'répétitions au-delà du plafond' => ['reps', Set::REPETITIONS_MAX + 1, Set::REPETITIONS_MAX],
    'durée hors capacité' => ['duration_seconds', 2_147_483_648, Set::DUREE_MAX_SECONDES],
    'durée au-delà du plafond' => ['duration_seconds', Set::DUREE_MAX_SECONDES + 1, Set::DUREE_MAX_SECONDES],
]);

it('refuse en 422 une valeur de série hors bornes à la création, et garde la valeur à la limite', function (string $champ, int|float $horsBornes, int $aLaLimite): void {
    $compte = User::factory()->create();
    $ligne = valeursCapaciteLigneDe($compte);

    actingAs($compte, 'sanctum')
        ->postJson(route('api.v1.sets.store'), ['workout_line_id' => $ligne->id, $champ => $horsBornes])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$champ]);

    expect(Set::query()->count())->toBe(0);

    actingAs($compte, 'sanctum')
        ->postJson(route('api.v1.sets.store'), ['workout_line_id' => $ligne->id, $champ => $aLaLimite])
        ->assertCreated();

    expect(valeursCapaciteNombre(Set::query()->sole()->getAttribute($champ)))->toBe((float) $aLaLimite);
})->with('valeurs capacite series');

it('refuse en 422 une valeur de série hors bornes à la modification, et garde la valeur à la limite', function (string $champ, int|float $horsBornes, int $aLaLimite): void {
    $compte = User::factory()->create();
    $serie = Set::factory()->create(['workout_line_id' => valeursCapaciteLigneDe($compte)->id, $champ => 1]);

    actingAs($compte, 'sanctum')
        ->patchJson(route('api.v1.sets.update', $serie), [$champ => $horsBornes])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$champ]);

    expect(valeursCapaciteNombre($serie->fresh()?->getAttribute($champ)))->toBe(1.0);

    actingAs($compte, 'sanctum')
        ->patchJson(route('api.v1.sets.update', $serie), [$champ => $aLaLimite])
        ->assertOk();

    expect(valeursCapaciteNombre($serie->fresh()?->getAttribute($champ)))->toBe((float) $aLaLimite);
})->with('valeurs capacite series');

/*
 * La page de séance n'affiche les bornes d'une série nulle part : quand elle
 * rétablit une valeur refusée, elle cite le message du serveur pour ce champ.
 * Il nomme donc la borne, et le champ tel que l'utilisateur le lit.
 */
dataset('valeurs capacite messages', [
    'poids' => ['weight', Set::POIDS_MAX_KG + 1, 'Une série porte au plus 100 000 kg.'],
    'répétitions' => ['reps', Set::REPETITIONS_MAX + 1, 'Une série compte au plus 999 répétitions.'],
    'durée' => ['duration_seconds', Set::DUREE_MAX_SECONDES + 1, 'Une série dure au plus 24 heures.'],
    'distance' => ['distance_km', Set::DISTANCE_MAX_KM + 1, 'Une série couvre au plus 1 000 km.'],
    'répétitions négatives' => ['reps', -1, 'La valeur de répétitions doit être au moins de 0.'],
]);

it('nomme la borne dans le message d’une valeur de série refusée, à la création comme à la modification', function (string $champ, int $horsBornes, string $message): void {
    $compte = User::factory()->create();
    $ligne = valeursCapaciteLigneDe($compte);
    $serie = Set::factory()->create(['workout_line_id' => $ligne->id]);

    actingAs($compte, 'sanctum')
        ->postJson(route('api.v1.sets.store'), ['workout_line_id' => $ligne->id, $champ => $horsBornes])
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$champ}.0", $message);

    actingAs($compte, 'sanctum')
        ->patchJson(route('api.v1.sets.update', $serie), [$champ => $horsBornes])
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$champ}.0", $message);
})->with('valeurs capacite messages');
