<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Models\BodyMeasurement;
use App\Models\BodyPartMeasurement;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\GoalService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * Ce que le pre-calcul de `syncGoals()` promet, table par table.
 *
 * `GoalServicePerformanceTest` verifie qu'il n'y a pas de N+1 sur vingt
 * objectifs, avec une borne large. Il ne dit rien de QUELLE table est lue, ni
 * combien de fois : un pre-calcul qui interrogerait les records d'un pratiquant
 * qui n'a aucun objectif de force passerait devant lui sans bruit.
 *
 * Or c'est tout ce que `preCalculateMetrics()` decide : quatre gardes, chacun
 * disant « cette lecture-la n'a de sens que pour cette sorte d'objectif ». Les
 * valeurs rendues sont, elles, identiques au chemin de repli — c'est la raison
 * d'etre de `GoalSyncPathsTest`. La seule difference observable est donc la
 * lecture elle-meme, et c'est elle qu'on affirme ici, en la nommant.
 *
 * Chaque cas verifie aussi la valeur obtenue : compter des requetes sans
 * regarder le resultat laisserait passer un pre-calcul qui ne lit qu'une fois
 * parce qu'il ne lit rien de bon.
 */

/**
 * Le SQL emis pendant l'appel, dans l'ordre.
 *
 * @return list<string>
 */
function sqlPendantLaSynchronisationDObjectifs(callable $geste): array
{
    /** @var list<string> $sql */
    $sql = [];

    DB::listen(function (QueryExecuted $requete) use (&$sql): void {
        $sql[] = $requete->sql;
    });

    $geste();

    DB::getEventDispatcher()->forget(QueryExecuted::class);

    return $sql;
}

/**
 * Combien de ces requetes touchent la table nommee.
 *
 * @param  list<string>  $sql
 */
function lecturesDeLaTableDObjectifs(array $sql, string $table): int
{
    return count(array_filter($sql, fn (string $requete): bool => str_contains($requete, "`{$table}`")));
}

/**
 * Un objectif a zero, loin de sa cible : toute lecture se voit sur sa valeur.
 *
 * @param  array<string, mixed>  $attributs
 */
function objectifASynchroniser(User $user, GoalType $type, array $attributs = []): Goal
{
    return Goal::factory()->create([
        'user_id' => $user->id,
        'type' => $type,
        'start_value' => 0,
        'current_value' => 0,
        'target_value' => 100000,
        'completed_at' => null,
        ...$attributs,
    ]);
}

/**
 * Un record de force pose sur l'exercice, comme `PersonalRecordService` l'ecrit.
 */
function recordDeForceDObjectif(User $user, Exercise $exercise, float $valeur): void
{
    PersonalRecord::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
        'type' => 'max_weight',
        'value' => $valeur,
    ]);
}

/**
 * Une seance dont une serie faite porte le volume demande.
 */
function seanceDeVolumeDObjectif(User $user, Exercise $exercise, float $poids, int $repetitions): void
{
    $seance = Workout::factory()->create(['user_id' => $user->id]);
    $ligne = WorkoutLine::factory()->create([
        'workout_id' => $seance->id,
        'exercise_id' => $exercise->id,
    ]);
    Set::factory()->create([
        'workout_line_id' => $ligne->id,
        'weight' => $poids,
        'reps' => $repetitions,
    ]);
}

it('ne lit les records qu’une fois pour plusieurs objectifs de force', function (): void {
    $user = User::factory()->create();
    $premier = Exercise::factory()->create();
    $second = Exercise::factory()->create();

    recordDeForceDObjectif($user, $premier, 80);
    recordDeForceDObjectif($user, $second, 120);

    $objectifPremier = objectifASynchroniser($user, GoalType::Weight, ['exercise_id' => $premier->id]);
    $objectifSecond = objectifASynchroniser($user, GoalType::Weight, ['exercise_id' => $second->id]);

    $sql = sqlPendantLaSynchronisationDObjectifs(fn () => app(GoalService::class)->syncGoals($user));

    expect($objectifPremier->refresh()->current_value)->toBe(80.0)
        ->and($objectifSecond->refresh()->current_value)->toBe(120.0)
        ->and(lecturesDeLaTableDObjectifs($sql, 'personal_records'))->toBe(1);
});

it('ne calcule le volume qu’une fois pour plusieurs objectifs de volume', function (): void {
    $user = User::factory()->create();
    $premier = Exercise::factory()->create();
    $second = Exercise::factory()->create();

    seanceDeVolumeDObjectif($user, $premier, 50, 10);
    seanceDeVolumeDObjectif($user, $second, 60, 10);

    $objectifPremier = objectifASynchroniser($user, GoalType::Volume, ['exercise_id' => $premier->id]);
    $objectifSecond = objectifASynchroniser($user, GoalType::Volume, ['exercise_id' => $second->id]);

    $sql = sqlPendantLaSynchronisationDObjectifs(fn () => app(GoalService::class)->syncGoals($user));

    expect($objectifPremier->refresh()->current_value)->toBe(500.0)
        ->and($objectifSecond->refresh()->current_value)->toBe(600.0)
        ->and(lecturesDeLaTableDObjectifs($sql, 'workout_lines'))->toBe(1);
});

