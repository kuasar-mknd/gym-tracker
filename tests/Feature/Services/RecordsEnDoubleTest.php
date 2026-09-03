<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\PersonalRecordService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** @return array{0: User, 1: Exercise, 2: WorkoutLine} */
function scenePourDoublons(): array
{
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $seance = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDay()]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);

    return [$user, $exercice, $ligne];
}

/**
 * Deux records du meme type sur le meme exercice : `keyBy()` n'en gardait que
 * le DERNIER, et le premier n'etait ni mis a jour ni supprime. Il annoncait
 * indefiniment une valeur que plus rien ne soutenait.
 *
 * Constate en production le 31/08 : deux `max_volume_set` sur le meme exercice,
 * dont un pose sur une serie jamais cochee, que la reparation ne corrigeait pas.
 */
it('supprime le doublon et ne garde qu’un record par type', function (): void {
    [$user, $exercice, $ligne] = scenePourDoublons();

    $faite = Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 20, 'reps' => 10, 'is_warmup' => false, 'is_completed' => true]);
    $decochee = Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 27, 'reps' => 12, 'is_warmup' => false, 'is_completed' => false]);

    // La contrainte est retiree le temps de fabriquer le doublon : c'est l'etat
    // d'une base d'AVANT `un_seul_record_par_type`, celui que la reparation
    // doit savoir rattraper.
    DB::statement('alter table personal_records drop index personal_records_user_exercise_type_unique');

    // La serie faite a deja produit son record par le hook `Set::saved`. On
    // ajoute le doublon : celui qui designe la serie decochee.
    DB::table('personal_records')->insert([
        'user_id' => $user->id, 'exercise_id' => $exercice->id, 'type' => 'max_volume_set',
        'value' => 324, 'set_id' => $decochee->id, 'achieved_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(PersonalRecord::where('type', 'max_volume_set')->count())->toBe(2);

    app(PersonalRecordService::class)->recompute($user, $exercice->id);

    $restants = PersonalRecord::where('user_id', $user->id)->where('type', 'max_volume_set')->get();
    $restant = $restants->first();

    expect($restants)->toHaveCount(1)
        ->and($restant)->not->toBeNull()
        ->and((int) $restant?->set_id)->toBe($faite->id)
        ->and((float) $restant?->value)->toBe(200.0);
});

it('refuse un second record du même type', function (): void {
    [$user, $exercice] = scenePourDoublons();

    $ligne = ['user_id' => $user->id, 'exercise_id' => $exercice->id, 'type' => 'max_weight', 'value' => 100, 'achieved_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    DB::table('personal_records')->insert($ligne);

    expect(fn () => DB::table('personal_records')->insert($ligne))->toThrow(QueryException::class);
});

it('laisse coexister deux types sur le même exercice', function (): void {
    [$user, $exercice, $ligne] = scenePourDoublons();

    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 30, 'reps' => 5, 'is_warmup' => false, 'is_completed' => true]);

    app(PersonalRecordService::class)->recompute($user, $exercice->id);

    $types = PersonalRecord::where('user_id', $user->id)
        ->get()
        ->map(fn (PersonalRecord $record): string => $record->type->value)
        ->sort()
        ->values()
        ->all();

    expect($types)->toBe(['max_1rm', 'max_volume_set', 'max_weight']);
});

/**
 * La migration nettoie ce qui existait deja : une base d'avant la contrainte
 * doit pouvoir l'accueillir sans que l'ajout de l'unicite echoue.
 */
it('la migration écarte les doublons avant de poser la contrainte', function (): void {
    [$user, $exercice] = scenePourDoublons();

    DB::statement('alter table personal_records drop index personal_records_user_exercise_type_unique');

    foreach ([100, 200] as $valeur) {
        DB::table('personal_records')->insert([
            'user_id' => $user->id, 'exercise_id' => $exercice->id, 'type' => 'max_weight',
            'value' => $valeur, 'achieved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(DB::table('personal_records')->count())->toBe(2);

    $migration = require database_path('migrations/2026_08_31_130425_un_seul_record_par_type.php');
    $migration->up();

    $restants = DB::table('personal_records')->get();
    $valeur = $restants->first()?->value;

    expect($restants)->toHaveCount(1)
        // Le plus recent est conserve, comme dans `recompute()`.
        ->and(is_numeric($valeur) ? (float) $valeur : null)->toBe(200.0);

    // Et la contrainte tient de nouveau.
    expect(fn () => DB::table('personal_records')->insert([
        'user_id' => $user->id, 'exercise_id' => $exercice->id, 'type' => 'max_weight',
        'value' => 300, 'achieved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
