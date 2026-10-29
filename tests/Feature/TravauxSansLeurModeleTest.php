<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Jobs\SyncPersonalRecord;
use App\Jobs\SyncUserAchievements;
use App\Jobs\SyncUserGoals;
use App\Models\Achievement;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\NotificationPreference;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Notifications\AchievementUnlocked;
use App\Notifications\PersonalRecordAchieved;
use App\Notifications\TrainingReminder;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/*
 * Un travail en file dont le modèle a disparu avant son tour ne finit plus
 * dans `failed_jobs` (#2109).
 *
 * Laravel restaure les modèles d'un travail avant de le lancer : une série
 * supprimée avant la synchronisation de ses records, ou un compte supprimé
 * alors qu'un de ses recalculs attend, levait `ModelNotFoundException`, et le
 * travail partait en échec, gardé trente jours dans `failed_jobs` et montré par
 * Horizon et le diagnostic au milieu des vrais échecs. L'échec ne protégeait
 * rien : les écouteurs de la suppression refont déjà les records de la série,
 * et un compte supprimé n'a plus rien à recalculer. Les trois travaux, et les
 * deux avis qui portent un modèle (un record, un badge), déclarent donc
 * `#[DeleteWhenMissingModels]` : Laravel les supprime sans les lancer.
 *
 * Les tests passent par une vraie file `database` et de vrais workers
 * (`queue:work --once`, qui remplit `failed_jobs` comme en production), un
 * travail à la fois (`.ai/rules/tests.md`). Sous APP_ENV=testing, la
 * synchronisation des records tourne dans la requête : les cas « sur le
 * chemin de production » la mettent en file, comme en production
 * (`sansModeleCheminDeProduction()`).
 *
 * Aucun verrou ne reste derrière un travail supprimé : Laravel rend le verrou
 * d'unicité d'un travail qu'il ne peut pas restaurer d'après la charge
 * (`CallQueuedHandler::handleModelNotFound()`), et le verrou d'exécution
 * (`WithoutOverlapping`) ne se prend qu'au lancement, qui n'a pas lieu.
 */

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Le chemin des records en production : file `database`, et
 * `SyncPersonalRecord` mis en file par `Set::saved` au lieu de tourner dans la
 * requête (`AppServiceProvider::registerSetEvents()`). Seule la configuration
 * que lit cette condition change : la connexion, déjà ouverte, reste celle du
 * test.
 */
function sansModeleCheminDeProduction(): void
{
    config([
        'queue.default' => 'database',
        'app.env' => 'production',
        'database.connections.mysql.database' => 'gym_tracker',
    ]);
}

/**
 * Un pratiquant, un exercice dans une séance, un objectif « 100 kg » sur cet
 * exercice, et l'annonce de ses records activée, posés pendant que la file est
 * encore synchrone.
 *
 * @return array{0: User, 1: WorkoutLine, 2: Goal}
 */
function sansModeleCompteAvecUnObjectif(): array
{
    $user = User::factory()->create();
    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'type' => 'personal_record',
        'is_enabled' => true,
        'is_push_enabled' => false,
    ]);
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
 * Valide une série par l'API, comme la page de séance, et la rend.
 */
function sansModeleValiderParLaRoute(User $user, WorkoutLine $ligne, float $poids): Set
{
    $id = actingAs($user, 'sanctum')
        ->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $ligne->id,
            'weight' => $poids,
            'reps' => 1,
            'is_warmup' => false,
            'is_completed' => true,
        ])
        ->assertCreated()
        ->json('data.id');
    assert(is_int($id));

    return Set::query()->findOrFail($id);
}

/**
 * Supprime une série par l'API, comme la page de séance.
 */
function sansModeleSupprimerParLaRoute(User $user, Set $serie): void
{
    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.sets.destroy', $serie))
        ->assertNoContent();
}

/**
 * Traite le prochain travail disponible par un vrai worker.
 */
function sansModeleTraiterLeSuivant(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);
}

/**
 * Vide la file par de vrais workers, un travail à la fois. Un travail remis
 * en file à plus tard se reprend à son heure : l'horloge avance jusqu'à elle
 * (`available_at`, une seconde pleine). Une file qui garde des travaux après
 * vingt tours lève, au lieu de laisser le cas conclure sur une file à moitié
 * vidée.
 */
function sansModeleViderLaFile(): void
{
    foreach (range(1, 20) as $_tour) {
        $disponibleA = DB::table('jobs')->min('available_at');

        if (! is_numeric($disponibleA)) {
            return;
        }

        if ((int) $disponibleA > now()->getTimestamp()) {
            Carbon::setTestNow(Carbon::createFromTimestamp((int) $disponibleA, date_default_timezone_get()));
        }

        sansModeleTraiterLeSuivant();
    }

    throw new LogicException('La file garde des travaux après vingt tours.');
}

/**
 * Les travaux de la file, réservés compris, dans l'ordre où ils y sont entrés,
 * par le nom que Horizon affiche (la classe de l'avis pour une notification).
 *
 * @return list<string>
 */
