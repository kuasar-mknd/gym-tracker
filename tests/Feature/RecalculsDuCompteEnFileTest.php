<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Jobs\SyncPersonalRecord;
use App\Jobs\SyncUserAchievements;
use App\Jobs\SyncUserGoals;
use App\Models\Achievement;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Notifications\AchievementUnlocked;
use App\Services\PersonalRecordService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;

use function Pest\Laravel\actingAs;

/*
 * Un recalcul des objectifs ou des succès demandé pendant qu'un autre tourne
 * pour le même compte n'est plus perdu, et un verrou orphelin ne les bloque
 * plus pour de bon (#2105).
 *
 * `SyncUserGoals` et `SyncUserAchievements` étaient `ShouldBeUnique` sans
 * échéance : Laravel prend le verrou à l'envoi et ne le rend qu'après
 * `handle()`. Un envoi arrivé pendant le recalcul du même compte était écarté,
 * alors que le travail en cours avait déjà lu l'état d'avant ; et un travail
 * retiré de la file sans être traité gardait le verrou pour toujours.
 *
 * Les tests passent par une vraie file `database` et de vrais workers
 * (`Worker::process()`), avec les réglages de Horizon en production : un
 * second worker qui prend un travail pendant qu'un premier en traite un autre
 * joue deux processus, comme les trois de la production.
 *
 * Sous APP_ENV=testing, `Set::saved` écrit le record d'une série dans la
 * requête, avant d'envoyer les recalculs. En production, `SyncPersonalRecord`
 * l'écrit dans un worker, derrière eux : les cas « sur le chemin de
 * production » le jouent (`recalculEnFileCheminDeProduction()`).
 *
 * `SyncPersonalRecord` suit la même voie, par série et par compte (#2130). Il
 * était `ShouldBeUnique` sans échéance : la correction d'une série arrivée
 * pendant la synchronisation de ses records, qui avait déjà relu la série,
 * n'en mettait aucune nouvelle en file, et un travail vidé de la file bloquait
 * les records de la série pour toujours. Les cas dont le jeu de données nomme
 * « records d’une série » le jouent comme les deux recalculs ; ceux qui
 * passent par `recalculEnFilePendantLaLectureDesRecords()` arrêtent la
 * synchronisation sous le verrou des records de l'exercice, comme
 * `RecordsConcurrentsTest`.
 */

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Un worker de la file, comme un processus de Horizon : il tient dans le cache
 * le compte des exceptions et des plantages, comme `horizon:work`.
 */
function recalculEnFileUnWorker(): Worker
{
    $queue = app('queue');
    assert($queue instanceof QueueManager);

    $worker = new Worker($queue, app('events'), app(ExceptionHandler::class), static fn (): bool => false);
    $worker->setCache(app('cache.store'));

    return $worker;
}

/**
 * Les options du superviseur de Horizon (`config/horizon.php`) : un essai par
 * travail, sauf ce que le travail déclare lui-même, et son délai.
 */
function recalculEnFileOptionsDeHorizon(): WorkerOptions
{
    return new WorkerOptions(
        timeout: config()->integer('horizon.defaults.supervisor-1.timeout'),
        sleep: 0,
        maxTries: config()->integer('horizon.defaults.supervisor-1.tries'),
    );
}

/**
 * Réserve le prochain travail disponible de la file `database` et le traite
 * par `Worker::process()`, comme `queue:work --once`. L'exception d'un travail
 * en échec reste dans le résultat, là où le worker la rapporterait.
 *
 * @return array{travail: string, issue: string, exception: string|null}|null Null quand aucun travail n'est disponible.
 */
function recalculEnFileTraiterLeSuivant(Worker $worker): ?array
{
    $file = app('queue');
    assert($file instanceof QueueManager);
    $travail = $file->connection('database')->pop('default');

    if (! $travail instanceof Job) {
        return null;
    }

    $exception = null;

    try {
        $worker->process('database', $travail, recalculEnFileOptionsDeHorizon());
    } catch (Throwable $erreur) {
        $exception = $erreur::class;
    }

    return [
        'travail' => $travail->resolveName(),
        'issue' => match (true) {
            $travail->hasFailed() => 'en échec',
            $travail->isReleased() => 'remis en file',
            default => 'traité',
        },
        'exception' => $exception,
    ];
}

/**
 * Traite la file jusqu'à ce qu'elle soit vide. Un travail remis en file à plus
 * tard ne se reprend qu'à son heure : quand rien n'est disponible, l'horloge
 * avance d'une minute, bien au-delà de l'attente qu'un travail se donne.
 *
 * @return list<array{travail: string, issue: string, exception: string|null}>
 */
function recalculEnFileViderLaFile(Worker $worker): array
{
    $traites = [];

    foreach (range(1, 20) as $_tour) {
        if (DB::table('jobs')->doesntExist()) {
            break;
        }

        $resultat = recalculEnFileTraiterLeSuivant($worker);

        if ($resultat === null) {
            Carbon::setTestNow(now()->addMinute());

            continue;
        }

        $traites[] = $resultat;
    }

    return $traites;
}

/**
 * Traite la file comme un worker qui reprend chaque travail remis en file dès
 * que la file le rend : quand rien n'est disponible, l'horloge avance jusqu'à
 * l'heure où le prochain travail le redevient (`available_at`, une seconde
 * pleine), sans rien de plus.
 *
 * Chaque essai coûte au plus deux tours, l'attente de son heure puis son
 * traitement : la borne suit les essais que le travail permet, plus celui qui
 * échoue faute d'essais et le tour qui trouve la file vide. Un cas écrit sur
 * `ESSAIS_PERMIS` suit ainsi les constantes du trait. Une file qui garde des
 * travaux à la borne lève, au lieu de rendre une liste tronquée.
 *
 * @return list<array{travail: string, issue: string, exception: string|null}>
 */
