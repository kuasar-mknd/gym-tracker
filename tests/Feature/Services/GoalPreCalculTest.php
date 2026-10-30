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
use Illuminate\Database\Eloquent\Collection;
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
 * Une seance faite, terminee, dont une serie cochee porte le volume demande.
 */
function seanceDeVolumeDObjectif(User $user, Exercise $exercise, float $poids, int $repetitions): void
{
    $seance = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $seance->id,
        'exercise_id' => $exercise->id,
    ]);
    Set::factory()->create([
        'workout_line_id' => $workoutLine->id,
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

    Workout::withoutEvents(static fn (): Collection => Workout::factory()->count(2)->faite()->create(['user_id' => $user->id]));
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

/*
 * Chaque colonne à la dernière pesée qui la porte, et toujours en une lecture
 * (#2107).
 *
 * Le lot lisait UNE pesée, la dernière, pour le poids de corps et la masse
 * grasse à la fois. La masse grasse est facultative : une dernière pesée sans
 * elle laissait l'objectif de masse grasse à sa valeur d'avant. Chaque colonne
 * a désormais sa sous-requête, dans la même instruction.
 */
it('lit chaque colonne à la dernière pesée qui la porte, en une seule lecture', function (): void {
    $user = User::factory()->create();

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 86,
        'body_fat' => 22,
        'measured_at' => now()->subDays(3),
    ]);

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 85,
        'body_fat' => null,
        'measured_at' => now(),
    ]);

    $poids = objectifASynchroniser($user, GoalType::Measurement, ['measurement_type' => 'weight']);
    $masseGrasse = objectifASynchroniser($user, GoalType::Measurement, ['measurement_type' => 'body_fat']);

    $sql = sqlPendantLaSynchronisationDObjectifs(fn () => app(GoalService::class)->syncGoals($user));

    expect($poids->refresh()->current_value)->toBe(85.0)
        ->and($masseGrasse->refresh()->current_value)->toBe(22.0)
        ->and(lecturesDeLaTableDObjectifs($sql, 'body_measurements'))->toBe(1);
});

/*
 * Un objectif de partie du corps se lit dans `body_part_measurements` : la
 * lecture des pesées ne le sert pas. Le lot la faisait pour tout objectif de
 * mensuration ; il ne la fait plus que pour le poids de corps et la masse
 * grasse (#2107).
 */
it('ne lit pas les pesées pour les seuls objectifs de partie du corps', function (): void {
    $user = User::factory()->create();

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 85,
        'measured_at' => now(),
    ]);

    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Waist',
        'value' => 88,
        'unit' => 'cm',
        'measured_at' => now(),
    ]);

    $objectif = objectifASynchroniser($user, GoalType::Measurement, ['measurement_type' => 'Waist']);

    $sql = sqlPendantLaSynchronisationDObjectifs(fn () => app(GoalService::class)->syncGoals($user));

    expect($objectif->refresh()->current_value)->toBe(88.0)
        ->and(lecturesDeLaTableDObjectifs($sql, 'body_measurements'))->toBe(0);
});

/**
 * Une mesure de partie du corps, à sa date.
 */
function mesureDePartieDObjectif(User $user, string $partie, float $valeur, string $unite, string $le): void
{
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => $partie,
        'value' => $valeur,
        'unit' => $unite,
        'measured_at' => $le,
    ]);
}

/*
 * La dernière mesure de chaque partie suivie, en une seule lecture (#2131).
 *
 * Chaque objectif de partie du corps lisait sa mesure par sa propre requête :
 * trois objectifs, trois lectures de `body_part_measurements`, la même requête
 * chaque fois. Le lot lit désormais toutes les parties suivies dans la même
 * instruction, comme les colonnes des pesées.
 *
 * Chaque cas de lecture y est : une partie mesurée deux fois (la dernière
 * compte), une mesure en pouces (ramenée en centimètres), une partie jamais
 * mesurée (la valeur de départ).
 */