function sansModeleContenuDeLaFile(): array
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
 * Les travaux que les workers ont rangés dans `failed_jobs`, chacun suivi de
 * son exception, sans la pile ni le fichier qui l'a levée.
 *
 * @return list<string>
 */
function sansModeleTravauxEnEchec(): array
{
    $echecs = [];

    foreach (DB::table('failed_jobs')->orderBy('id')->get(['payload', 'exception']) as $ligne) {
        assert(is_string($ligne->payload) && is_string($ligne->exception));
        $charge = json_decode($ligne->payload, true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($charge) && is_string($charge['displayName'] ?? null));

        $echecs[] = $charge['displayName'].' : '.preg_replace('/ in \/.*$/', '', (string) strtok($ligne->exception, "\n"));
    }

    return $echecs;
}

/**
 * Ni le verrou d'unicité ni le verrou d'exécution de ce travail ne sont
 * pris : un envoi de la même clé partirait, et tournerait aussitôt.
 */
function sansModeleAucunVerrouNeReste(SyncPersonalRecord|SyncUserAchievements|SyncUserGoals $travail): void
{
    $unicite = Cache::lock(UniqueLock::getKey($travail), 10);
    $execution = Cache::lock($travail->middleware()[0]->getLockKey($travail), 10);

    expect($unicite->get())->toBeTrue('Le verrou d’unicité de '.$travail::class.' est resté pris.')
        ->and($execution->get())->toBeTrue('Le verrou d’exécution de '.$travail::class.' est resté pris.');

    $unicite->release();
    $execution->release();
}

/**
 * Le travail que nomme un jeu de données pour ce compte, sur une série de
 * 100 kg posée sans évènement (rien ne part en file) pour les records.
 */
function sansModeleLeTravail(string $classe, User $user): SyncPersonalRecord|SyncUserAchievements|SyncUserGoals
{
    if ($classe === SyncPersonalRecord::class) {
        $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
        $seance = Workout::factory()->createQuietly(['user_id' => $user->id]);
        $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
        $serie = Set::factory()->createQuietly([
            'workout_line_id' => $ligne->id,
            'user_id' => $user->id,
            'weight' => 100,
            'reps' => 1,
            'is_warmup' => false,
            'is_completed' => true,
        ]);

        return new SyncPersonalRecord($serie, $user);
    }

    return match ($classe) {
        SyncUserGoals::class => new SyncUserGoals($user),
        SyncUserAchievements::class => new SyncUserAchievements($user),
        default => throw new LogicException('Ni les objectifs, ni les succès, ni les records : '.$classe),
    };
}

it('ne laisse aucun travail en échec quand une série validée est supprimée avant la synchronisation de ses records, et les records restent justes, sur le chemin de production', function (): void {
    [$user, $ligne, $objectif] = sansModeleCompteAvecUnObjectif();

    // Une première série, synchronisée dans la requête : le record de 90 kg.
    $premiere = sansModeleValiderParLaRoute($user, $ligne, 90);

    sansModeleCheminDeProduction();

    // La série cochée puis supprimée aussitôt, ou la file hors ligne qui
    // rejoue « série ajoutée » puis « série supprimée » : aucun worker n'est
    // passé entre les deux.
    $supprimee = sansModeleValiderParLaRoute($user, $ligne, 110);

    expect(sansModeleContenuDeLaFile())->toBe([SyncPersonalRecord::class, SyncUserAchievements::class, SyncUserGoals::class]);

    sansModeleSupprimerParLaRoute($user, $supprimee);
    sansModeleViderLaFile();

    $objectif->refresh();
    $record = PersonalRecord::query()
        ->where('user_id', $user->id)
        ->where('exercise_id', $ligne->exercise_id)
        ->where('type', 'max_weight')
        ->sole();

    expect(sansModeleTravauxEnEchec())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($record->value)->toBe('90.00')
        ->and((int) $record->set_id)->toBe($premiere->id)
        // Chaque record du compte reste tenu par la première série. Chercher ceux
        // de la série supprimée ne prouverait rien : `personal_records.set_id`
        // passe à nul quand sa série disparaît (ON DELETE SET NULL).
        ->and(PersonalRecord::query()
            ->where('user_id', $user->id)
            ->where(static fn (Builder $records): Builder => $records->whereNull('set_id')->orWhere('set_id', '<>', $premiere->id))
            ->doesntExist())->toBeTrue()
        ->and($objectif->current_value)->toBe(90.0)
        ->and($objectif->completed_at)->toBeNull();

    sansModeleAucunVerrouNeReste(new SyncPersonalRecord($supprimee, $user));
});

