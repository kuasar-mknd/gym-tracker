<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

it('ne tient plus de total par utilisateur : le volume soulevé se lit dans les séances', function (): void {
    [$user] = compteCoherent();

    expect(Schema::hasColumn('users', 'total_volume'))->toBeFalse()
        ->and($user->volumeSouleve())->toBe((float) DB::table('workouts')->where('user_id', $user->id)->sum('workout_volume'));

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('utilisateurs');
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

/**
 * Le compte affiche etait celui des lignes RENDUES, or `--limit` bornait la
 * requete : cinq cents utilisateurs derivant s'annoncaient « 5 ». Un controle
 * nocturne qui sous-estime l'etendue d'une derive est pire que muet, puisqu'il
 * a l'air d'avoir mesure.
 */
it('annonce le nombre d’écarts, pas le nombre d’exemples cités', function (): void {
    $derivants = [];

    for ($i = 0; $i < 7; $i++) {
        [$user] = compteCoherent();
        $derivants[] = $user->id;
    }

    DB::table('workouts')->whereIn('user_id', $derivants)->update(['workout_volume' => 9999]);

    $this->artisan('app:verify-data-coherence', ['--limit' => 2])
        ->assertExitCode(1)
        ->expectsOutputToContain('7 volume des séances')
        ->expectsOutputToContain('… et 5 autre(s), non cité(s)');
});

/**
 * Le controle des volumes utilisateurs ne descend plus jusqu'aux series : une
 * seance qui ment sur les siennes doit rester vue par l'autre controle.
 */
it('voit encore une séance qui ment sur ses séries', function (): void {
    [$user, $set] = compteCoherent();
    $workoutId = $set->workoutLine->workout_id;

    // La seance ET l'utilisateur portent le meme chiffre faux : leur somme
    // concorde, seule la comparaison aux series peut les demasquer.
    DB::table('workouts')->where('id', $workoutId)->update(['workout_volume' => 4242]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain("séance {$workoutId}");
});

/**
 * Le controle voisin compare la valeur du record au poids de sa serie ; il ne
 * demande jamais si cette serie comptait. Un record pose sur une serie jamais
 * cochee — le defaut corrige par #1615 — n'est pas DETACHE, donc il passait
 * les deux controles existants et survivait a la reparation.
 */
it('signale un record assis sur une série jamais cochée', function (): void {
    [$user, $set] = compteCoherent();

    DB::table('sets')->where('id', $set->id)->update(['is_completed' => false]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain("de l'utilisateur {$user->id} s'appuie sur une série qui ne compte pas");
});

it('signale un record assis sur un échauffement', function (): void {
    [, $set] = compteCoherent();

    DB::table('sets')->where('id', $set->id)->update(['is_warmup' => true]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain("s'appuie sur une série qui ne compte pas");
});

it('reconstruit un record assis sur une série inéligible', function (): void {
    [$user, $set] = compteCoherent();

    // Une seconde serie, faite celle-la, plus legere : c'est elle qui doit
    // porter le record une fois la premiere ecartee.
    Set::factory()->create([
        'workout_line_id' => $set->workout_line_id,
        'weight' => 40,
        'reps' => 10,
        'is_warmup' => false,
        'is_completed' => true,
    ]);

    DB::table('sets')->where('id', $set->id)->update(['is_completed' => false]);

    $this->artisan('app:verify-data-coherence', ['--repair' => true]);

    $record = DB::table('personal_records')
        ->where('user_id', $user->id)
        ->where('type', 'max_weight')
        ->value('value');

    expect(is_numeric($record) ? (float) $record : null)->toBe(40.0);
});

/**
 * Le controle voyait ces deux-la, la reparation non.
 *
 * Un record dont la VALEUR diverge d'une serie pourtant valide n'est ni
 * detache ni inegible : il sortait de la selection de `reconstruireLesRecords()`.
 * Et `last_workout_at` etait controle sans jamais etre repare. Les deux sont
 * apparus en production le 31/08, apres un `--repair` qui les a laisses rouges.
 */
it('reconstruit un record dont la valeur ne correspond plus à sa série', function (): void {
    [$user, $set] = compteCoherent();

    // La valeur du record ment, mais sa serie est valide : ni detache, ni
    // inegible. C'est le cas qu'aucune reparation ne couvrait.
    DB::table('personal_records')
        ->where('user_id', $user->id)
        ->where('type', 'max_weight')
        ->update(['value' => 500]);

    $this->artisan('app:verify-data-coherence', ['--repair' => true]);

    $record = DB::table('personal_records')
        ->where('user_id', $user->id)
        ->where('type', 'max_weight')
        ->value('value');

    expect(is_numeric($record) ? (float) $record : null)->toBe((float) $set->weight);
});

it('refait la date de dernière séance quand elle pointe dans le vide', function (): void {
    [$user, $set] = compteCoherent();

    $reelle = $set->workoutLine->workout->started_at;

    // Une date en avance de trois mois sur la derniere seance : c'est ce qu'une
    // suppression anterieure a #1460 laissait derriere elle.
    DB::table('users')->where('id', $user->id)->update([
        'last_workout_at' => $reelle->copy()->addMonths(3),
    ]);

    $this->artisan('app:verify-data-coherence', ['--repair' => true]);

    $stockee = DB::table('users')->where('id', $user->id)->value('last_workout_at');

    expect(is_string($stockee) ? $stockee : null)->toBe($reelle->toDateTimeString());
});

it('repasse au vert après réparation', function (): void {
    [$user, $set] = compteCoherent();

    DB::table('personal_records')->where('user_id', $user->id)->where('type', 'max_weight')->update(['value' => 500]);
    DB::table('users')->where('id', $user->id)->update([
        'last_workout_at' => $set->workoutLine->workout->started_at->copy()->addMonths(3),
    ]);
    DB::table('workouts')->where('id', $set->workoutLine->workout_id)->update(['workout_volume' => 42]);

    $this->artisan('app:verify-data-coherence', ['--repair' => true]);

    // Une seconde passe, sans réparer : plus rien ne doit être signalé.
    $this->artisan('app:verify-data-coherence')->assertExitCode(0);
});
