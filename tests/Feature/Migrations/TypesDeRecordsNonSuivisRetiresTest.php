<?php

declare(strict_types=1);

use App\Enums\PersonalRecordType;
use App\Models\Exercise;
use App\Models\NotificationPreference;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\PersonalRecordService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/*
 * Des records portent un type que l'application ne tient plus (#1811) : les
 * quatre types hérités que l'enum garde encore ('1RM', 'strength', 'cardio',
 * 'volume'), et toute valeur que l'enum ne connaît pas, dont celles que la
 * collation de la colonne (utf8mb4_unicode_ci, insensible à la casse et PAD
 * SPACE) confond avec un type suivi : 'MAX_WEIGHT', 'max_weight '. Le modèle ne
 * relit pas ces dernières (`ValueError` au cast), et le tableau de bord, la
 * page de séance, la synchronisation d'une série et la reconstruction des
 * records de l'exercice levaient avec lui. Les types hérités, eux, se relisent
 * tant que l'enum les garde : les retirer de l'enum ferait lever de même.
 * `2026_11_06_161554_retirer_les_records_d_un_type_non_suivi.php` retire
 * chacune de ces lignes, choisies octet par octet (`CAST(type AS BINARY)`),
 * et refait les records de leur exercice depuis ses séries, sans rien
 * annoncer, par `PersonalRecordService::retirerLesTypesNonSuivis()`. La
 * migration n'écrit aucun schéma : elle se joue dans la transaction du test,
 * sur la file synchrone, où les annonces partent dans la requête.
 */

/**
 * Joue `up()` de la migration, comme `migrate`.
 */
function typesNonSuivisMigrer(): void
{
    $migration = require database_path('migrations/2026_11_06_161554_retirer_les_records_d_un_type_non_suivi.php');
    assert($migration instanceof Migration && method_exists($migration, 'up'));

    $migration->up();
}

/**
 * Un exercice du compte, une ligne dans la séance et ses séries faites, par
 * les modèles : `Set::saved` établit ses records et les annonce.
 *
 * @param  list<array{0: float, 1: int}>  $series  Poids et répétitions de chaque série.
 */
function typesNonSuivisExercice(User $compte, Workout $seance, string $nom, string $sorte, array $series): Exercise
{
    $exercice = Exercise::factory()->create(['user_id' => $compte->id, 'name' => $nom, 'type' => $sorte]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);

    foreach ($series as [$poids, $repetitions]) {
        Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => $poids, 'reps' => $repetitions, 'is_warmup' => false, 'is_completed' => true]);
    }

    return $exercice;
}

/**
 * Un record d'un type donné, écrit en base sans série, comme les écrivait le
 * code d'avant.
 */