function recalculEnFileReprendreDesQueRendu(Worker $worker, int $essaisPermis): array
{
    $traites = [];
    $tours = 2 * $essaisPermis + 2;

    foreach (range(1, $tours) as $_tour) {
        $disponibleA = DB::table('jobs')->min('available_at');

        if (! is_numeric($disponibleA)) {
            break;
        }

        $resultat = recalculEnFileTraiterLeSuivant($worker);

        if ($resultat === null) {
            Carbon::setTestNow(Carbon::createFromTimestamp((int) $disponibleA, date_default_timezone_get()));

            continue;
        }

        $traites[] = $resultat;
    }

    if (DB::table('jobs')->exists()) {
        throw new LogicException(sprintf('La file garde des travaux après %d tours, la borne pour %d essais permis.', $tours, $essaisPermis));
    }

    return $traites;
}

/**
 * Pose le verrou d'exécution du recalcul de ce compte, comme le laisse un
 * worker tué en plein recalcul, pour la durée donnée.
 */
function recalculEnFilePoserUnVerrouOrphelin(string $classe, User $user, int $secondes): void
{
    $travail = recalculEnFileLeTravail($classe, $user);
    $sansChevauchement = $travail->middleware()[0];

    expect(Cache::lock($sansChevauchement->getLockKey($travail), $secondes)->get())->toBeTrue();
}

/**
 * Les travaux de la file, réservés compris, dans l'ordre où ils y sont entrés.
 *
 * @return list<string>
 */
function recalculEnFileContenu(): array
{
    return array_values(DB::table('jobs')->orderBy('id')->pluck('payload')
        ->map(static function (mixed $charge): string {
            assert(is_string($charge));
            $decodee = json_decode($charge, true, flags: JSON_THROW_ON_ERROR);
            assert(is_array($decodee) && is_string($decodee['displayName'] ?? null));

            return $decodee['displayName'];
        })
        ->all());
}

/**
 * Un pratiquant, un exercice dans une séance, et un objectif « 100 kg » sur
 * cet exercice, posés pendant que la file est encore synchrone.
 *
 * @return array{0: User, 1: WorkoutLine, 2: Goal}
 */
function recalculEnFileObjectifDeCharge(): array
{
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $seance = Workout::factory()->create(['user_id' => $user->id]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
    $objectif = Goal::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => $exercice->id,
        'type' => GoalType::Weight,
        'start_value' => 0,
        'current_value' => 0,
        'target_value' => 100,
        'completed_at' => null,
    ]);

    return [$user, $ligne, $objectif];
}

/**
 * Valide une série de la ligne : son enregistrement tient les records, puis
 * met en file le recalcul des succès et celui des objectifs.
 */
function recalculEnFileValiderUneSerie(WorkoutLine $ligne, float $poids): Set
{
    return Set::factory()->create([
        'workout_line_id' => $ligne->id,
        'weight' => $poids,
        'reps' => 1,
        'is_warmup' => false,
        'is_completed' => true,
    ]);
}

/**
 * Le nom du travail que les workers traitent en ce moment, tenu à jour par
 * l'événement que chaque worker émet avant un travail.
 */
function recalculEnFileSuivreLeTravailEnCours(): Closure
{
    $enCours = null;

    Event::listen(JobProcessing::class, static function (JobProcessing $evenement) use (&$enCours): void {
        $enCours = $evenement->job->resolveName();
    });

    return static function () use (&$enCours): ?string {
        return $enCours;
    };
}

/**
 * Le travail que nomme un jeu de données, pour ce compte : un recalcul, ou la
 * synchronisation des records de sa série (`recalculEnFileUneSerieDe()`).
 */
function recalculEnFileLeTravail(string $classe, User $user): SyncUserGoals|SyncUserAchievements|SyncPersonalRecord
{
    return match ($classe) {
        SyncUserGoals::class => new SyncUserGoals($user),
        SyncUserAchievements::class => new SyncUserAchievements($user),
        SyncPersonalRecord::class => new SyncPersonalRecord(recalculEnFileUneSerieDe($user), $user),
        default => throw new LogicException('Ni les objectifs, ni les succès, ni les records : '.$classe),
    };
}

/**
 * Envoie le travail que nomme un jeu de données, pour ce compte, comme le font
 * les écouteurs : `dispatch()`, qui prend le verrou d'unicité.
 */
function recalculEnFileEnvoyer(string $classe, User $user): void
{
    dispatch(recalculEnFileLeTravail($classe, $user));
}

/**
 * La clé du verrou d'unicité que prenait, sans échéance, la version d'avant
 * le correctif de la classe : l'identifiant du compte seul pour un recalcul
 * (avant #2105), `sync_pr_{compte}_{série}` pour les records (avant #2130).
 * Seul l'identifiant change : le préfixe, qui nomme la classe, vient de
 * Laravel (`UniqueLock::getKey()`).
 */
function recalculEnFileAncienneCle(string $classe, User $user): string
{
    $travail = recalculEnFileLeTravail($classe, $user);
    $ancienIdentifiant = $travail instanceof SyncPersonalRecord
        ? 'sync_pr_'.$user->id.'_'.$travail->set->id
        : (string) $user->id;

    return substr(UniqueLock::getKey($travail), 0, -strlen($travail->uniqueId())).$ancienIdentifiant;
}

/**
 * La série que synchronise `SyncPersonalRecord` dans les cas qu'il partage
 * avec les deux recalculs : la dernière du compte, ou une série faite de
 * 100 kg posée sans évènement, qui ne met rien en file.
 */
function recalculEnFileUneSerieDe(User $user): Set
{
    $serie = Set::query()->where('user_id', $user->id)->orderByDesc('id')->first();

    if ($serie instanceof Set) {
        return $serie;
    }

    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $seance = Workout::factory()->createQuietly(['user_id' => $user->id]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);

    return Set::factory()->createQuietly([
        'workout_line_id' => $ligne->id,
        'user_id' => $user->id,
        'weight' => 100,
        'reps' => 1,
        'is_warmup' => false,
        'is_completed' => true,
    ]);
}

/**
 * Lance `$premier` dans une fibre et l'arrête à sa lecture des records, sous
 * le verrou des records de l'exercice, la série déjà relue, puis lance
 * `$pendant`. Chaque attente de ce verrou (`Sleep`, simulé) rend la main à la
 * fibre, qui termine ; sinon elle reprend après `$pendant`. Rend ce que rend
 * chacun.
 *
 * @return array{0: mixed, 1: mixed}
 */