it('ne lit la dernière mesure des parties du corps qu’une fois pour plusieurs objectifs', function (): void {
    $user = User::factory()->create();

    mesureDePartieDObjectif($user, 'Waist', 88, 'cm', '2026-06-01');
    mesureDePartieDObjectif($user, 'Waist', 85, 'cm', '2026-06-15');
    mesureDePartieDObjectif($user, 'Chest', 101, 'cm', '2026-06-10');
    mesureDePartieDObjectif($user, 'Hips', 38, 'in', '2026-06-12');

    $taille = objectifASynchroniser($user, GoalType::Measurement, ['measurement_type' => 'Waist']);
    $poitrine = objectifASynchroniser($user, GoalType::Measurement, ['measurement_type' => 'Chest']);
    $hanches = objectifASynchroniser($user, GoalType::Measurement, ['measurement_type' => 'Hips']);
    $cou = objectifASynchroniser($user, GoalType::Measurement, ['measurement_type' => 'Neck', 'start_value' => 7]);

    $sql = sqlPendantLaSynchronisationDObjectifs(fn () => app(GoalService::class)->syncGoals($user));

    expect($taille->refresh()->current_value)->toBe(85.0)
        ->and($poitrine->refresh()->current_value)->toBe(101.0)
        ->and($hanches->refresh()->current_value)->toBe(38 * 2.54)
        ->and($cou->refresh()->current_value)->toBe(7.0)
        ->and(lecturesDeLaTableDObjectifs($sql, 'body_part_measurements'))->toBe(1);
});

/*
 * La forme de cette lecture : une sous-requête par partie distincte et par
 * mesure lue, la valeur et l'unité, et aucune pour un objectif qui ne nomme
 * pas de partie (sans mensuration, ou une colonne des pesées).
 *
 * Deux objectifs sur la même partie posent la même question : elle ne se pose
 * qu'une fois. Le compte des lectures ne le dit pas, la forme si : c'est elle
 * qu'on affirme, pas un nombre de lignes lues, qui dépend des statistiques
 * que MySQL tient (`.ai/rules/services.md`).
 */
it('ne pose qu’une sous-requête par partie distincte et par mesure lue', function (): void {
    $user = User::factory()->create();

    mesureDePartieDObjectif($user, 'Waist', 85, 'cm', '2026-06-15');
    mesureDePartieDObjectif($user, 'Chest', 101, 'cm', '2026-06-10');

    $objectifs = collect(['Waist', 'Waist', 'Chest', null, '', 'weight', 'body_fat'])->map(
        fn (?string $mensuration): Goal => objectifASynchroniser($user, GoalType::Measurement, ['measurement_type' => $mensuration])
    );

    $sql = sqlPendantLaSynchronisationDObjectifs(fn () => app(GoalService::class)->syncGoals($user));

    $lectures = array_values(array_filter($sql, fn (string $requete): bool => str_contains($requete, '`body_part_measurements`')));

    expect($lectures)->toHaveCount(1)
        ->and(substr_count($lectures[0], 'from `body_part_measurements`'))->toBe(4)
        ->and($objectifs->map(fn (Goal $objectif): float => $objectif->refresh()->current_value)->all())
        ->toBe([85.0, 85.0, 101.0, 0.0, 0.0, 0.0, 0.0]);
});

/*
 * Deux objectifs peuvent nommer la même partie dans deux casses : « waist »
 * vient des objectifs d'avant les noms proposés, « Waist » des suivants. La
 * collation de la colonne les rapproche tous deux de la mesure saisie sous
 * « Waist », dans le lot comme dans le chemin d'un seul objectif
 * (`ObjectifSurUneMensurationTest`) : chacun a sa sous-requête, à sa
 * position, et le lot ne compare pas les noms en PHP.
 */