function typesNonSuivisPlanter(int $idCompte, int $idExercice, string $type, float $valeur): int
{
    return DB::table('personal_records')->insertGetId([
        'user_id' => $idCompte,
        'exercise_id' => $idExercice,
        'type' => $type,
        'value' => $valeur,
        'achieved_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Le record du type suivi, réécrit en base sous un autre type : l'index unique
 * (user_id, exercise_id, type), qui suit la collation, refuserait de les tenir
 * tous les deux.
 */
function typesNonSuivisRenommer(User $compte, Exercise $exercice, string $suivi, string $altere): void
{
    expect(DB::table('personal_records')
        ->where('user_id', $compte->id)
        ->where('exercise_id', $exercice->id)
        ->whereRaw('CAST(type AS BINARY) = ?', [$suivi])
        ->update(['type' => $altere]))->toBe(1);
}

/**
 * Un pratiquant qui a l'annonce de ses records activée, et une séance faite
 * hier, dont les séries ont établi leurs records par les modèles :
 *
 * - le squat, 100 kg × 5 et 110 kg × 1, auquel s'ajoutent en base un record
 *   de chacun des quatre types hérités et un d'un type inconnu ;
 * - le développé couché, 80 kg × 5, dont le poids maximum est écrit en
 *   capitales ('MAX_WEIGHT') ;
 * - le soulevé de terre, 140 kg × 3, dont le poids maximum porte une espace
 *   finale ('max_weight ') ;
 * - la course, qui n'a qu'un échauffement, sans record suivi, et un record
 *   'cardio'.
 *
 * Rien d'autre ne s'écarte de ses sources : le contrôle de cohérence ne
 * signale que ces records.
 *
 * @return array{compte: User, seance: Workout, squat: Exercise, couche: Exercise, terre: Exercise, course: Exercise}
 */
function typesNonSuivisScene(): array
{
    $compte = User::factory()->create();
    NotificationPreference::factory()->create([
        'user_id' => $compte->id,
        'type' => 'personal_record',
        'is_enabled' => true,
        'is_push_enabled' => false,
    ]);

    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => now()->subDay(), 'ended_at' => now()->subDay()->addHour()]);

    $squat = typesNonSuivisExercice($compte, $seance, 'Squat', 'strength', [[100, 5], [110, 1]]);
    $couche = typesNonSuivisExercice($compte, $seance, 'Développé couché', 'strength', [[80, 5]]);
    $terre = typesNonSuivisExercice($compte, $seance, 'Soulevé de terre', 'strength', [[140, 3]]);
    $course = typesNonSuivisExercice($compte, $seance, 'Course', 'cardio', []);

    Set::factory()->create([
        'workout_line_id' => WorkoutLine::query()->where('exercise_id', $course->id)->value('id'),
        'weight' => 20,
        'reps' => 10,
        'is_warmup' => true,
        'is_completed' => true,
    ]);

    foreach (['1RM' => 116.67, 'strength' => 110, 'cardio' => 45, 'volume' => 500, 'inconnu' => 1] as $type => $valeur) {
        typesNonSuivisPlanter($compte->id, $squat->id, $type, $valeur);
    }

    typesNonSuivisRenommer($compte, $couche, 'max_weight', 'MAX_WEIGHT');
    typesNonSuivisRenommer($compte, $terre, 'max_weight', 'max_weight ');
    typesNonSuivisPlanter($compte->id, $course->id, 'cardio', 30);

    return [
        'compte' => $compte->refresh(),
        'seance' => $seance,
        'squat' => $squat,
        'couche' => $couche,
        'terre' => $terre,
        'course' => $course,
    ];
}

/**
 * Le nombre de records dont le type, comparé octet par octet, n'est pas l'un
 * des types suivis.
 */
function typesNonSuivisRestants(): int
{
    return DB::table('personal_records')
        ->whereNotIn(DB::raw('CAST(type AS BINARY)'), PersonalRecordType::SUIVIS)
        ->count();
}

/**
 * Les records suivis de l'exercice, par type : valeur et série.
 *
 * @return array<string, array{valeur: float, serie: int|null}>
 */
function typesNonSuivisRecordsSuivis(User $compte, Exercise $exercice): array
{
    $records = [];

    foreach (PersonalRecord::query()->where('user_id', $compte->id)->where('exercise_id', $exercice->id)->get() as $record) {
        $records[$record->type->value] = ['valeur' => (float) $record->value, 'serie' => $record->set_id];
    }

    ksort($records);

    return $records;
}

/**
 * Ce que la meilleure série éligible donne pour chaque type suivi, sous la
 * même forme : ce qu'écrit la reconstruction.
 *
 * @return array<string, array{valeur: float, serie: int|null}>
 */
function typesNonSuivisAttendus(User $compte, Exercise $exercice): array
{
    $attendus = app(PersonalRecordService::class)->recordsAttendus($compte->id, $exercice->id);
    ksort($attendus);

    return $attendus;
}

/**
 * Les lignes des records de ces comptes, telles qu'en base, par identifiant.
 *
 * @param  list<int>  $idsComptes
 * @return array<array-key, array<mixed>>
 */
function typesNonSuivisLignes(array $idsComptes): array
{
    return DB::table('personal_records')
        ->whereIn('user_id', $idsComptes)
        ->orderBy('id')
        ->get()
        ->keyBy('id')
        ->map(fn (object $ligne): array => get_object_vars($ligne))
        ->all();
}

it('retire chaque record d’un type non suivi, et refait depuis ses séries chaque exercice qui en portait un, sans rien annoncer', function (): void {
    $scene = typesNonSuivisScene();
    $compte = $scene['compte'];

    // Un record suivi que plus aucune série ne donne, sur un exercice qui
    // porte des types hérités : la reconstruction le refait.
    DB::table('personal_records')->where('user_id', $compte->id)->where('exercise_id', $scene['squat']->id)->where('type', 'max_volume_set')->update(['value' => 9999]);

    // Les lignes que le modèle ne relit pas, comme le dit le contrôle.
    expect(typesNonSuivisRestants())->toBe(8);

    Notification::fake();
    typesNonSuivisMigrer();

    expect(typesNonSuivisRestants())->toBe(0);

    foreach (['squat', 'couche', 'terre', 'course'] as $exercice) {
        expect(typesNonSuivisRecordsSuivis($compte, $scene[$exercice]))->toBe(typesNonSuivisAttendus($compte, $scene[$exercice]));
    }

    // Les valeurs, en clair : le poids maximum recréé sous son type suivi, le
    // volume refait, et plus aucun record sur la course, qui n'a pas de série
    // qui compte.
    expect(typesNonSuivisRecordsSuivis($compte, $scene['couche'])['max_weight']['valeur'] ?? null)->toBe(80.0)
        ->and(typesNonSuivisRecordsSuivis($compte, $scene['terre'])['max_weight']['valeur'] ?? null)->toBe(140.0)
        ->and(typesNonSuivisRecordsSuivis($compte, $scene['squat'])['max_volume_set']['valeur'] ?? null)->toBe(500.0)
        ->and(DB::table('personal_records')->where('exercise_id', $scene['course']->id)->count())->toBe(0);

    // Recréer le poids maximum du développé couché aurait été annoncé par une
    // reconstruction des écritures de l'application (#2185) : la migration
    // répare, elle n'annonce rien.
    Notification::assertNothingSent();
});

it('écrit au journal le nombre de records retirés par type et leurs identifiants, sans valeur ni compte', function (): void {
    typesNonSuivisScene();
    $retires = DB::table('personal_records')
        ->whereNotIn(DB::raw('CAST(type AS BINARY)'), PersonalRecordType::SUIVIS)
        ->orderBy('id')
        ->pluck('id')
        ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
        ->all();

    $journal = Log::spy();
    typesNonSuivisMigrer();

    $journal->shouldHaveReceived('info')
        ->once()
        ->withArgs(static fn (string $message, array $contexte): bool => $message === 'Records d’un type que l’application ne tient plus retirés, leurs exercices refaits depuis leurs séries (#1811)'
            && $contexte === [
                'types' => ['1RM' => 1, 'MAX_WEIGHT' => 1, 'cardio' => 2, 'inconnu' => 1, 'max_weight ' => 1, 'strength' => 1, 'volume' => 1],
                'records' => $retires,
                'exercices_refaits' => 4,
                'exercices_sans_compte' => 0,
            ]);
    expect($retires)->toHaveCount(8);
});

it('ne touche pas aux exercices ni aux comptes qui ne portent aucun type non suivi', function (): void {
    $scene = typesNonSuivisScene();
    $compte = $scene['compte'];

    // Un autre exercice du même compte, et un autre compte, dont un record
    // s'écarte de ses séries : la migration ne répare que les exercices dont
    // elle retire un record, le reste demeure au contrôle de nuit et à
    // `--repair`.
    $tirage = typesNonSuivisExercice($compte, $scene['seance'], 'Tirage', 'strength', [[60, 8]]);
    DB::table('personal_records')->where('exercise_id', $tirage->id)->where('type', 'max_weight')->update(['value' => 999]);

    $autre = User::factory()->create();
    $seanceDeLAutre = Workout::factory()->create(['user_id' => $autre->id, 'started_at' => now()->subDays(2), 'ended_at' => now()->subDays(2)->addHour()]);
    $presse = typesNonSuivisExercice($autre, $seanceDeLAutre, 'Presse', 'strength', [[200, 10]]);
    DB::table('personal_records')->where('exercise_id', $presse->id)->where('type', 'max_1rm')->update(['value' => 777]);

    $intacts = array_filter(
        typesNonSuivisLignes([$compte->id, $autre->id]),
        static fn (array $ligne): bool => in_array($ligne['exercise_id'], [$tirage->id, $presse->id], true),
    );

    expect($intacts)->toHaveCount(6);

    typesNonSuivisMigrer();

    expect(typesNonSuivisRestants())->toBe(0)
        ->and(array_intersect_key(typesNonSuivisLignes([$compte->id, $autre->id]), $intacts))->toBe($intacts);
});

it('rejouée, ne trouve plus rien à retirer et n’écrit rien', function (): void {
    typesNonSuivisScene();
    typesNonSuivisMigrer();

    expect(typesNonSuivisRestants())->toBe(0);

    $lignes = DB::table('personal_records')->orderBy('id')->get()->map(fn (object $ligne): array => get_object_vars($ligne))->all();
    $journal = Log::spy();

    typesNonSuivisMigrer();

    expect(DB::table('personal_records')->orderBy('id')->get()->map(fn (object $ligne): array => get_object_vars($ligne))->all())->toBe($lignes);
    $journal->shouldNotHaveReceived('info');
});

it('laisse le contrôle de cohérence sans écart, type des records compris', function (): void {
    typesNonSuivisScene();

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain('7 type des records');

    typesNonSuivisMigrer();

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(0)
        ->expectsOutputToContain('OK type des records')
        ->expectsOutputToContain('Aucun écart');
});

it('rouvre le tableau de bord, que le dernier record écrit en capitales faisait tomber', function (): void {
    $this->withoutVite();
    $scene = typesNonSuivisScene();
    $compte = $scene['compte'];

    DB::table('personal_records')
        ->where('user_id', $compte->id)
        ->whereRaw('CAST(type AS BINARY) = ?', ['MAX_WEIGHT'])
        ->update(['achieved_at' => now()->addMinute()]);

    actingAs($compte)->get(route('dashboard'))->assertInternalServerError();

    typesNonSuivisMigrer();

    actingAs($compte)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Dashboard')
            ->has('recentPRs', 2));
});

