<?php

declare(strict_types=1);

use App\Jobs\SyncPersonalRecord;
use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\PersonalRecordService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * Mesure pour #2324 : les records personnels s'écrivent un type à la fois.
 *
 * `EcrituresParOperationTest` fausse les files : la synchronisation d'une
 * série n'y tourne jamais. Ici elle s'exécute pour de bon (le travail et le
 * service), et seuls les envois qu'elle ferait en aval sont interceptés.
 * Seules les écritures (insert, update, delete) sont épinglées : les lectures
 * se rapportent, elles ne se figent pas.
 */

/**
 * @return array{ecritures: list<string>, lectures: list<string>}
 */
function requetesDesRecords(callable $action): array
{
    /** @var list<string> $sql */
    $sql = [];
    DB::listen(function (QueryExecuted $query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    $action();

    DB::getEventDispatcher()->forget(QueryExecuted::class);

    $ecritures = [];
    $lectures = [];

    foreach ($sql as $requete) {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $requete) === 1) {
            $ecritures[] = $requete;
        } else {
            $lectures[] = $requete;
        }
    }

    return ['ecritures' => $ecritures, 'lectures' => $lectures];
}

/**
 * Une série cochée de 100 kg pour 5 répétitions : 100 de poids, 116,67 de 1RM,
 * 500 de volume. Les records de l'exercice sont posés sans événement.
 *
 * @param  array<string, float>|null  $existants
 * @return array{0: User, 1: Set}
 */
function serieQuiBatDesRecords(?array $existants): array
{
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
    $set = Set::factory()->createQuietly(['workout_line_id' => $line->id, 'weight' => 100, 'reps' => 5, 'is_completed' => true, 'is_warmup' => false]);
    $ancienne = Set::factory()->createQuietly(['workout_line_id' => $line->id, 'weight' => 10, 'reps' => 5, 'is_completed' => true, 'is_warmup' => false]);

    foreach ($existants ?? [] as $type => $valeur) {
        PersonalRecord::factory()->createQuietly([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'workout_id' => $workout->id,
            'set_id' => $ancienne->id,
            'type' => $type,
            'value' => $valeur,
        ]);
    }

    return [$user, $set];
}

/**
 * Une séance close de cinq exercices, une série cochée par exercice, et leurs
 * records reconstruits ; une séance plus ancienne tient une série plus légère
 * de chaque exercice, que les records reprennent quand la séance part.
 */
function seanceCloseDeCinqExercices(): Workout
{
    $user = User::factory()->create();
    $ancienne = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()->subDays(10), 'started_at' => now()->subDays(10)->subHour()]);
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now(), 'started_at' => now()->subHour()]);

    foreach (range(1, 5) as $i) {
        $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
        $vieille = WorkoutLine::factory()->create(['workout_id' => $ancienne->id, 'exercise_id' => $exercise->id]);
        Set::factory()->createQuietly(['workout_line_id' => $vieille->id, 'weight' => 50, 'reps' => 5, 'is_completed' => true, 'is_warmup' => false]);
        $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
        Set::factory()->createQuietly(['workout_line_id' => $line->id, 'weight' => 100 + $i, 'reps' => 5, 'is_completed' => true, 'is_warmup' => false]);
        app(PersonalRecordService::class)->reparer($user, $exercise->id);
    }

    expect(PersonalRecord::query()->where('user_id', $user->id)->count())->toBe(15);

    return Workout::query()->findOrFail($workout->id);
}

/**
 * Une série cochée de 100 kg pour 5 répétitions qui détient les trois records
 * de son exercice, et une plus légère, d'une séance plus ancienne, qui les
 * reprend quand elle les perd. La synchronisation part en file, comme en
 * production : elle ne tourne pas dans la requête.
 */
function serieQuiTientLesTroisRecords(): Set
{
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
    $ancienne = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()->subDays(10), 'started_at' => now()->subDays(10)->subHour()]);
    $vieille = WorkoutLine::factory()->create(['workout_id' => $ancienne->id, 'exercise_id' => $exercise->id]);
    Set::factory()->createQuietly(['workout_line_id' => $vieille->id, 'weight' => 50, 'reps' => 5, 'is_completed' => true, 'is_warmup' => false]);
    $set = Set::factory()->createQuietly(['workout_line_id' => $line->id, 'weight' => 100, 'reps' => 5, 'is_completed' => true, 'is_warmup' => false]);
    app(PersonalRecordService::class)->reparer($user, $exercise->id);

    expect(PersonalRecord::query()->where('set_id', $set->id)->count())->toBe(3);

    config(['app.env' => 'local']);
    Queue::fake();

    return Set::query()->findOrFail($set->id);
}

it('synchronise une série qui bat un record en une écriture', function (): void {
    [$user, $set] = serieQuiBatDesRecords(['max_weight' => 100.0, 'max_1rm' => 200.0, 'max_volume_set' => 400.0]);

    $mesure = requetesDesRecords(fn () => SyncPersonalRecord::dispatchSync($set, $user));

    expect($mesure['ecritures'])->toHaveCount(1);
});

it('synchronise une série qui bat trois records en trois écritures', function (): void {
    [$user, $set] = serieQuiBatDesRecords(['max_weight' => 60.0, 'max_1rm' => 70.0, 'max_volume_set' => 300.0]);

    $mesure = requetesDesRecords(fn () => SyncPersonalRecord::dispatchSync($set, $user));

    expect($mesure['ecritures'])->toHaveCount(3);
});

it('synchronise une série qui crée trois records en trois écritures', function (): void {
    [$user, $set] = serieQuiBatDesRecords(null);

    $mesure = requetesDesRecords(fn () => SyncPersonalRecord::dispatchSync($set, $user));

    expect($mesure['ecritures'])->toHaveCount(3);
});

it('supprime une séance close de cinq exercices, records repris sur l historique, en dix-sept écritures', function (): void {
    $workout = seanceCloseDeCinqExercices();
    Queue::fake();

    $mesure = requetesDesRecords(fn () => $workout->delete());

    // La séance, la série de jours du compte et quinze records réécrits, un par type et par exercice.
    expect($mesure['ecritures'])->toHaveCount(17);
});

it('corrige le poids d une série qui tient les trois records de son exercice', function (): void {
    $set = serieQuiTientLesTroisRecords();

    $mesure = requetesDesRecords(fn () => $set->update(['weight' => 90]));

    // La série, ses trois records et le volume de la séance.
    expect($mesure['ecritures'])->toHaveCount(5);
});

it('décoche une série qui tient les trois records de son exercice', function (): void {
    $set = serieQuiTientLesTroisRecords();

    $mesure = requetesDesRecords(fn () => $set->update(['is_completed' => false]));

    // La série et ses trois records ; le volume de la séance ne bouge pas.
    expect($mesure['ecritures'])->toHaveCount(4);
});
