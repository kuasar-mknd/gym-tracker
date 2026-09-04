<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Models\BodyMeasurement;
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
 * Les gardes de `updateGoalProgress()`, le chemin d'un seul objectif.
 *
 * C'est celui que prend l'ecran des objectifs (`GoalController`), sans metrique
 * pre-calculee : chaque garde y decide s'il faut interroger la base, et pour
 * quelle table. Un garde retire ne change presque jamais la VALEUR rendue —
 * chercher un exercice nul ne ramene rien, chercher une mensuration sans nom
 * non plus — il change ce qu'on demande a la base. C'est donc cela qu'on
 * affirme, en plus de la valeur.
 */

/**
 * Le SQL emis pendant l'appel, dans l'ordre.
 *
 * @return list<string>
 */
function sqlPendantLeSuiviDUnObjectif(callable $geste): array
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
function lecturesDeLaTableDUnObjectif(array $sql, string $table): int
{
    return count(array_filter($sql, fn (string $requete): bool => str_contains($requete, "`{$table}`")));
}

/**
 * Un objectif isole, a zero, que rien n'a encore rempli.
 *
 * @param  array<string, mixed>  $attributs
 */
function objectifSuiviSeul(User $user, GoalType $type, array $attributs = []): Goal
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

/*
 * Un objectif de force sans exercice n'a rien a mesurer.
 *
 * Le formulaire en impose un, mais la colonne est nullable et le service ne
 * suppose rien : sans exercice, il n'y a pas de record a chercher, et la
 * question ne se pose pas a la base.
 */
it('n’interroge pas les records pour un objectif de force sans exercice', function (): void {
    $user = User::factory()->create();

    PersonalRecord::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => Exercise::factory()->create()->id,
        'type' => 'max_weight',
        'value' => 140,
    ]);

    $objectif = objectifSuiviSeul($user, GoalType::Weight, ['exercise_id' => null]);

    $sql = sqlPendantLeSuiviDUnObjectif(fn () => app(GoalService::class)->updateGoalProgress($objectif));

    expect($objectif->current_value)->toBe(0.0)
        ->and(lecturesDeLaTableDUnObjectif($sql, 'personal_records'))->toBe(0);
});

it('n’interroge pas les séries pour un objectif de volume sans exercice', function (): void {
    $user = User::factory()->create();

    $seance = Workout::factory()->create(['user_id' => $user->id]);
    $ligne = WorkoutLine::factory()->create([
        'workout_id' => $seance->id,
        'exercise_id' => Exercise::factory()->create()->id,
    ]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 50, 'reps' => 10]);

    $objectif = objectifSuiviSeul($user, GoalType::Volume, ['exercise_id' => null]);

    $sql = sqlPendantLeSuiviDUnObjectif(fn () => app(GoalService::class)->updateGoalProgress($objectif));

    expect($objectif->current_value)->toBe(0.0)
        ->and(lecturesDeLaTableDUnObjectif($sql, 'workout_lines'))->toBe(0);
});

/**
 * Le compte des seances est retenu sur l'utilisateur, pas redemande.
 *
 * Un pratiquant peut avoir plusieurs objectifs de frequence, et l'ecran les met
 * a jour l'un apres l'autre sur le meme utilisateur. Le compte ne change pas
 * entre deux : il se lit une fois.
 */
it('ne recompte pas les séances déjà comptées', function (): void {
    $user = User::factory()->create();
    Workout::factory()->count(3)->create(['user_id' => $user->id]);

    $objectif = objectifSuiviSeul($user, GoalType::Frequency);
    $service = app(GoalService::class);

    $sql = sqlPendantLeSuiviDUnObjectif(function () use ($service, $objectif): void {
        $service->updateGoalProgress($objectif);
        $service->updateGoalProgress($objectif);
    });

    $comptages = array_filter(
        $sql,
        fn (string $requete): bool => str_contains($requete, 'count(*)') && str_contains($requete, '`workouts`')
    );

    expect($objectif->current_value)->toBe(3.0)
        ->and($comptages)->toHaveCount(1);
});

/**
 * Le volume d'un objectif est le meilleur d'UNE seance, pas le cumul.
 *
 * Les volumes sont groupes par seance puis ordonnes : c'est la premiere ligne
 * qui compte, et il faut qu'il y en ait une. Deux seances de volumes
 * differents suffisent a dire laquelle est retenue.
 */
it('retient le meilleur volume d’une séance quand rien n’est pré-calculé', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    foreach ([[50, 10], [60, 10]] as [$poids, $repetitions]) {
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

    $objectif = objectifSuiviSeul($user, GoalType::Volume, ['exercise_id' => $exercise->id]);

    app(GoalService::class)->updateGoalProgress($objectif);

    expect($objectif->current_value)->toBe(600.0);
});

it('suit le poids de corps quand c’est la mensuration visée', function (): void {
    $user = User::factory()->create();

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 85,
        'measured_at' => now(),
    ]);

    $objectif = objectifSuiviSeul($user, GoalType::Measurement, ['measurement_type' => 'weight']);

    app(GoalService::class)->updateGoalProgress($objectif);

    expect($objectif->current_value)->toBe(85.0);
});

/**
 * Un objectif de mensuration sans type ne cherche rien.
 *
 * La colonne est nullable et le formulaire a change plusieurs fois : des
 * objectifs sans type existent en base. Sans le garde, ils partiraient chercher
 * une partie du corps qui ne s'appelle pas — une lecture qui ne peut rien
 * ramener.
 */
it('ne cherche aucune mensuration quand l’objectif n’en nomme pas', function (?string $type): void {
    $user = User::factory()->create();

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 85,
        'measured_at' => now(),
    ]);

    $objectif = objectifSuiviSeul($user, GoalType::Measurement, ['measurement_type' => $type]);

    $sql = sqlPendantLeSuiviDUnObjectif(fn () => app(GoalService::class)->updateGoalProgress($objectif));

    expect($objectif->current_value)->toBe(0.0)
        ->and(lecturesDeLaTableDUnObjectif($sql, 'body_part_measurements'))->toBe(0)
        ->and(lecturesDeLaTableDUnObjectif($sql, 'body_measurements'))->toBe(0);
})->with([
    'sans type' => [null],
    'type vide' => [''],
]);