/*
 * Les quatre gardes de `preCalculateMetrics()`, pris a l'envers : ce qu'on ne
 * lit pas. Un pratiquant n'a presque jamais les quatre sortes d'objectifs a la
 * fois, donc c'est le cas courant, pas le cas limite.
 */
it('ne lit pas les records sans objectif de force', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    seanceDeVolumeDObjectif($user, $exercise, 50, 10);
    $objectif = objectifASynchroniser($user, GoalType::Volume, ['exercise_id' => $exercise->id]);

    $sql = sqlPendantLaSynchronisationDObjectifs(fn () => app(GoalService::class)->syncGoals($user));

    expect($objectif->refresh()->current_value)->toBe(500.0)
        ->and(lecturesDeLaTableDObjectifs($sql, 'personal_records'))->toBe(0);
});

it('ne calcule pas de volume sans objectif de volume', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    recordDeForceDObjectif($user, $exercise, 80);
    $objectif = objectifASynchroniser($user, GoalType::Weight, ['exercise_id' => $exercise->id]);

    $sql = sqlPendantLaSynchronisationDObjectifs(fn () => app(GoalService::class)->syncGoals($user));

    expect($objectif->refresh()->current_value)->toBe(80.0)
        ->and(lecturesDeLaTableDObjectifs($sql, 'workout_lines'))->toBe(0);
});

it('ne compte pas les séances sans objectif de fréquence', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    recordDeForceDObjectif($user, $exercise, 80);
    Workout::factory()->count(3)->create(['user_id' => $user->id]);

    $objectif = objectifASynchroniser($user, GoalType::Weight, ['exercise_id' => $exercise->id]);

    $sql = sqlPendantLaSynchronisationDObjectifs(fn () => app(GoalService::class)->syncGoals($user));

    expect($objectif->refresh()->current_value)->toBe(80.0)
        ->and(lecturesDeLaTableDObjectifs($sql, 'workouts'))->toBe(0);
});

it('ne lit pas les mensurations sans objectif de mensuration', function (): void {
    $user = User::factory()->create();

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 85,
        'measured_at' => now(),
    ]);

    Workout::factory()->count(2)->create(['user_id' => $user->id]);
    $objectif = objectifASynchroniser($user, GoalType::Frequency);

    $sql = sqlPendantLaSynchronisationDObjectifs(fn () => app(GoalService::class)->syncGoals($user));

    expect($objectif->refresh()->current_value)->toBe(2.0)
        ->and(lecturesDeLaTableDObjectifs($sql, 'body_measurements'))->toBe(0);
});

it('ne lit la dernière mensuration qu’une fois pour plusieurs objectifs', function (): void {
    $user = User::factory()->create();

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 85,
        'body_fat' => 22,
        'measured_at' => now(),
    ]);

    $poids = objectifASynchroniser($user, GoalType::Measurement, ['measurement_type' => 'weight']);
    $masseGrasse = objectifASynchroniser($user, GoalType::Measurement, ['measurement_type' => 'body_fat']);

    $sql = sqlPendantLaSynchronisationDObjectifs(fn () => app(GoalService::class)->syncGoals($user));

    expect($poids->refresh()->current_value)->toBe(85.0)
        ->and($masseGrasse->refresh()->current_value)->toBe(22.0)
        ->and(lecturesDeLaTableDObjectifs($sql, 'body_measurements'))->toBe(1);
});

/**
 * L'utilisateur est deja en main : le rattacher a chaque objectif evite qu'ils
 * aillent le rechercher un par un.
 *
 * Une mensuration de partie du corps est le chemin qui deroule la relation —
 * `$goal->user->bodyPartMeasurements()`. Sans le rattachement, trois objectifs
 * font trois lectures de `users` pour retrouver celui qu'on tient deja.
 */
it('ne relit pas l’utilisateur pour chaque objectif', function (): void {
    $user = User::factory()->create();

    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Waist',
        'value' => 88,
        'unit' => 'cm',
        'measured_at' => now(),
    ]);

    $objectifs = collect(range(1, 3))->map(
        fn (): Goal => objectifASynchroniser($user, GoalType::Measurement, ['measurement_type' => 'Waist'])
    );

    $sql = sqlPendantLaSynchronisationDObjectifs(fn () => app(GoalService::class)->syncGoals($user));

    foreach ($objectifs as $objectif) {
        expect($objectif->refresh()->current_value)->toBe(88.0);
    }

    expect(lecturesDeLaTableDObjectifs($sql, 'users'))->toBe(0);
});