function recalculEnFilePendantLaLectureDesRecords(Closure $premier, Closure $pendant): array
{
    $suspendue = false;
    $fibre = new Fiber($premier);

    DB::listen(static function (QueryExecuted $requete) use (&$suspendue, $fibre): void {
        if (! $suspendue && Fiber::getCurrent() === $fibre && str_starts_with($requete->sql, 'select * from `personal_records`')) {
            $suspendue = true;
            Fiber::suspend();
        }
    });

    Sleep::fake();
    Sleep::whenFakingSleep(static function () use ($fibre): void {
        if ($fibre->isSuspended()) {
            $fibre->resume();
        }
    });

    $fibre->start();

    expect($fibre->isSuspended())->toBeTrue('La synchronisation devait lire les records avant d’écrire.');

    $pendantLaLecture = $pendant();

    if ($fibre->isSuspended()) {
        $fibre->resume();
    }

    expect($fibre->isTerminated())->toBeTrue();

    return [$fibre->getReturn(), $pendantLaLecture];
}

/**
 * Le chemin des records en production : `Set::saved` met `SyncPersonalRecord`
 * en file, devant les deux recalculs, au lieu de l'exécuter dans la requête
 * comme sous APP_ENV=testing ou sur la base de la CI, gym_tracker_testing
 * (`AppServiceProvider::registerSetEvents()`). Seule la configuration que lit
 * cette condition change : la connexion, déjà ouverte, reste celle du test.
 */
function recalculEnFileCheminDeProduction(): void
{
    config(['app.env' => 'production', 'database.connections.mysql.database' => 'gym_tracker']);
}

/**
 * Le succès de charge « 100 kg », que débloque un record de poids maximal.
 */
function recalculEnFileSuccesDeCharge(): Achievement
{
    return Achievement::factory()->create(['type' => 'weight_record', 'threshold' => 100]);
}

/**
 * Un pratiquant à 800 kg soulevés au cumulé (une série de 200 kg × 4, qui
 * tient aussi les records de l'exercice) dans une séance en cours, puis le
 * succès « 1 000 kg soulevés », que lit `User::volumeSouleve()`, la somme des
 * volumes de séance.
 *
 * @return array{0: User, 1: WorkoutLine, 2: Achievement}
 */
function recalculEnFileCompteA800Kg(): array
{
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $seance = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 200, 'reps' => 4, 'is_warmup' => false, 'is_completed' => true]);
    $succes = Achievement::factory()->create(['type' => 'volume_total', 'threshold' => 1000]);

    expect($user->volumeSouleve())->toBe(800.0);

    return [$user, $ligne, $succes];
}

/**
 * Valide une série par l'API, comme la page de séance.
 */
function recalculEnFileValiderParLaRoute(User $user, WorkoutLine $ligne, float $poids, int $repetitions): void
{
    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $ligne->id,
            'weight' => $poids,
            'reps' => $repetitions,
            'is_warmup' => false,
            'is_completed' => true,
        ])
        ->assertCreated();
}

it('recalcule les objectifs d’une série validée pendant que le recalcul du même compte lit les records', function (): void {
    Notification::fake();
    [$user, $ligne, $objectif] = recalculEnFileObjectifDeCharge();

    config(['queue.default' => 'database']);
    recalculEnFileValiderUneSerie($ligne, 90);

    expect(recalculEnFileContenu())->toBe([SyncUserAchievements::class, SyncUserGoals::class]);

    $travailEnCours = recalculEnFileSuivreLeTravailEnCours();
    $fileApresB = null;

    DB::listen(static function (QueryExecuted $requete) use (&$fileApresB, $travailEnCours, $ligne): void {
        if ($fileApresB !== null || $travailEnCours() !== SyncUserGoals::class || ! str_contains($requete->sql, '`personal_records`')) {
            return;
        }

        $fileApresB = [];
        recalculEnFileValiderUneSerie($ligne, 110);
        $fileApresB = recalculEnFileContenu();
    });

    $traites = array_column(recalculEnFileViderLaFile(recalculEnFileUnWorker()), 'travail');

    $objectif->refresh();
    $record = PersonalRecord::query()
        ->where('user_id', $user->id)
        ->where('exercise_id', $ligne->exercise_id)
        ->where('type', 'max_weight')
        ->sole();

    expect($record->value)->toBe('110.00')
        ->and($objectif->current_value)->toBe(110.0)
        ->and($objectif->completed_at)->not->toBeNull()
        ->and($fileApresB)->toBe([SyncUserGoals::class, SyncUserAchievements::class, SyncUserGoals::class])
        ->and($traites)->toBe([SyncUserAchievements::class, SyncUserGoals::class, SyncUserAchievements::class, SyncUserGoals::class]);
});

it('ne fait jamais tourner ensemble deux recalculs des objectifs d’un même compte : le second attend le premier et lit l’état final', function (): void {
    Notification::fake();
    [, $ligne, $objectif] = recalculEnFileObjectifDeCharge();

    config(['queue.default' => 'database']);
    recalculEnFileValiderUneSerie($ligne, 90);

    $travailEnCours = recalculEnFileSuivreLeTravailEnCours();
    $pendantLePremier = null;

    DB::listen(static function (QueryExecuted $requete) use (&$pendantLePremier, $travailEnCours, $ligne): void {
        if ($pendantLePremier !== null || $travailEnCours() !== SyncUserGoals::class || ! str_contains($requete->sql, '`personal_records`')) {
            return;
        }

        $pendantLePremier = [];
        recalculEnFileValiderUneSerie($ligne, 110);

        // Deux autres workers prennent aussitôt les deux travaux que la série
        // vient de mettre en file, pendant que le premier recalcul tourne.
        $pendantLePremier = [
            recalculEnFileTraiterLeSuivant(recalculEnFileUnWorker()),
            recalculEnFileTraiterLeSuivant(recalculEnFileUnWorker()),
        ];
    });

    $traites = recalculEnFileViderLaFile(recalculEnFileUnWorker());

    $objectif->refresh();

    expect($objectif->current_value)->toBe(110.0)
        ->and($objectif->completed_at)->not->toBeNull()
        ->and($pendantLePremier)->toBe([
            ['travail' => SyncUserAchievements::class, 'issue' => 'traité', 'exception' => null],
            ['travail' => SyncUserGoals::class, 'issue' => 'remis en file', 'exception' => null],
        ])
        ->and($traites)->toBe([
            ['travail' => SyncUserAchievements::class, 'issue' => 'traité', 'exception' => null],
            ['travail' => SyncUserGoals::class, 'issue' => 'traité', 'exception' => null],
            ['travail' => SyncUserGoals::class, 'issue' => 'traité', 'exception' => null],
        ]);
});