/*
 * Un record dont le compte a disparu ne devrait pas exister : la clé
 * étrangère l'emporte avec le compte. Une base restaurée clés coupées, ou
 * passée par un serveur qui ne l'a pas vérifiée, peut en garder : la
 * migration le retire aussi, et il n'y a rien à reconstruire pour personne.
 */
it('retire aussi, sans rien reconstruire, le record d’un type non suivi dont le compte a disparu', function (): void {
    $exercice = Exercise::factory()->create();
    $absent = (int) DB::table('users')->max('id') + 1000;

    Schema::withoutForeignKeyConstraints(function () use ($absent, $exercice): void {
        typesNonSuivisPlanter($absent, $exercice->id, 'cardio', 30);
        typesNonSuivisPlanter($absent, $exercice->id, 'max_weight', 50);
    });

    $journal = Log::spy();
    typesNonSuivisMigrer();

    expect(typesNonSuivisRestants())->toBe(0)
        // Son record suivi reste au contrôle de nuit, qui le dit détaché.
        ->and(DB::table('personal_records')->where('user_id', $absent)->pluck('type')->all())->toBe(['max_weight']);

    $journal->shouldHaveReceived('info')
        ->once()
        ->withArgs(static fn (string $message, array $contexte): bool => $contexte['types'] === ['cardio' => 1]
            && $contexte['exercices_refaits'] === 0
            && $contexte['exercices_sans_compte'] === 1);
});

