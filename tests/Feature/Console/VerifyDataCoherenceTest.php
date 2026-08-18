<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Facades\DB;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Le controle de coherence doit trouver ce qu'aucun test ne cherche.
 *
 * Chaque ecart est plante EN BASE, sans passer par Eloquent : c'est justement la
 * facon dont les vrais ecarts apparaissent — une cascade, une suppression en
 * masse, un job rejoue. Passer par les modeles declencherait les observateurs,
 * qui repareraient l'incoherence avant que le controle puisse la voir.
 */

/**
 * Un compte avec une seance, une serie, et les records qui vont avec.
 *
 * @return array{0: User, 1: Set}
 */
function compteCoherent(): array
{
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDay()]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    $set = Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 10,
        'is_warmup' => false,
    ]);

    return [$user->refresh(), $set];
}

it('ne signale rien quand tout concorde', function (): void {
    compteCoherent();

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(0)
        ->expectsOutputToContain('Aucun écart');
});

it('signale un volume utilisateur qui a dérivé', function (): void {
    [$user] = compteCoherent();

    // Directement en base : c'est ainsi qu'une derive arrive vraiment.
    DB::table('users')->where('id', $user->id)->update(['total_volume' => 9999]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain("utilisateur {$user->id}");
});

it('signale un volume de séance qui a dérivé', function (): void {
    [, $set] = compteCoherent();

    $workoutId = $set->workoutLine->workout_id;

    DB::table('workouts')->where('id', $workoutId)->update(['workout_volume' => 42]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain("séance {$workoutId}");
});

/**
 * L'ecart de #1476, celui que le controle existe pour voir.
 */
it('signale un record qui ne pointe sur aucune série', function (): void {
    [$user, $set] = compteCoherent();

    expect(PersonalRecord::query()->where('user_id', $user->id)->count())->toBeGreaterThan(0);

    // `ON DELETE SET NULL` : la ligne survit a la serie, simplement detachee.
    DB::table('sets')->where('id', $set->id)->delete();

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain('ne pointe sur aucune série');
});

it('signale un record dont la valeur ne correspond pas à sa série', function (): void {
    [$user] = compteCoherent();

    $record = PersonalRecord::query()
        ->where('user_id', $user->id)
        ->where('type', 'max_weight')
        ->firstOrFail();

    DB::table('personal_records')->where('id', $record->id)->update(['value' => 777]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain("record {$record->id}");
});

it('signale une date de dernière séance en retard', function (): void {
    [$user] = compteCoherent();

    DB::table('users')->where('id', $user->id)->update(['last_workout_at' => null]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain("utilisateur {$user->id}");
});

/**
 * Un controle qu'on ne peut pas ramener au vert finit desactive.
 *
 * La reparation n'invente rien : elle appelle le meme `recompute()` que
 * l'application execute quand une serie disparait.
 */
it('reconstruit les records détachés et repasse au vert', function (): void {
    [$user, $set] = compteCoherent();

    // Une seconde serie, plus legere, qui reprendra le record.
    Set::factory()->create([
        'workout_line_id' => $set->workout_line_id,
        'weight' => 60,
        'reps' => 10,
        'is_warmup' => false,
    ]);

    DB::table('sets')->where('id', $set->id)->delete();

    // Supprimer une serie en base fait aussi deriver les volumes, que rien ne
    // decremente sur ce chemin. On les remet a leur valeur reelle pour que le
    // test porte sur le seul ecart qui l'interesse — le controle, lui, signale
    // bien les deux, et c'est ce qu'on veut de lui.
    DB::table('users')->where('id', $user->id)->update(['total_volume' => 600]);
    DB::table('workouts')->where('id', $set->workoutLine->workout_id)->update(['workout_volume' => 600]);

    $this->artisan('app:verify-data-coherence')->assertExitCode(1);

    $this->artisan('app:verify-data-coherence --repair')
        ->assertExitCode(0)
        ->expectsOutputToContain('reconstruit');

    $valeur = PersonalRecord::query()
        ->where('user_id', $user->id)
        ->where('type', 'max_weight')
        ->value('value');

    expect(is_numeric($valeur) ? (float) $valeur : null)->toBe(60.0);
});