it('ne fait jamais tourner ensemble deux recalculs des succès d’un même compte, et n’en perd aucun', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    Workout::withoutEvents(static fn (): Workout => Workout::factory()->create(['user_id' => $user->id]));
    $succes = Achievement::factory()->create(['type' => 'count', 'threshold' => 1]);

    config(['queue.default' => 'database']);
    SyncUserAchievements::dispatch($user);

    /** @var list<string> $journal */
    $journal = [];

    DB::beforeExecuting(static function (string $sql) use (&$journal): void {
        if (str_starts_with($sql, 'select `achievement_id` from `user_achievements`')) {
            $journal[] = 'lecture';
        } elseif (str_starts_with($sql, 'insert into `user_achievements`')) {
            $journal[] = 'écriture';
        }
    });

    $pendantLePremier = null;

    DB::listen(static function (QueryExecuted $requete) use (&$pendantLePremier, $user): void {
        if ($pendantLePremier !== null || ! str_starts_with($requete->sql, 'select `achievement_id` from `user_achievements`')) {
            return;
        }

        // Une autre écriture du compte demande un recalcul, qu'un second
        // worker prend aussitôt, pendant que le premier vient de lire les
        // succès déjà acquis.
        $pendantLePremier = [];
        SyncUserAchievements::dispatch($user);
        $pendantLePremier = recalculEnFileTraiterLeSuivant(recalculEnFileUnWorker());
    });

    $traites = recalculEnFileViderLaFile(recalculEnFileUnWorker());

    expect($journal)->toBe(['lecture', 'écriture', 'lecture'])
        ->and($pendantLePremier)->toBe(['travail' => SyncUserAchievements::class, 'issue' => 'remis en file', 'exception' => null])
        ->and($traites)->toBe([
            ['travail' => SyncUserAchievements::class, 'issue' => 'traité', 'exception' => null],
            ['travail' => SyncUserAchievements::class, 'issue' => 'traité', 'exception' => null],
        ])
        ->and(DB::table('user_achievements')->where('user_id', $user->id)->where('achievement_id', $succes->id)->count())->toBe(1);

    Notification::assertSentToTimes($user, AchievementUnlocked::class, 1);
});

it('recalcule les objectifs et les succès après le record qu’un worker écrit pendant que deux autres recalculent, sur le chemin de production', function (): void {
    Notification::fake();
    $succes = recalculEnFileSuccesDeCharge();
    [$user, $ligne, $objectif] = recalculEnFileObjectifDeCharge();

    config(['queue.default' => 'database']);
    recalculEnFileCheminDeProduction();
    recalculEnFileValiderUneSerie($ligne, 90);

    expect(recalculEnFileContenu())->toBe([SyncPersonalRecord::class, SyncUserAchievements::class, SyncUserGoals::class]);

    $travailEnCours = recalculEnFileSuivreLeTravailEnCours();
    $fileApresB = null;
    $pendantLeRecord = null;

    DB::listen(static function (QueryExecuted $requete) use (&$fileApresB, &$pendantLeRecord, $travailEnCours, $ligne): void {
        if (! str_contains($requete->sql, '`personal_records`')) {
            return;
        }

        if ($fileApresB === null && $travailEnCours() === SyncUserGoals::class) {
            $fileApresB = [];
            recalculEnFileValiderUneSerie($ligne, 110);
            $fileApresB = recalculEnFileContenu();

            return;
        }

        if ($fileApresB !== null && $pendantLeRecord === null && $travailEnCours() === SyncPersonalRecord::class) {
            // Le record de B n'est pas encore écrit : deux autres workers
            // prennent aussitôt les deux recalculs que la série a mis en file.
            $pendantLeRecord = [];
            $pendantLeRecord[] = recalculEnFileTraiterLeSuivant(recalculEnFileUnWorker());
            $pendantLeRecord[] = recalculEnFileTraiterLeSuivant(recalculEnFileUnWorker());
        }
    });

    $traites = array_column(recalculEnFileViderLaFile(recalculEnFileUnWorker()), 'travail');

    $objectif->refresh();
    $record = PersonalRecord::query()
        ->where('user_id', $user->id)
        ->where('exercise_id', $ligne->exercise_id)
        ->where('type', 'max_weight')
        ->sole();

    expect($fileApresB)->toBe([SyncUserGoals::class, SyncPersonalRecord::class, SyncUserAchievements::class, SyncUserGoals::class])
        ->and($pendantLeRecord)->toBe([
            ['travail' => SyncUserAchievements::class, 'issue' => 'traité', 'exception' => null],
            ['travail' => SyncUserGoals::class, 'issue' => 'traité', 'exception' => null],
        ])
        ->and($record->value)->toBe('110.00')
        ->and($objectif->current_value)->toBe(110.0)
        ->and($objectif->completed_at)->not->toBeNull()
        ->and(DB::table('user_achievements')->where('user_id', $user->id)->where('achievement_id', $succes->id)->count())->toBe(1)
        ->and($traites)->toBe([
            SyncPersonalRecord::class, SyncUserAchievements::class, SyncUserGoals::class,
            SyncPersonalRecord::class, SyncUserAchievements::class, SyncUserGoals::class,
        ])
        ->and(DB::table('jobs')->count())->toBe(0);

    Notification::assertSentToTimes($user, AchievementUnlocked::class, 1);
});

