<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Goal;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\GoalService;
use App\Services\PersonalRecordService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function recordMaxDe(User $user, Exercise $exercice): float
{
    $valeur = DB::table('personal_records')
        ->where('user_id', $user->id)
        ->where('exercise_id', $exercice->id)
        ->where('type', 'max_weight')
        ->value('value');

    return is_numeric($valeur) ? (float) $valeur : 0.0;
}

/** @return array{0: User, 1: Exercise, 2: WorkoutLine} */
function ligneDeSeance(): array
{
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $seance = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDay()]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);

    return [$user, $exercice, $ligne];
}

/**
 * L'interface cree chaque serie DECOCHEE, puis l'utilisateur la coche une fois
 * faite. Un poids saisi mais jamais coche est une intention, pas un souleve —
 * et il devenait pourtant un record personnel.
 *
 * `Workout::recomputeVolume()` filtre `is_completed` depuis #1499 : la meme
 * serie ne comptait donc dans aucun volume tout en tenant le record.
 */
it('ne fait pas un record d’une série jamais cochée', function (): void {
    [$user, $exercice, $ligne] = ligneDeSeance();

    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 100, 'reps' => 5, 'is_warmup' => false, 'is_completed' => true]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 200, 'reps' => 5, 'is_warmup' => false, 'is_completed' => false]);

    app(PersonalRecordService::class)->recompute($user, $exercice->id);

    expect(recordMaxDe($user, $exercice))->toBe(100.0);
});

/**
 * `GoalService` refaisait le `MAX(sets.weight)` que `PersonalRecordService`
 * tient deja — sans en reprendre les regles. Sur les memes donnees, l'objectif
 * annoncait 200 la ou le record en disait 100.
 */
it('accorde l’objectif de poids au record', function (): void {
    [$user, $exercice, $ligne] = ligneDeSeance();

    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 100, 'reps' => 5, 'is_warmup' => false, 'is_completed' => true]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 150, 'reps' => 5, 'is_warmup' => true, 'is_completed' => true]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 200, 'reps' => 5, 'is_warmup' => false, 'is_completed' => false]);

    app(PersonalRecordService::class)->recompute($user, $exercice->id);

    $objectif = Goal::factory()->create([
        'user_id' => $user->id, 'exercise_id' => $exercice->id,
        'type' => 'weight', 'target_value' => 300, 'current_value' => 0,
    ]);

    app(GoalService::class)->syncGoals($user->refresh());

    expect((float) $objectif->refresh()->current_value)->toBe(recordMaxDe($user, $exercice))
        ->and((float) $objectif->current_value)->toBe(100.0);
});

it('ignore les séries non faites dans un objectif de volume', function (): void {
    [$user, $exercice, $ligne] = ligneDeSeance();

    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 50, 'reps' => 10, 'is_warmup' => false, 'is_completed' => true]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 100, 'reps' => 10, 'is_warmup' => false, 'is_completed' => false]);

    $objectif = Goal::factory()->create([
        'user_id' => $user->id, 'exercise_id' => $exercice->id,
        'type' => 'volume', 'target_value' => 5000, 'current_value' => 0,
    ]);

    app(GoalService::class)->syncGoals($user->refresh());

    expect((float) $objectif->refresh()->current_value)->toBe(500.0);
});

/**
 * Une serie postee sans `is_completed` retombait sur le defaut de la colonne,
 * donc « non faite » : elle ne comptait dans aucun volume tout en posant un
 * record. Un client d'API qui rapporte un poids et des repetitions rapporte ce
 * qu'il vient de faire.
 */
it('compte dans le volume une série postée sans le champ', function (): void {
    [$user, , $ligne] = ligneDeSeance();
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.sets.store'), [
        'workout_line_id' => $ligne->id,
        'weight' => 60,
        'reps' => 10,
    ])->assertCreated();

    $seance = Workout::findOrFail($ligne->workout_id);

    expect((float) $seance->workout_volume)->toBe(600.0)
        ->and((float) User::findOrFail($user->id)->total_volume)->toBe(600.0);
});

it('respecte un `is_completed` explicitement faux', function (): void {
    [$user, , $ligne] = ligneDeSeance();
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.sets.store'), [
        'workout_line_id' => $ligne->id,
        'weight' => 60,
        'reps' => 10,
        'is_completed' => false,
    ])->assertCreated();

    expect((float) Workout::findOrFail($ligne->workout_id)->workout_volume)->toBe(0.0);
});
