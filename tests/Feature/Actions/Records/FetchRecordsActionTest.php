<?php

declare(strict_types=1);

use App\Actions\Records\FetchRecordsAction;
use App\Enums\PersonalRecordType;
use App\Jobs\SyncPersonalRecord;
use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\PersonalRecordService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * La page Records lit les records du compte, exercice par exercice (#2434).
 * L'action les lit sans le modèle (un type que l'enum ne connaît plus le ferait
 * lever), par trois lectures bornées, et range le résultat sous la version des
 * séances du compte.
 */

/**
 * Pose, sans série ni séance, les trois records suivis d'un exercice.
 *
 * @param  array{poids?: float, repetitions?: float, un_rm?: float, volume?: float, jour?: string}  $valeurs
 */
function poserLesRecordsDe(User $user, Exercise $exercice, array $valeurs = []): void
{
    $jour = $valeurs['jour'] ?? '2026-11-10 09:30:00';

    foreach ([
        [PersonalRecordType::MaxWeight, $valeurs['poids'] ?? 100.0, $valeurs['repetitions'] ?? 5.0],
        [PersonalRecordType::Max1RM, $valeurs['un_rm'] ?? 116.67, 100.0],
        [PersonalRecordType::MaxVolumeSet, $valeurs['volume'] ?? 500.0, null],
    ] as [$type, $valeur, $secondaire]) {
        PersonalRecord::factory()->createQuietly([
            'user_id' => $user->id,
            'exercise_id' => $exercice->id,
            'workout_id' => null,
            'type' => $type,
            'value' => $valeur,
            'secondary_value' => $secondaire,
            'achieved_at' => $jour,
        ]);
    }
}

/**
 * @return list<string>
 */
function lecturesDeLAction(User $user): array
{
    /** @var list<string> $sql */
    $sql = [];
    DB::listen(function (QueryExecuted $query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    app(FetchRecordsAction::class)->execute($user);

    DB::getEventDispatcher()->forget(QueryExecuted::class);

    return $sql;
}

it('rend les trois records de chaque exercice avec leur jour, le plus récent d’abord', function (): void {
    $user = User::factory()->create();
    $squat = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Squat']);
    $tractions = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Tractions']);
    poserLesRecordsDe($user, $squat, ['poids' => 140.0, 'repetitions' => 3.0, 'un_rm' => 154.0, 'volume' => 420.0, 'jour' => '2026-11-02 08:00:00']);
    poserLesRecordsDe($user, $tractions, ['jour' => '2026-11-16 08:00:00']);
    Cache::flush();

    $page = app(FetchRecordsAction::class)->execute($user);

    expect($page['tronque'])->toBeFalse()
        ->and(array_column($page['records'], 'nom'))->toBe(['Tractions', 'Squat'])
        ->and($page['records'][1])->toBe([
            'exercice_id' => $squat->id,
            'nom' => 'Squat',
            'date' => '2026-11-02',
            'poids_max' => ['valeur' => 140.0, 'repetitions' => 3, 'date' => '2026-11-02'],
            'un_rm' => ['valeur' => 154.0, 'date' => '2026-11-02'],
            'volume_serie' => ['valeur' => 420.0, 'date' => '2026-11-02'],
        ]);
});

it('laisse vide le record qu’aucune série n’a établi', function (): void {
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id]);
    PersonalRecord::factory()->createQuietly([
        'user_id' => $user->id,
        'exercise_id' => $exercice->id,
        'workout_id' => null,
        'type' => PersonalRecordType::MaxWeight,
        'value' => 60,
        'secondary_value' => 8,
    ]);
    Cache::flush();

    $page = app(FetchRecordsAction::class)->execute($user);

    expect($page['records'])->toHaveCount(1)
        ->and($page['records'][0]['poids_max'])->not->toBeNull()
        ->and($page['records'][0]['un_rm'])->toBeNull()
        ->and($page['records'][0]['volume_serie'])->toBeNull();
});

it('ne rend rien d’un autre compte, ni ses exercices ni ses valeurs sur un exercice partagé', function (): void {
    $user = User::factory()->create();
    $autre = User::factory()->create();
    $partage = Exercise::factory()->create(['user_id' => null, 'name' => 'Développé couché']);
    $secret = Exercise::factory()->create(['user_id' => $autre->id, 'name' => 'Exercice de l’autre']);
    poserLesRecordsDe($user, $partage, ['poids' => 80.0, 'un_rm' => 90.0, 'volume' => 400.0, 'jour' => '2026-11-01 08:00:00']);
    poserLesRecordsDe($autre, $partage, ['poids' => 200.0, 'un_rm' => 250.0, 'volume' => 900.0, 'jour' => '2026-11-18 08:00:00']);
    poserLesRecordsDe($autre, $secret, ['poids' => 300.0]);
    Cache::flush();

    $page = app(FetchRecordsAction::class)->execute($user);

    expect($page['records'])->toHaveCount(1)
        ->and($page['records'][0]['nom'])->toBe('Développé couché')
        ->and($page['records'][0]['poids_max']['valeur'] ?? null)->toBe(80.0)
        ->and($page['records'][0]['un_rm']['valeur'] ?? null)->toBe(90.0)
        ->and($page['records'][0]['volume_serie']['valeur'] ?? null)->toBe(400.0)
        ->and($page['records'][0]['date'])->toBe('2026-11-01');
});