it('recalcule après le record de la dernière série quand les séries attendent la file avec leur recalcul, un seul worker, sur le chemin de production', function (): void {
    Notification::fake();
    $succes = recalculEnFileSuccesDeCharge();
    [$user, $ligne, $objectif] = recalculEnFileObjectifDeCharge();

    config(['queue.default' => 'database']);
    recalculEnFileCheminDeProduction();

    // La file hors ligne rejoue les séries l'une derrière l'autre, avant que le
    // worker ne prenne le premier travail : les recalculs de la seconde sont
    // écartés, puisque ceux de la première attendent encore.
    recalculEnFileValiderUneSerie($ligne, 90);
    recalculEnFileValiderUneSerie($ligne, 110);

    expect(recalculEnFileContenu())->toBe([SyncPersonalRecord::class, SyncUserAchievements::class, SyncUserGoals::class, SyncPersonalRecord::class]);

    $traites = array_column(recalculEnFileViderLaFile(recalculEnFileUnWorker()), 'travail');

    $objectif->refresh();

    expect($objectif->current_value)->toBe(110.0)
        ->and($objectif->completed_at)->not->toBeNull()
        ->and(DB::table('user_achievements')->where('user_id', $user->id)->where('achievement_id', $succes->id)->count())->toBe(1)
        ->and($traites)->toBe([
            SyncPersonalRecord::class, SyncUserAchievements::class, SyncUserGoals::class,
            SyncPersonalRecord::class, SyncUserAchievements::class, SyncUserGoals::class,
        ])
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('ne renvoie aucun recalcul quand le record s’écrit dans la requête ou par un appel direct', function (): void {
    Notification::fake();
    [$user, $ligne] = recalculEnFileObjectifDeCharge();

    /** @var list<string> $lances */
    $lances = [];
    Event::listen(JobProcessing::class, static function (JobProcessing $evenement) use (&$lances): void {
        $lances[] = $evenement->job->resolveName();
    });

    // La file synchrone des tests : `Set::saved` lance le travail des records
    // dans la requête, puis envoie lui-même les deux recalculs, une fois.
    $serie = recalculEnFileValiderUneSerie($ligne, 110);

    expect($lances)->toBe([SyncPersonalRecord::class, SyncUserAchievements::class, SyncUserGoals::class]);

    Queue::fake();
    new SyncPersonalRecord($serie, $user)->handle(app(PersonalRecordService::class));

    Queue::assertNothingPushed();
});

it('synchronise les records de la valeur corrigée quand la série est corrigée pendant la synchronisation de ses records, sur le chemin de production', function (): void {
    Notification::fake();
    $succes = recalculEnFileSuccesDeCharge();
    [$user, $ligne, $objectif] = recalculEnFileObjectifDeCharge();

    // Le record en place, 95 kg, tenu par une autre série et écrit dans la
    // requête : l'objectif « 100 kg » n'est pas atteint.
    recalculEnFileValiderUneSerie($ligne, 95);

    config(['queue.default' => 'database']);
    recalculEnFileCheminDeProduction();

    // 90 kg, sous le record : la série n'en tient aucun.
    $serie = recalculEnFileValiderUneSerie($ligne, 90);

    expect(recalculEnFileContenu())->toBe([SyncPersonalRecord::class, SyncUserAchievements::class, SyncUserGoals::class]);

    [$synchronisation, $fileApresLaCorrection] = recalculEnFilePendantLaLectureDesRecords(
        static fn (): ?array => recalculEnFileTraiterLeSuivant(recalculEnFileUnWorker()),
        static function () use ($serie): array {
            // La faute de frappe corrigée aussitôt, pendant que la
            // synchronisation compare les records à la série relue à 90 kg.
            // La requête attend le verrou des records, puis ne reconstruit
            // que ceux que la série tient : aucun.
            Set::query()->findOrFail($serie->id)->update(['weight' => 120]);

            return recalculEnFileContenu();
        },
    );

    $traites = array_column(recalculEnFileViderLaFile(recalculEnFileUnWorker()), 'travail');

    $objectif->refresh();
    $record = PersonalRecord::query()
        ->where('user_id', $user->id)
        ->where('exercise_id', $ligne->exercise_id)
        ->where('type', 'max_weight')
        ->sole();

    expect($record->value)->toBe('120.00')
        ->and($record->set_id)->toBe($serie->id)
        ->and($objectif->current_value)->toBe(120.0)
        ->and($objectif->completed_at)->not->toBeNull()
        ->and(DB::table('user_achievements')->where('user_id', $user->id)->where('achievement_id', $succes->id)->count())->toBe(1)
        ->and($synchronisation)->toBe(['travail' => SyncPersonalRecord::class, 'issue' => 'traité', 'exception' => null])
        ->and($fileApresLaCorrection)->toBe([SyncUserAchievements::class, SyncUserGoals::class, SyncPersonalRecord::class])
        ->and($traites)->toBe([
            SyncUserAchievements::class, SyncUserGoals::class,
            SyncPersonalRecord::class, SyncUserAchievements::class, SyncUserGoals::class,
        ])
        ->and(DB::table('jobs')->count())->toBe(0);

    Notification::assertSentToTimes($user, AchievementUnlocked::class, 1);
});

it('ne fait jamais tourner ensemble deux synchronisations des records d’une même série, et laisse tourner celle d’une autre série du compte', function (): void {
    Notification::fake();
    [$user, $ligne] = recalculEnFileObjectifDeCharge();
    $serie = recalculEnFileValiderUneSerie($ligne, 90);
    $autreExercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $autreLigne = WorkoutLine::factory()->create(['workout_id' => $ligne->workout_id, 'exercise_id' => $autreExercice->id]);
    $autreSerie = recalculEnFileValiderUneSerie($autreLigne, 60);

    config(['queue.default' => 'database']);
    recalculEnFileCheminDeProduction();
    SyncPersonalRecord::dispatch($serie, $user);

    [$premiere, $pendantLaPremiere] = recalculEnFilePendantLaLectureDesRecords(
        static fn (): ?array => recalculEnFileTraiterLeSuivant(recalculEnFileUnWorker()),
        static function () use ($serie, $autreSerie, $user): array {
            // Deux envois pendant la première synchronisation, la même série
            // puis une autre série du compte, que deux autres workers
            // prennent aussitôt.
            SyncPersonalRecord::dispatch($serie, $user);
            SyncPersonalRecord::dispatch($autreSerie, $user);

            return [
                recalculEnFileTraiterLeSuivant(recalculEnFileUnWorker()),
                recalculEnFileTraiterLeSuivant(recalculEnFileUnWorker()),
            ];
        },
    );

    $traites = recalculEnFileViderLaFile(recalculEnFileUnWorker());

    expect($premiere)->toBe(['travail' => SyncPersonalRecord::class, 'issue' => 'traité', 'exception' => null])
        ->and($pendantLaPremiere)->toBe([
            ['travail' => SyncPersonalRecord::class, 'issue' => 'remis en file', 'exception' => null],
            ['travail' => SyncPersonalRecord::class, 'issue' => 'traité', 'exception' => null],
        ])
        ->and($traites)->toBe([
            ['travail' => SyncUserAchievements::class, 'issue' => 'traité', 'exception' => null],
            ['travail' => SyncUserGoals::class, 'issue' => 'traité', 'exception' => null],
            ['travail' => SyncPersonalRecord::class, 'issue' => 'traité', 'exception' => null],
            ['travail' => SyncUserAchievements::class, 'issue' => 'traité', 'exception' => null],
            ['travail' => SyncUserGoals::class, 'issue' => 'traité', 'exception' => null],
        ]);
});

it('tient un verrou d’unicité par série : la synchronisation qui attend pour une série n’écarte pas celle d’une autre série du compte', function (): void {
    [$user, $ligne] = recalculEnFileObjectifDeCharge();
    $premiere = recalculEnFileValiderUneSerie($ligne, 90);
    $seconde = recalculEnFileValiderUneSerie($ligne, 80);

    $file = Queue::fake();

    SyncPersonalRecord::dispatch($premiere, $user);
    SyncPersonalRecord::dispatch($seconde, $user);
    SyncPersonalRecord::dispatch($premiere, $user);

    $series = $file->pushed(SyncPersonalRecord::class)->map(static function (mixed $travail): int {
        assert($travail instanceof SyncPersonalRecord);

        return $travail->set->id;
    })->all();

    expect($series)->toBe([$premiere->id, $seconde->id]);
});

it('met en file le travail que retenait, sans échéance, un verrou d’unicité pris sous l’ancienne clé', function (string $classe): void {
    $user = User::factory()->create();
    config(['queue.default' => 'database']);

    // Le verrou qu'un travail vidé de la file a pu laisser avant le correctif
    // de sa classe (#2105 pour les recalculs, #2130 pour les records) : pris à
    // l'envoi sous la clé d'alors, sans échéance, il ne se rendait jamais.
    expect(Cache::lock(recalculEnFileAncienneCle($classe, $user))->get())->toBeTrue();

    recalculEnFileEnvoyer($classe, $user);

    expect(recalculEnFileContenu())->toBe([$classe]);
})->with([
    'objectifs' => [SyncUserGoals::class],
    'succès' => [SyncUserAchievements::class],
    'records d’une série' => [SyncPersonalRecord::class],
]);

it('tient un verrou d’unicité par compte : le recalcul qui attend pour un compte n’écarte pas celui d’un autre', function (string $classe): void {
    $premier = User::factory()->create();
    $second = User::factory()->create();

    // Le verrou d'unicité se prend à l'envoi, quel que soit le pilote de la
    // file : la file simulée rend les travaux mis en file, compte compris.
    $file = Queue::fake();

    $classe::dispatch($premier);
    $classe::dispatch($second);
    $classe::dispatch($premier);

    $comptes = $file->pushed($classe)->map(static function (mixed $travail): int {
        assert($travail instanceof SyncUserGoals || $travail instanceof SyncUserAchievements);

        return (int) $travail->user->id;
    })->all();

    expect($comptes)->toBe([$premier->id, $second->id]);
})->with([
    'objectifs' => [SyncUserGoals::class],
    'succès' => [SyncUserAchievements::class],
]);

it('met en file le recalcul envoyé trois jours après un vidage de la file', function (string $classe): void {
    $user = User::factory()->create();
    config(['queue.default' => 'database']);

    recalculEnFileEnvoyer($classe, $user);
    expect(DB::table('jobs')->count())->toBe(1);

    Artisan::call('queue:clear', ['connection' => 'database', '--force' => true]);
    expect(DB::table('jobs')->count())->toBe(0);

    $this->travel(3)->days();
    recalculEnFileEnvoyer($classe, $user);

    expect(recalculEnFileContenu())->toBe([$classe]);
})->with([
    'objectifs' => [SyncUserGoals::class],
    'succès' => [SyncUserAchievements::class],
    'records d’une série' => [SyncPersonalRecord::class],
]);

it('écarte un doublon tant que le verrou d’un recalcul jamais traité tient, et l’accepte à son échéance', function (string $classe): void {
    $this->travelTo('2026-10-23 10:00:00');
    $user = User::factory()->create();
    config(['queue.default' => 'database']);

    recalculEnFileEnvoyer($classe, $user);
    Artisan::call('queue:clear', ['connection' => 'database', '--force' => true]);

    $echeance = recalculEnFileLeTravail($classe, $user)->uniqueFor();

    $this->travel($echeance - 1)->seconds();
    recalculEnFileEnvoyer($classe, $user);
    expect(DB::table('jobs')->count())->toBe(0);

    $this->travel(1)->seconds();
    recalculEnFileEnvoyer($classe, $user);
    expect(recalculEnFileContenu())->toBe([$classe]);
})->with([
    'objectifs' => [SyncUserGoals::class],
    'succès' => [SyncUserAchievements::class],
    'records d’une série' => [SyncPersonalRecord::class],
]);

it('fait échouer au premier essai un recalcul qui lève, sans verrou laissé derrière lui', function (string $classe, string $lecture): void {
    Notification::fake();
    $user = User::factory()->create();
    config(['queue.default' => 'database']);
    $leve = false;

    DB::beforeExecuting(static function (string $sql) use (&$leve, $lecture): void {
        if (! $leve && str_starts_with($sql, $lecture)) {
            $leve = true;

            throw new RuntimeException('base indisponible');
        }
    });

    recalculEnFileEnvoyer($classe, $user);
    $worker = recalculEnFileUnWorker();

    expect(recalculEnFileTraiterLeSuivant($worker))->toBe(['travail' => $classe, 'issue' => 'en échec', 'exception' => RuntimeException::class])
        ->and(DB::table('jobs')->count())->toBe(0);

    // Ni le verrou d'unicité ni celui de l'exécution ne survivent à l'échec :
    // l'écriture suivante remet un recalcul en file, et il tourne.
    recalculEnFileEnvoyer($classe, $user);

    expect(recalculEnFileTraiterLeSuivant($worker))->toBe(['travail' => $classe, 'issue' => 'traité', 'exception' => null]);
})->with([
    'objectifs' => [SyncUserGoals::class, 'select * from `goals`'],
    'succès' => [SyncUserAchievements::class, 'select `achievement_id` from `user_achievements`'],
    'records d’une série' => [SyncPersonalRecord::class, 'select * from `personal_records`'],
]);

it('attend la fin du verrou d’un worker tué pris en cours de seconde, même repris dès que la file le rend', function (string $classe): void {
    Notification::fake();
    $user = User::factory()->create();
    config(['queue.default' => 'database']);

    $this->travelTo(Carbon::parse('2026-10-23 10:00:00.500'));
    recalculEnFilePoserUnVerrouOrphelin($classe, $user, recalculEnFileLeTravail($classe, $user)::VERROU_D_EXECUTION);

    // Le recalcul suivant du compte est pris dans la même seconde. La file
    // le rend à la seconde pleine : son premier retour ne dure que 4,2 s, et
    // chacun des suivants 5 s, jusqu'au 25e, à 10:02:00, une demi-seconde
    // avant la fin du verrou.
    $this->travelTo(Carbon::parse('2026-10-23 10:00:00.800'));
    $classe::dispatch($user);

    $issues = recalculEnFileReprendreDesQueRendu(recalculEnFileUnWorker(), recalculEnFileLeTravail($classe, $user)::ESSAIS_PERMIS);

    expect(array_column($issues, 'issue'))->toBe([...array_fill(0, 25, 'remis en file'), 'traité'])
        ->and($issues[25])->toBe(['travail' => $classe, 'issue' => 'traité', 'exception' => null])
        ->and(now()->format('H:i:s.v'))->toBe('10:02:05.000');
})->with([
    'objectifs' => [SyncUserGoals::class],
    'succès' => [SyncUserAchievements::class],
]);

it('rend le verrou d’unicité d’un recalcul qui épuise ses essais sans avoir commencé, et l’envoi suivant part aussitôt', function (string $classe): void {
    Notification::fake();
    $user = User::factory()->create();
    config(['queue.default' => 'database']);
    $this->travelTo('2026-10-23 10:00:00');

    // Un verrou d'exécution qui tient plus que tous les essais du recalcul.
    recalculEnFilePoserUnVerrouOrphelin($classe, $user, 3600);
    recalculEnFileEnvoyer($classe, $user);

    $essais = recalculEnFileLeTravail($classe, $user)::ESSAIS_PERMIS;
    $issues = recalculEnFileReprendreDesQueRendu(recalculEnFileUnWorker(), $essais);

    expect($issues)->toHaveCount($essais + 1)
        ->and(array_unique(array_column(array_slice($issues, 0, $essais), 'issue')))->toBe(['remis en file'])
        ->and($issues[$essais])->toBe(['travail' => $classe, 'issue' => 'en échec', 'exception' => MaxAttemptsExceededException::class])
        ->and(DB::table('jobs')->count())->toBe(0);

    // Bien avant l'échéance du verrou d'unicité, la prochaine écriture du
    // compte remet un recalcul en file.
    recalculEnFileEnvoyer($classe, $user);

    expect(recalculEnFileContenu())->toBe([$classe]);
})->with([
    'objectifs' => [SyncUserGoals::class],
    'succès' => [SyncUserAchievements::class],
    'records d’une série' => [SyncPersonalRecord::class],
]);

it('ne rend pas, en échouant, le verrou d’unicité d’un envoi plus récent', function (string $classe, string $lecture): void {
    Notification::fake();
    $user = User::factory()->create();
    config(['queue.default' => 'database']);
    $this->travelTo('2026-10-23 10:00:00');

    recalculEnFileEnvoyer($classe, $user);
    $this->travel(recalculEnFileLeTravail($classe, $user)->uniqueFor())->seconds();
    recalculEnFileEnvoyer($classe, $user);

    expect(recalculEnFileContenu())->toBe([$classe, $classe]);

    $leve = false;
    DB::beforeExecuting(static function (string $sql) use (&$leve, $lecture): void {
        if (! $leve && str_starts_with($sql, $lecture)) {
            $leve = true;

            throw new RuntimeException('base indisponible');
        }
    });

    // Le plus ancien échoue : le verrou d'unicité appartient au plus récent,
    // qui attend encore, et l'envoi suivant reste écarté.
    expect(recalculEnFileTraiterLeSuivant(recalculEnFileUnWorker()))->toBe(['travail' => $classe, 'issue' => 'en échec', 'exception' => RuntimeException::class]);

    recalculEnFileEnvoyer($classe, $user);

    expect(recalculEnFileContenu())->toBe([$classe]);
})->with([
    'objectifs' => [SyncUserGoals::class, 'select * from `goals`'],
    'succès' => [SyncUserAchievements::class, 'select `achievement_id` from `user_achievements`'],
    'records d’une série' => [SyncPersonalRecord::class, 'select * from `personal_records`'],
]);

it('dit au worker de rejouer un recalcul qui attend son tour, et jamais celui qui échoue ou plante', function (string $classe): void {
    $user = User::factory()->create();
    config(['queue.default' => 'database']);
    recalculEnFileEnvoyer($classe, $user);

    $charge = DB::table('jobs')->sole()->payload;
    assert(is_string($charge));
    $charge = json_decode($charge, true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($charge));

    $travail = recalculEnFileLeTravail($classe, $user);
    $middlewares = $travail->middleware();
    expect($middlewares)->toHaveCount(1);
    $sansChevauchement = $middlewares[0];
    expect($sansChevauchement)->toBeInstanceOf(WithoutOverlapping::class);
    $attente = $sansChevauchement->releaseAfter;
    assert(is_int($attente));

    // La clé de l'exécution est celle du verrou d'unicité : le compte pour un
    // recalcul, la série du compte pour ses records.
    expect($sansChevauchement->key)->toBe($travail->uniqueId())
        ->and($sansChevauchement->shareKey)->toBeFalse()
        ->and($attente)->toBeGreaterThan(0)
        // Le premier essai, un retour par attente pendant la vie du verrou, et
        // un de plus pour la seconde pleine où la file rend le travail.
        ->and($charge['maxTries'])->toBe(intdiv($sansChevauchement->expiresAfter, $attente) + 2)
        ->and($charge['maxExceptions'])->toBe(1)
        ->and($charge['countCrashesAsExceptions'])->toBeTrue()
        ->and($charge['backoff'])->toBeNull()
        ->and($charge['retryUntil'])->toBeNull()
        ->and($travail->uniqueFor())->toBeGreaterThanOrEqual($sansChevauchement->expiresAfter);

    // Le verrou de l'exécution survit au délai que le worker laisse à un
    // travail, sous chaque superviseur qui sert la file des recalculs, dans
    // chaque environnement : un recalcul qui tourne jusqu'à ce délai le garde
    // jusqu'au bout. Le délai que déclare le travail (`#[Timeout]`, `$timeout`,
    // qui arrive dans la charge) l'emporte sur celui du superviseur
    // (`Worker::timeoutForJob()`), et un délai nul n'en pose aucun : le verrou
    // expirerait alors sous un recalcul qui tourne encore. Le plan est celui
    // que Horizon déploie, chaque superviseur des défauts fusionné par son nom
    // avec celui de l'environnement.
    $delaiDuTravail = $charge['timeout'];
    assert($delaiDuTravail === null || is_int($delaiDuTravail));
    $superviseursDeLaFile = 0;

    foreach (ProvisioningPlan::get('recalculs')->parsed as $superviseurs) {
        assert(is_iterable($superviseurs));

        foreach ($superviseurs as $options) {
            assert($options instanceof SupervisorOptions && is_string($options->queue) && is_int($options->timeout));

            if (! in_array(config('queue.connections.'.$options->connection.'.queue'), explode(',', $options->queue), true)) {
                continue;
            }

            $superviseursDeLaFile++;
            $delai = $delaiDuTravail ?? $options->timeout;

            expect($delai)->toBeGreaterThan(0)
                ->and($sansChevauchement->expiresAfter)->toBeGreaterThan($delai);
        }
    }

    expect($superviseursDeLaFile)->toBeGreaterThan(0);
})->with([
    'objectifs' => [SyncUserGoals::class],
    'succès' => [SyncUserAchievements::class],
    'records d’une série' => [SyncPersonalRecord::class],
]);

it('débloque le succès du volume cumulé dès la série validée qui franchit son seuil : le volume de la séance s’écrit avant les recalculs', function (): void {
    Notification::fake();
    [$user, $ligne, $succes] = recalculEnFileCompteA800Kg();

    /** @var list<string> $journal */
    $journal = [];

    DB::listen(static function (QueryExecuted $requete) use (&$journal): void {
        if (str_starts_with($requete->sql, 'update `workouts` set `workout_volume`')) {
            $journal[] = 'écriture du volume de la séance';
        } elseif (str_starts_with($requete->sql, 'select sum(`workout_volume`)')) {
            $journal[] = 'lecture du volume cumulé';
        }
    });

    // La file synchrone des tests : les recalculs tournent dans la requête,
    // au moment de leur envoi.
    recalculEnFileValiderParLaRoute($user, $ligne, 100, 2);
    $journalDeLaRequete = $journal;

    expect($user->volumeSouleve())->toBe(1000.0)
        ->and(DB::table('user_achievements')->where('user_id', $user->id)->where('achievement_id', $succes->id)->count())->toBe(1)
        ->and($journalDeLaRequete)->toBe(['écriture du volume de la séance', 'lecture du volume cumulé']);

    Notification::assertSentToTimes($user, AchievementUnlocked::class, 1);
});

it('débloque le succès du volume cumulé quand un worker vide la file pendant que la requête écrit le volume de la séance, sur le chemin de production', function (): void {
    Notification::fake();
    [$user, $ligne, $succes] = recalculEnFileCompteA800Kg();

    config(['queue.default' => 'database']);
    recalculEnFileCheminDeProduction();

    $fileAuVolume = null;
    $pendantLeVolume = null;

    DB::listen(static function (QueryExecuted $requete) use (&$fileAuVolume, &$pendantLeVolume): void {
        if ($pendantLeVolume !== null || ! str_starts_with($requete->sql, 'select `workout_volume` from `workouts`') || ! str_contains($requete->sql, 'for update')) {
            return;
        }

        // La requête relit sous verrou le volume de la séance, qu'elle n'a pas
        // encore écrit : un worker prend tout ce que la file lui offre.
        $pendantLeVolume = [];
        $fileAuVolume = recalculEnFileContenu();
        $pendantLeVolume = array_column(recalculEnFileViderLaFile(recalculEnFileUnWorker()), 'travail');
    });

    recalculEnFileValiderParLaRoute($user, $ligne, 100, 2);

    $apresLaRequete = array_column(recalculEnFileViderLaFile(recalculEnFileUnWorker()), 'travail');

    expect($user->volumeSouleve())->toBe(1000.0)
        ->and(DB::table('user_achievements')->where('user_id', $user->id)->where('achievement_id', $succes->id)->count())->toBe(1)
        ->and($fileAuVolume)->toBe([SyncPersonalRecord::class])
        ->and($pendantLeVolume)->toBe([SyncPersonalRecord::class, SyncUserAchievements::class, SyncUserGoals::class])
        ->and($apresLaRequete)->toBe([SyncUserAchievements::class, SyncUserGoals::class])
        ->and(DB::table('jobs')->count())->toBe(0);

    Notification::assertSentToTimes($user, AchievementUnlocked::class, 1);
});