it('rend la même mesure à deux objectifs qui nomment la partie dans deux casses', function (): void {
    $user = User::factory()->create();

    mesureDePartieDObjectif($user, 'Waist', 84, 'cm', '2026-06-15');

    $majuscule = objectifASynchroniser($user, GoalType::Measurement, ['measurement_type' => 'Waist']);
    $minuscule = objectifASynchroniser($user, GoalType::Measurement, ['measurement_type' => 'waist']);

    $sql = sqlPendantLaSynchronisationDObjectifs(fn () => app(GoalService::class)->syncGoals($user));

    expect($majuscule->refresh()->current_value)->toBe(84.0)
        ->and($minuscule->refresh()->current_value)->toBe(84.0)
        ->and(lecturesDeLaTableDObjectifs($sql, 'body_part_measurements'))->toBe(1);
});

/*
 * Et sans objectif de partie du corps, la lecture n'a pas lieu : un compte
 * qui ne suit que son poids de corps ne paie pas celle des parties, même s'il
 * en mesure.
 */
it('ne lit pas les parties du corps pour les seuls objectifs de pesée', function (): void {
    $user = User::factory()->create();

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 85,
        'measured_at' => now(),
    ]);
    mesureDePartieDObjectif($user, 'Waist', 88, 'cm', '2026-06-15');

    $objectif = objectifASynchroniser($user, GoalType::Measurement, ['measurement_type' => 'weight']);

    $sql = sqlPendantLaSynchronisationDObjectifs(fn () => app(GoalService::class)->syncGoals($user));

    expect($objectif->refresh()->current_value)->toBe(85.0)
        ->and(lecturesDeLaTableDObjectifs($sql, 'body_part_measurements'))->toBe(0);
});

/**
 * Le lot ne repasse jamais par le compte de chaque objectif.
 *
 * L'utilisateur est déjà en main, et chaque lecture du lot part de lui :
 * séances, records, volumes, pesées et, depuis #2131, parties du corps. Ces
 * dernières étaient le chemin qui déroulait la relation de l'objectif —
 * `$goal->user->bodyPartMeasurements()` — et le lot rattachait le compte à
 * chaque objectif pour ne pas le relire. Il ne le rattache plus : plus rien
 * dans le lot ne lit `$goal->user`. Un chemin qui s'y remettrait relirait
 * `users` pour chaque objectif, et le mode strict d'Eloquent lèverait sur ce
 * chargement paresseux : ce cas tient toutes les sortes d'objectifs à la fois.
 */
it('ne relit pas l’utilisateur pour chaque objectif', function (): void {
    $user = User::factory()->create();
    $exerciceDeForce = Exercise::factory()->create();
    $exerciceDeVolume = Exercise::factory()->create();

    recordDeForceDObjectif($user, $exerciceDeForce, 80);
    seanceDeVolumeDObjectif($user, $exerciceDeVolume, 50, 10);
    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 85,
        'measured_at' => now(),
    ]);
    mesureDePartieDObjectif($user, 'Waist', 88, 'cm', '2026-06-15');

    $objectifs = collect([
        [GoalType::Measurement, ['measurement_type' => 'Waist']],
        [GoalType::Measurement, ['measurement_type' => 'Waist']],
        [GoalType::Measurement, ['measurement_type' => 'weight']],
        [GoalType::Measurement, ['measurement_type' => null, 'start_value' => 3]],
        [GoalType::Weight, ['exercise_id' => $exerciceDeForce->id]],
        [GoalType::Volume, ['exercise_id' => $exerciceDeVolume->id]],
        [GoalType::Frequency, []],
    ])->map(fn (array $objectif): Goal => objectifASynchroniser($user, ...$objectif));

    $sql = sqlPendantLaSynchronisationDObjectifs(fn () => app(GoalService::class)->syncGoals($user));

    expect($objectifs->map(fn (Goal $objectif): float => $objectif->refresh()->current_value)->all())
        ->toBe([88.0, 88.0, 85.0, 3.0, 80.0, 500.0, 1.0])
        ->and(lecturesDeLaTableDObjectifs($sql, 'users'))->toBe(0);
});