it('ne mélange pas les comptes dans le cache', function (): void {
    $user = User::factory()->create();
    $autre = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => null, 'name' => 'Rowing']);
    poserLesRecordsDe($user, $exercice, ['poids' => 70.0]);
    poserLesRecordsDe($autre, $exercice, ['poids' => 170.0]);
    Cache::flush();

    $action = app(FetchRecordsAction::class);

    expect($action->execute($autre)['records'][0]['poids_max']['valeur'] ?? null)->toBe(170.0)
        ->and($action->execute($user)['records'][0]['poids_max']['valeur'] ?? null)->toBe(70.0);
});

it('borne la page aux exercices dont le record est le plus récent, et le dit', function (): void {
    $user = User::factory()->create();

    foreach (range(1, FetchRecordsAction::LIMITE + 1) as $rang) {
        $exercice = Exercise::factory()->create(['user_id' => $user->id, 'name' => sprintf('Exercice %02d', $rang)]);
        PersonalRecord::factory()->createQuietly([
            'user_id' => $user->id,
            'exercise_id' => $exercice->id,
            'workout_id' => null,
            'achieved_at' => now()->subDays(200)->addDays($rang)->toDateTimeString(),
        ]);
    }

    Cache::flush();

    $page = app(FetchRecordsAction::class)->execute($user);

    expect($page['records'])->toHaveCount(FetchRecordsAction::LIMITE)
        ->and($page['tronque'])->toBeTrue()
        ->and($page['records'][0]['nom'])->toBe(sprintf('Exercice %02d', FetchRecordsAction::LIMITE + 1))
        ->and(array_column($page['records'], 'nom'))->not->toContain('Exercice 01');
});

it('ne dit pas tronquée une page qui tient pile dans la borne', function (): void {
    $user = User::factory()->create();

    foreach (range(1, FetchRecordsAction::LIMITE) as $rang) {
        PersonalRecord::factory()->createQuietly([
            'user_id' => $user->id,
            'exercise_id' => Exercise::factory()->create(['user_id' => $user->id])->id,
            'workout_id' => null,
        ]);
    }

    Cache::flush();

    $page = app(FetchRecordsAction::class)->execute($user);

    expect($page['records'])->toHaveCount(FetchRecordsAction::LIMITE)
        ->and($page['tronque'])->toBeFalse();
});

it('lit en trois requêtes, que le compte ait un exercice ou soixante, et en une seule, celle des noms, une fois en cache', function (int $exercices): void {
    $user = User::factory()->create();

    foreach (range(1, $exercices) as $rang) {
        poserLesRecordsDe($user, Exercise::factory()->create(['user_id' => $user->id]));
    }

    Cache::flush();

    $lectures = lecturesDeLAction($user);

    $relue = lecturesDeLAction($user);

    expect($lectures)->toHaveCount(3)
        ->and($relue)->toHaveCount(1)
        ->and($relue[0])->toContain('from `exercises`');
})->with([1, 5, FetchRecordsAction::LIMITE]);

it('ne lit qu’une fois, puis plus du tout, pour un compte sans record, et ne dit pas tronquée', function (): void {
    $user = User::factory()->create();
    Cache::flush();

    expect(lecturesDeLAction($user))->toHaveCount(1)
        ->and(lecturesDeLAction($user))->toBe([])
        ->and(app(FetchRecordsAction::class)->execute($user))->toBe(['records' => [], 'tronque' => false]);
});

