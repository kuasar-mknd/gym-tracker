<?php

declare(strict_types=1);

use App\Actions\Workouts\DemarrerUneSeanceAction;
use App\Actions\Workouts\TerminerLaSeanceAction;
use App\Actions\Workouts\UpdateWorkoutAction;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/*
 * Deux fins de la même séance ne se croisent plus (#2049).
 *
 * La fin relisait la séance, vérifiait qu'elle était encore ouverte, puis
 * l'enregistrait, sans rien qui empêche une autre fin de passer entre les
 * deux : deux appareils dont la file se vide au même retour du réseau, ou une
 * fin directe et son rejeu. La seconde écrivait son heure, rendait sa propre
 * fin à son appareil, et la première l'écrasait ensuite avec la sienne :
 * l'heure gardée n'était plus celle de la première fin arrivée, et les deux
 * appareils ne montraient pas la même. La fin passe désormais sous le verrou
 * des séances du compte, celui que prend déjà le démarrage
 * (`DemarrerUneSeanceAction::verrouDuCompte()`), qui ordonne aussi fin et
 * démarrage.
 *
 * Ces tests rejouent l'entrelacement dans un seul processus, avec une fibre,
 * comme `DemarragesConcurrentsTest` : la première fin s'arrête juste après sa
 * relecture de la séance, la dernière avant son écriture, et l'autre écriture
 * arrive à cet instant. Avec le verrou, elle attend : chacune de ses attentes
 * (`Sleep`, simulé) rend la main à la première, qui termine et rend le verrou.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-19 12:00:00');
});

/**
 * Lance la première fin, l'arrête juste après sa relecture de la séance, joue
 * `$pendant` à cet instant, puis la laisse finir. Rend ce que la première a
 * rendu, et le nombre d'attentes du verrou.
 *
 * @return array{premiere: Workout, attentes: int}
 */
function finsConcurrentesEntrelacer(Closure $premiere, Closure $pendant): array
{
    $suspendue = false;
    $attentes = 0;
    $issue = null;
    $fibre = new Fiber(function () use ($premiere, &$issue): void {
        $issue = $premiere();
    });

    DB::listen(function (QueryExecuted $requete) use (&$suspendue, $fibre): void {
        if (! $suspendue && Fiber::getCurrent() === $fibre && str_starts_with($requete->sql, 'select * from `workouts`')) {
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

    expect($fibre->isSuspended())->toBeTrue('La première fin devait relire la séance avant de l’écrire.');

    $pendant();

    if ($fibre->isSuspended()) {
        $fibre->resume();
    }

    expect($fibre->isTerminated())->toBeTrue();
    assert($issue instanceof Workout);

    return ['premiere' => $issue, 'attentes' => $attentes];
}

/**
 * La séance en cours du compte, commencée à 11 h.
 */
function finsConcurrentesSeance(User $compte): Workout
{
    return Workout::factory()->create(['user_id' => $compte->id, 'started_at' => '2026-10-19 11:00:00', 'ended_at' => null]);
}

it('garde la première fin quand deux fins de la même séance se croisent', function (): void {
    $compte = User::factory()->create();
    $seance = finsConcurrentesSeance($compte);
    $terminer = app(TerminerLaSeanceAction::class);
    $seconde = null;

    $issue = finsConcurrentesEntrelacer(
        fn (): Workout => $terminer->execute($seance, Carbon::parse('2026-10-19 11:30:00')),
        function () use ($terminer, $seance, &$seconde): void {
            $seconde = $terminer->execute(Workout::query()->findOrFail($seance->id), Carbon::parse('2026-10-19 11:50:00'));
        },
    );

    expect($seance->refresh()->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-19 11:30:00')
        ->and($issue['premiere']->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-19 11:30:00')
        // La seconde rend la fin gardée, pas la sienne : les deux appareils montrent la même.
        ->and($seconde?->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-19 11:30:00')
        // La seconde a bien trouvé le verrou pris : elle a attendu la première.
        ->and($issue['attentes'])->toBeGreaterThan(0);
});

/*
 * La fin par la page (`workouts.update` avec `is_finished`, que gardent les
 * pages d'avant #2049 encore ouvertes) prend le même verrou, et garde la
 * première fin arrivée au lieu de la remplacer par maintenant.
 */
it('garde la première fin quand la page termine pendant une fin de la file', function (): void {
    $compte = User::factory()->create();
    $seance = finsConcurrentesSeance($compte);
    $terminer = app(TerminerLaSeanceAction::class);
    $parLaPage = null;

    $issue = finsConcurrentesEntrelacer(
        fn (): Workout => $terminer->execute($seance, Carbon::parse('2026-10-19 11:30:00')),
        function () use ($seance, &$parLaPage): void {
            $parLaPage = app(UpdateWorkoutAction::class)->execute(Workout::query()->findOrFail($seance->id), ['is_finished' => true]);
        },
    );

    expect($seance->refresh()->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-19 11:30:00')
        ->and($parLaPage?->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-19 11:30:00')
        ->and($issue['attentes'])->toBeGreaterThan(0);
});

/*
 * Un démarrage qui arrive pendant la fin de la séance en cours l'attend : il
 * lisait la séance encore ouverte et se refusait (409 `seance-deja-en-cours`)
 * alors qu'elle se fermait au même instant.
 */
it('fait attendre à un démarrage la fin de la séance en cours', function (): void {
    $compte = User::factory()->create();
    $seance = finsConcurrentesSeance($compte);
    $terminer = app(TerminerLaSeanceAction::class);
    $demarree = null;

    $issue = finsConcurrentesEntrelacer(
        fn (): Workout => $terminer->execute($seance, Carbon::parse('2026-10-19 11:30:00')),
        function () use ($compte, &$demarree): void {
            $demarree = app(DemarrerUneSeanceAction::class)->execute($compte, '0b6f5d1e-2049-4c11-9d1a-0000000000f1');
        },
    );

    expect($issue['premiere']->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-19 11:30:00')
        ->and($demarree?->id)->not->toBe($seance->id)
        ->and($demarree?->ended_at)->toBeNull()
        ->and($issue['attentes'])->toBeGreaterThan(0);
});

it('ne fait pas attendre la fin d’une séance d’un autre compte', function (): void {
    $compte = User::factory()->create();
    $autre = User::factory()->create();
    $seance = finsConcurrentesSeance($compte);
    $deLAutre = finsConcurrentesSeance($autre);
    $terminer = app(TerminerLaSeanceAction::class);
    $finDeLAutre = null;

    $issue = finsConcurrentesEntrelacer(
        fn (): Workout => $terminer->execute($seance, Carbon::parse('2026-10-19 11:30:00')),
        function () use ($terminer, $deLAutre, &$finDeLAutre): void {
            $finDeLAutre = $terminer->execute($deLAutre, Carbon::parse('2026-10-19 11:40:00'));
        },
    );

    expect($issue['attentes'])->toBe(0)
        ->and($finDeLAutre?->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-19 11:40:00')
        ->and($issue['premiere']->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-19 11:30:00');
});
