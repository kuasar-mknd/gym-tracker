<?php

declare(strict_types=1);

use App\Jobs\SyncPersonalRecord;
use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Notifications\PersonalRecordAchieved;
use App\Services\PersonalRecordService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;

/*
 * Deux écritures des records du même exercice ne se croisent plus (#1984).
 *
 * Deux séries du même exercice donnent deux travaux `SyncPersonalRecord`, que
 * plusieurs processus de file exécutent en même temps, et la requête web qui
 * corrige une série reconstruit les records de son exercice (`recompute()`).
 * Chacun lisait les records, comparait, puis écrivait. Le premier record de
 * l'exercice était inséré deux fois, et le second travail échouait sur la
 * contrainte d'unicité ; un record déjà en place était écrasé par la plus
 * faible des deux séries, sans erreur nulle part.
 *
 * Ces tests rejouent l'entrelacement dans un seul processus, avec une fibre :
 * la première écriture s'arrête juste après avoir lu les records, et la
 * seconde démarre à cet instant. Sans verrou, la seconde passe en entier, puis
 * la première reprend sur sa lecture périmée. Avec le verrou, la seconde
 * attend : chacune de ses attentes (`Sleep`, simulé) rend la main à la
 * première, qui termine et rend le verrou.
 */

/**
 * Un compte, un exercice et une ligne de séance, et la série de 90 kg qui
 * tient déjà les records quand le scénario le demande.
 *
 * @return array{0: User, 1: Exercise, 2: WorkoutLine}
 */
function recordsConcurrentsScene(bool $recordEnPlace): array
{
    $user = User::factory()->create();
    $user->notificationPreferences()->create(['type' => 'personal_record', 'is_enabled' => true]);

    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $seance = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subHour()]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);

    if ($recordEnPlace) {
        // Par les modèles : `Set::saved` établit les records de 90 kg.
        Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 90, 'reps' => 5, 'is_warmup' => false, 'is_completed' => true]);

        expect(PersonalRecord::query()->where('user_id', $user->id)->where('type', 'max_weight')->value('value'))->toEqual(90.0);
    }

    return [$user->refresh(), $exercice, $ligne];
}

/**
 * Une série faite, enregistrée sans évènement : rien ne la synchronise avant
 * que le test ne le décide.
 */
function recordsConcurrentsSerie(User $user, WorkoutLine $ligne, float $poids): Set
{
    return Set::factory()->createQuietly([
        'workout_line_id' => $ligne->id,
        'user_id' => $user->id,
        'weight' => $poids,
        'reps' => 5,
        'is_warmup' => false,
        'is_completed' => true,
    ]);
}

/**
 * Lance la première écriture, l'arrête juste après sa lecture des records, et
 * lance la seconde à cet instant. Rend le nombre d'attentes de la seconde.
 *
 * Sans verrou, la seconde passe en entier pendant l'arrêt, et la première
 * reprend ensuite. Avec le verrou, la seconde attend, et chacune de ses
 * attentes rend la main à la première.
 */
function recordsConcurrentsEntrelacer(Closure $premiere, Closure $seconde): int
{
    $suspendue = false;
    $attentes = 0;
    $fibre = new Fiber($premiere);

    DB::listen(function (QueryExecuted $requete) use (&$suspendue, $fibre): void {
        if (! $suspendue && Fiber::getCurrent() === $fibre && str_starts_with($requete->sql, 'select * from `personal_records`')) {
            $suspendue = true;
            Fiber::suspend();
        }
    });

    Sleep::fake();
    Sleep::whenFakingSleep(function () use (&$attentes, $fibre): void {
        $attentes++;

        if ($fibre->isSuspended()) {
            $fibre->resume();
        }
    });

    $fibre->start();

    expect($fibre->isSuspended())->toBeTrue('La première écriture devait lire les records avant d’écrire.');

    $seconde();

    if ($fibre->isSuspended()) {
        $fibre->resume();
    }

    expect($fibre->isTerminated())->toBeTrue();

    return $attentes;
}

/**
 * Les trois records de l'exercice, par type : valeur et série.
 *
 * @return array<string, array{valeur: float, serie: int|null}>
 */
function recordsConcurrentsEtat(User $user, Exercise $exercice): array
{
    $etat = [];

    foreach (PersonalRecord::query()->where('user_id', $user->id)->where('exercise_id', $exercice->id)->get() as $record) {
        $etat[$record->type->value] = ['valeur' => (float) $record->value, 'serie' => $record->set_id];
    }

    ksort($etat);

    return $etat;
}

/**
 * Ce que la série de 105 kg × 5 doit tenir : les trois records.
 *
 * @return array<string, array{valeur: float, serie: int|null}>
 */
function recordsConcurrentsAttendus(Set $gagnante): array
{
    return [
        'max_1rm' => ['valeur' => 122.5, 'serie' => $gagnante->id],
        'max_volume_set' => ['valeur' => 525.0, 'serie' => $gagnante->id],
        'max_weight' => ['valeur' => 105.0, 'serie' => $gagnante->id],
    ];
}

/**
 * Les annonces « nouveau record » parties pour cette série, par type.
 *
 * @return array<string, int>
 */
function recordsConcurrentsAnnonces(User $user, Set $serie): array
{
    $parType = [];

    foreach (Notification::sent($user, PersonalRecordAchieved::class) as $annonce) {
        if (! $annonce instanceof PersonalRecordAchieved || $annonce->personalRecord->set_id !== $serie->id) {
            continue;
        }

        $type = $annonce->personalRecord->type->value;
        $parType[$type] = ($parType[$type] ?? 0) + 1;
    }

    ksort($parType);

    return $parType;
}