it('ne laisse aucun travail en échec quand le compte est supprimé alors que ses recalculs attendent, sur le chemin de production', function (bool $recordsDejaSynchronises, array $fileAvantLaSuppression): void {
    [$user, $ligne] = sansModeleCompteAvecUnObjectif();
    sansModeleCheminDeProduction();

    $serie = sansModeleValiderParLaRoute($user, $ligne, 110);

    if ($recordsDejaSynchronises) {
        // Un worker a écrit le record et mis son annonce en file ; les
        // recalculs qu'il renvoie sont écartés, ceux de la requête attendent.
        sansModeleTraiterLeSuivant();
    }

    // Un avis qui ne porte que son destinataire : un compte disparu le reçoit
    // sans rien à restaurer, et il ne part simplement à personne.
    $user->notify(new TrainingReminder());

    expect(sansModeleContenuDeLaFile())->toBe($fileAvantLaSuppression);

    // Le profil, par le compte lui-même, dans sa session (la garde `web`,
    // que l'appel à l'API a remplacée par celle de Sanctum).
    $this->actingAs($user, 'web')
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();

    sansModeleViderLaFile();

    expect(sansModeleTravauxEnEchec())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('notifications')->where('notifiable_id', $user->id)->count())->toBe(0);

    sansModeleAucunVerrouNeReste(new SyncPersonalRecord($serie, $user));
    sansModeleAucunVerrouNeReste(new SyncUserAchievements($user));
    sansModeleAucunVerrouNeReste(new SyncUserGoals($user));
})->with([
    'avant la synchronisation de ses records' => [
        false,
        [SyncPersonalRecord::class, SyncUserAchievements::class, SyncUserGoals::class, TrainingReminder::class],
    ],
    'après la synchronisation de ses records, leur annonce en file' => [
        true,
        [
            SyncUserAchievements::class, SyncUserGoals::class,
            PersonalRecordAchieved::class, PersonalRecordAchieved::class, PersonalRecordAchieved::class,
            TrainingReminder::class,
        ],
    ],
]);

it('n’annonce pas le record d’une série supprimée avant son annonce, sans travail en échec, sur le chemin de production', function (): void {
    [$user, $ligne] = sansModeleCompteAvecUnObjectif();
    sansModeleCheminDeProduction();

    $serie = sansModeleValiderParLaRoute($user, $ligne, 110);
    sansModeleTraiterLeSuivant();

    // Les trois records de la série (poids, 1RM estimé, volume), chacun
    // annoncé par son propre avis.
    expect(sansModeleContenuDeLaFile())->toBe([
        SyncUserAchievements::class, SyncUserGoals::class,
        PersonalRecordAchieved::class, PersonalRecordAchieved::class, PersonalRecordAchieved::class,
    ])
        ->and(PersonalRecord::query()->where('set_id', $serie->id)->count())->toBe(3);

    // La suppression reconstruit les records : plus aucune série ne les
    // tient, et ils disparaissent avant que leur annonce ne parte.
    sansModeleSupprimerParLaRoute($user, $serie);
    sansModeleViderLaFile();

    expect(sansModeleTravauxEnEchec())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(PersonalRecord::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('notifications')->where('notifiable_id', $user->id)->count())->toBe(0);
});

it('n’annonce pas un badge retiré du catalogue avant son annonce, sans travail en échec', function (): void {
    $user = User::factory()->create();
    $badge = Achievement::factory()->create();
    config(['queue.default' => 'database']);

    $user->notify(new AchievementUnlocked($badge));

    expect(sansModeleContenuDeLaFile())->toBe([AchievementUnlocked::class]);

    $badge->delete();
    sansModeleViderLaFile();

    expect(sansModeleTravauxEnEchec())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('notifications')->where('notifiable_id', $user->id)->count())->toBe(0);
});

it('supprime sans échec, et sans garder son verrou d’unicité, un travail qui attendait son tour quand son modèle disparaît', function (string $classe): void {
    $this->travelTo('2026-10-28 10:00:00');
    $user = User::factory()->create();
    $travail = sansModeleLeTravail($classe, $user);
    config(['queue.default' => 'database']);

    // Un autre worker tient la clé du travail : il revient en file, et garde
    // sa place (verrou d'unicité pris), si bien que l'envoi suivant est écarté.
    $occupe = Cache::lock($travail->middleware()[0]->getLockKey($travail), 3600);
    expect($occupe->get())->toBeTrue();

    dispatch($travail);
    sansModeleTraiterLeSuivant();
    dispatch(clone $travail);

    expect(sansModeleContenuDeLaFile())->toBe([$classe])
        ->and(DB::table('jobs')->where('attempts', 1)->count())->toBe(1);

    if ($travail instanceof SyncPersonalRecord) {
        $travail->set->deleteQuietly();
    } else {
        $user->delete();
    }

    $occupe->release();

    sansModeleViderLaFile();

    expect(sansModeleTravauxEnEchec())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0);

    sansModeleAucunVerrouNeReste($travail);
})->with([
    'objectifs' => [SyncUserGoals::class],
    'succès' => [SyncUserAchievements::class],
    'records d’une série' => [SyncPersonalRecord::class],
]);