it('ne relit pas, et ne lève pas, une ligne d’un type que l’application ne tient plus', function (): void {
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Curl']);
    poserLesRecordsDe($user, $exercice, ['poids' => 20.0]);

    $heritee = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Hérité']);

    foreach (['1RM', 'strength', 'MAX_1RM ', 'inconnu'] as $type) {
        DB::table('personal_records')->insert([
            'user_id' => $user->id,
            'exercise_id' => $heritee->id,
            'type' => $type,
            'value' => 999,
            'achieved_at' => '2026-11-19 10:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    Cache::flush();

    $page = app(FetchRecordsAction::class)->execute($user);

    expect(array_column($page['records'], 'nom'))->toBe(['Curl']);
});

it('ne compte pas dans la borne un exercice qui ne porte que des types que l’application ne tient plus', function (): void {
    $user = User::factory()->create();

    foreach (range(1, FetchRecordsAction::LIMITE) as $rang) {
        poserLesRecordsDe($user, Exercise::factory()->create(['user_id' => $user->id]), ['jour' => '2026-10-01 08:00:00']);
    }

    $heritee = Exercise::factory()->create(['user_id' => $user->id]);
    DB::table('personal_records')->insert([
        'user_id' => $user->id,
        'exercise_id' => $heritee->id,
        'type' => 'MAX_WEIGHT ',
        'value' => 999,
        'achieved_at' => '2026-11-19 10:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Cache::flush();

    $page = app(FetchRecordsAction::class)->execute($user);

    expect($page['records'])->toHaveCount(FetchRecordsAction::LIMITE)
        ->and($page['tronque'])->toBeFalse()
        ->and(array_column($page['records'], 'exercice_id'))->not->toContain($heritee->id);
});

it('n’écrit rien en base', function (): void {
    $user = User::factory()->create();
    poserLesRecordsDe($user, Exercise::factory()->create(['user_id' => $user->id]));
    Cache::flush();

    $ecritures = array_filter(
        lecturesDeLAction($user),
        static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $sql) === 1,
    );

    expect($ecritures)->toBe([]);
});

it('montre un record battu dès la lecture suivante, sans attendre l’échéance du cache', function (): void {
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $seance = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 100, 'reps' => 5, 'is_completed' => true, 'is_warmup' => false]);

    $action = app(FetchRecordsAction::class);

    expect($action->execute($user)['records'][0]['poids_max']['valeur'] ?? null)->toBe(100.0);

    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 120, 'reps' => 5, 'is_completed' => true, 'is_warmup' => false]);

    expect($action->execute($user)['records'][0]['poids_max']['valeur'] ?? null)->toBe(120.0);
});

it('oublie un record que la reconstruction retire', function (): void {
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $seance = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
    $serie = Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 100, 'reps' => 5, 'is_completed' => true, 'is_warmup' => false]);

    $action = app(FetchRecordsAction::class);

    expect($action->execute($user)['records'])->toHaveCount(1);

    Set::query()->whereKey($serie->id)->delete();
    app(PersonalRecordService::class)->recompute($user, $exercice->id);

    expect($action->execute($user)['records'])->toBe([]);
});

it('montre un record que la file écrit après la requête qui a validé la série', function (): void {
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $seance = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
    $serie = Set::factory()->createQuietly(['workout_line_id' => $ligne->id, 'weight' => 100, 'reps' => 5, 'is_completed' => true, 'is_warmup' => false]);

    $action = app(FetchRecordsAction::class);

    expect($action->execute($user)['records'])->toBe([]);

    (new SyncPersonalRecord(Set::query()->findOrFail($serie->id), $user))->handle(app(PersonalRecordService::class));

    expect($action->execute($user)['records'][0]['poids_max']['valeur'] ?? null)->toBe(100.0);
});

it('dit le nouveau nom d’un exercice renommé, et oublie un exercice supprimé, sans attendre l’échéance du cache', function (): void {
    $user = User::factory()->create();
    $gardee = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Squat']);
    $perdue = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Curl']);
    poserLesRecordsDe($user, $gardee, ['jour' => '2026-11-10 08:00:00']);
    poserLesRecordsDe($user, $perdue, ['jour' => '2026-11-09 08:00:00']);

    $action = app(FetchRecordsAction::class);

    expect(array_column($action->execute($user)['records'], 'nom'))->toBe(['Squat', 'Curl']);

    $gardee->forceFill(['name' => 'Squat arrière'])->saveQuietly();
    DB::table('exercises')->where('id', $perdue->id)->delete();

    expect(array_column($action->execute($user)['records'], 'nom'))->toBe(['Squat arrière']);
});

it('date un record du nouveau début de sa séance quand celui-ci change', function (): void {
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $seance = Workout::factory()->create(['user_id' => $user->id, 'started_at' => '2026-11-10 09:00:00', 'ended_at' => '2026-11-10 10:00:00']);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 100, 'reps' => 5, 'is_completed' => true, 'is_warmup' => false]);

    $action = app(FetchRecordsAction::class);

    expect($action->execute($user)['records'][0]['date'])->toBe('2026-11-10');

    $seance->update(['started_at' => '2026-11-03 09:00:00', 'ended_at' => '2026-11-03 10:00:00']);

    expect($action->execute($user)['records'][0]['date'])->toBe('2026-11-03');
});