dataset('records concurrents', [
    'le premier record de l’exercice' => [false],
    'un record déjà en place, à 90 kg' => [true],
]);

it('garde la meilleure des deux séries quand deux synchronisations du même exercice se croisent', function (bool $recordEnPlace): void {
    Notification::fake();
    [$user, $exercice, $ligne] = recordsConcurrentsScene($recordEnPlace);
    $legere = recordsConcurrentsSerie($user, $ligne, 100);
    $lourde = recordsConcurrentsSerie($user, $ligne, 105);
    $service = app(PersonalRecordService::class);

    $attentes = recordsConcurrentsEntrelacer(
        fn () => new SyncPersonalRecord($legere, $user)->handle($service),
        fn () => new SyncPersonalRecord($lourde, $user)->handle($service),
    );

    expect(recordsConcurrentsEtat($user, $exercice))->toBe(recordsConcurrentsAttendus($lourde))
        // Une seule annonce par record pour la série gagnante : ni perdue,
        // ni doublée par le croisement.
        ->and(recordsConcurrentsAnnonces($user, $lourde))->toBe(['max_1rm' => 1, 'max_volume_set' => 1, 'max_weight' => 1])
        // La seconde a bien trouvé le verrou pris : elle a attendu la première.
        ->and($attentes)->toBeGreaterThan(0);
})->with('records concurrents');

it('garde la meilleure série quand une reconstruction croise une synchronisation du même exercice', function (bool $recordEnPlace): void {
    [$user, $exercice, $ligne] = recordsConcurrentsScene($recordEnPlace);
    $legere = recordsConcurrentsSerie($user, $ligne, 100);
    $lourde = recordsConcurrentsSerie($user, $ligne, 105);
    $service = app(PersonalRecordService::class);

    $attentes = recordsConcurrentsEntrelacer(
        fn () => new SyncPersonalRecord($legere, $user)->handle($service),
        fn () => $service->recompute($user, $exercice->id),
    );

    expect(recordsConcurrentsEtat($user, $exercice))->toBe(recordsConcurrentsAttendus($lourde))
        ->and($attentes)->toBeGreaterThan(0);
})->with('records concurrents');

/*
 * Le verrou peut manquer : expiré, évincé du cache, ou contourné par une
 * écriture hors du service. Le premier record inséré deux fois ne fait plus
 * échouer la synchronisation : `recompute()` repart des séries. La ligne en
 * conflit est plantée en base, entre la lecture et l'écriture de la
 * synchronisation.
 */
it('reconstruit au lieu d’échouer quand le premier record a été inséré entre-temps', function (): void {
    Notification::fake();
    [$user, $exercice, $ligne] = recordsConcurrentsScene(false);
    $legere = recordsConcurrentsSerie($user, $ligne, 100);
    $lourde = recordsConcurrentsSerie($user, $ligne, 105);
    $plantee = false;

    DB::listen(function (QueryExecuted $requete) use (&$plantee, $user, $exercice, $legere): void {
        if ($plantee || ! str_starts_with($requete->sql, 'select * from `personal_records`')) {
            return;
        }

        $plantee = true;

        DB::table('personal_records')->insert([
            'user_id' => $user->id,
            'exercise_id' => $exercice->id,
            'type' => 'max_weight',
            'value' => 100,
            'secondary_value' => 5,
            'workout_id' => $legere->workoutLine->workout_id,
            'set_id' => $legere->id,
            'achieved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    new SyncPersonalRecord($lourde, $user)->handle(app(PersonalRecordService::class));

    expect($plantee)->toBeTrue()
        ->and(recordsConcurrentsEtat($user, $exercice))->toBe(recordsConcurrentsAttendus($lourde))
        // La reconstruction n'annonce rien d'elle-même : la série qui gagne
        // est annoncée pour chaque record qu'elle détient désormais.
        ->and(recordsConcurrentsAnnonces($user, $lourde))->toBe(['max_1rm' => 1, 'max_volume_set' => 1, 'max_weight' => 1]);
});

it('n’annonce rien pour une série que la reconstruction ne retient pas', function (): void {
    Notification::fake();
    [$user, $exercice, $ligne] = recordsConcurrentsScene(false);
    $legere = recordsConcurrentsSerie($user, $ligne, 100);
    $lourde = recordsConcurrentsSerie($user, $ligne, 105);
    $plantee = false;

    DB::listen(function (QueryExecuted $requete) use (&$plantee, $user, $exercice, $lourde): void {
        if ($plantee || ! str_starts_with($requete->sql, 'select * from `personal_records`')) {
            return;
        }

        $plantee = true;

        DB::table('personal_records')->insert([
            'user_id' => $user->id,
            'exercise_id' => $exercice->id,
            'type' => 'max_weight',
            'value' => 105,
            'secondary_value' => 5,
            'workout_id' => $lourde->workoutLine->workout_id,
            'set_id' => $lourde->id,
            'achieved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    new SyncPersonalRecord($legere, $user)->handle(app(PersonalRecordService::class));

    expect($plantee)->toBeTrue()
        ->and(recordsConcurrentsEtat($user, $exercice))->toBe(recordsConcurrentsAttendus($lourde))
        ->and(recordsConcurrentsAnnonces($user, $legere))->toBe([]);
});