/*
 * Le retrait et la reconstruction se font sous le verrou des records de
 * l'exercice (#1984), comme toute écriture des records : une synchronisation
 * qui écrirait le poids maximum entre le retrait de 'MAX_WEIGHT' et la
 * reconstruction ferait refuser la seconde écriture par l'index unique.
 */
it('retire sous le verrou des records de l’exercice, et n’écrit rien tant qu’un autre le tient', function (): void {
    $scene = typesNonSuivisScene();
    $compte = $scene['compte'];
    $lignes = typesNonSuivisLignes([$compte->id]);

    $verrou = Cache::lock("records:{$compte->id}:{$scene['couche']->id}", 30);
    expect($verrou->get())->toBeTrue();

    Carbon::setTestNow(now());
    Sleep::fake(syncWithCarbon: true);

    try {
        expect(fn (): int => app(PersonalRecordService::class)->retirerLesTypesNonSuivis($compte, $scene['couche']->id))
            ->toThrow(LockTimeoutException::class);
    } finally {
        $verrou->release();
    }

    expect(typesNonSuivisLignes([$compte->id]))->toBe($lignes);

    expect(app(PersonalRecordService::class)->retirerLesTypesNonSuivis($compte, $scene['couche']->id))->toBe(1)
        ->and(typesNonSuivisRecordsSuivis($compte, $scene['couche']))->toBe(typesNonSuivisAttendus($compte, $scene['couche']));
});
