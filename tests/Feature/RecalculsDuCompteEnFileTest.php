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
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

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
 * Le recalcul que nomme un jeu de données, pour ce compte.
 */
function recalculEnFileLeTravail(string $classe, User $user): SyncUserGoals|SyncUserAchievements
{
    return match ($classe) {
        SyncUserGoals::class => new SyncUserGoals($user),
        SyncUserAchievements::class => new SyncUserAchievements($user),
        default => throw new LogicException('Ni les objectifs ni les succès : '.$classe),
    };
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
            $pendantLeRecord = [
                recalculEnFileTraiterLeSuivant(recalculEnFileUnWorker()),
                recalculEnFileTraiterLeSuivant(recalculEnFileUnWorker()),
            ];
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

it('met en file le recalcul envoyé trois jours après un vidage de la file', function (string $classe): void {
    $user = User::factory()->create();
    config(['queue.default' => 'database']);

    $classe::dispatch($user);
    expect(DB::table('jobs')->count())->toBe(1);

    Artisan::call('queue:clear', ['connection' => 'database', '--force' => true]);
    expect(DB::table('jobs')->count())->toBe(0);

    $this->travel(3)->days();
    $classe::dispatch($user);

    expect(recalculEnFileContenu())->toBe([$classe]);
})->with([
    'objectifs' => [SyncUserGoals::class],
    'succès' => [SyncUserAchievements::class],
]);

it('écarte un doublon tant que le verrou d’un recalcul jamais traité tient, et l’accepte à son échéance', function (string $classe): void {
    $this->travelTo('2026-10-23 10:00:00');
    $user = User::factory()->create();
    config(['queue.default' => 'database']);

    $classe::dispatch($user);
    Artisan::call('queue:clear', ['connection' => 'database', '--force' => true]);

    $echeance = recalculEnFileLeTravail($classe, $user)->uniqueFor();

    $this->travel($echeance - 1)->seconds();
    $classe::dispatch($user);
    expect(DB::table('jobs')->count())->toBe(0);

    $this->travel(1)->seconds();
    $classe::dispatch($user);
    expect(recalculEnFileContenu())->toBe([$classe]);
})->with([
    'objectifs' => [SyncUserGoals::class],
    'succès' => [SyncUserAchievements::class],
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

    $classe::dispatch($user);
    $worker = recalculEnFileUnWorker();

    expect(recalculEnFileTraiterLeSuivant($worker))->toBe(['travail' => $classe, 'issue' => 'en échec', 'exception' => RuntimeException::class])
        ->and(DB::table('jobs')->count())->toBe(0);

    // Ni le verrou d'unicité ni celui de l'exécution ne survivent à l'échec :
    // l'écriture suivante remet un recalcul en file, et il tourne.
    $classe::dispatch($user);

    expect(recalculEnFileTraiterLeSuivant($worker))->toBe(['travail' => $classe, 'issue' => 'traité', 'exception' => null]);
})->with([
    'objectifs' => [SyncUserGoals::class, 'select * from `goals`'],
    'succès' => [SyncUserAchievements::class, 'select `achievement_id` from `user_achievements`'],
]);

it('dit au worker de rejouer un recalcul qui attend son tour, et jamais celui qui échoue ou plante', function (string $classe): void {
    $user = User::factory()->create();
    config(['queue.default' => 'database']);
    $classe::dispatch($user);

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

    expect($sansChevauchement->key)->toBe((string) $user->id)
        ->and($sansChevauchement->shareKey)->toBeFalse()
        ->and($attente)->toBeGreaterThan(0)
        ->and($charge['maxTries'])->toBe(intdiv($sansChevauchement->expiresAfter, $attente) + 1)
        ->and($charge['maxExceptions'])->toBe(1)
        ->and($charge['countCrashesAsExceptions'])->toBeTrue()
        ->and($charge['backoff'])->toBeNull()
        ->and($charge['retryUntil'])->toBeNull()
        ->and($travail->uniqueFor())->toBeGreaterThanOrEqual($sansChevauchement->expiresAfter);

    // Le verrou de l'exécution survit au délai que Horizon laisse à un
    // travail, dans chaque environnement : un recalcul qui tourne jusqu'à ce
    // délai le garde jusqu'au bout.
    $superviseurs = [config('horizon.defaults.supervisor-1')];

    foreach ((array) config('horizon.environments') as $environnement) {
        assert(is_array($environnement));
        $superviseurs[] = array_merge((array) config('horizon.defaults.supervisor-1'), (array) ($environnement['supervisor-1'] ?? []));
    }

    foreach ($superviseurs as $superviseur) {
        assert(is_array($superviseur) && is_int($superviseur['timeout']));
        expect($sansChevauchement->expiresAfter)->toBeGreaterThan($superviseur['timeout']);
    }
})->with([
    'objectifs' => [SyncUserGoals::class],
    'succès' => [SyncUserAchievements::class],
]);
