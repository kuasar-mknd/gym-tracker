<?php

declare(strict_types=1);

use App\Actions\Workouts\DemarrerUneSeanceAction;
use App\Exceptions\UneSeanceEstDejaEnCours;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/*
 * Deux démarrages du même compte ne se croisent plus (#2049).
 *
 * Deux onglets, deux appareils, ou la file hors ligne qui rejoue un démarrage
 * pendant qu'un autre arrive : chacun lisait « aucune séance en cours » au
 * même instant et ouvrait la sienne, et deux séances restaient ouvertes,
 * dont le bandeau ne montrait que la plus récente (#1958). Le démarrage se
 * fait désormais sous le verrou du compte : la clé, puis la séance en cours
 * lue en base, puis la création (`DemarrerUneSeanceAction`).
 *
 * Ces tests rejouent l'entrelacement dans un seul processus, avec une fibre,
 * comme `RecordsConcurrentsTest` : le premier démarrage s'arrête juste après
 * sa lecture de la séance en cours, la dernière avant sa création, et le
 * second démarre à cet instant. Sans verrou, le second passe en entier, puis
 * le premier reprend sur sa lecture périmée. Avec le verrou, le second
 * attend : chacune de ses attentes (`Sleep`, simulé) rend la main au premier,
 * qui termine et rend le verrou.
 */

/**
 * Lance le premier démarrage, l'arrête juste après sa lecture de la séance en
 * cours, joue `$pendant` à cet instant, puis le laisse finir. Rend ce que le
 * premier a rendu, ou ce qu'il a levé, et le nombre d'attentes du verrou.
 *
 * @return array{premier: Workout|Throwable, attentes: int}
 */
function demarragesConcurrentsEntrelacer(Closure $premier, Closure $pendant): array
{
    $suspendu = false;
    $attentes = 0;
    $issue = null;
    $fibre = new Fiber(function () use ($premier, &$issue): void {
        try {
            $issue = $premier();
        } catch (Throwable $leve) {
            $issue = $leve;
        }
    });

    DB::listen(function (QueryExecuted $requete) use (&$suspendu, $fibre): void {
        if (! $suspendu && Fiber::getCurrent() === $fibre && str_contains($requete->sql, '`ended_at` is null')) {
            $suspendu = true;
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

    expect($fibre->isSuspended())->toBeTrue('Le premier démarrage devait lire la séance en cours avant de créer.');

    $pendant();

    if ($fibre->isSuspended()) {
        $fibre->resume();
    }

    expect($fibre->isTerminated())->toBeTrue();
    assert($issue instanceof Workout || $issue instanceof Throwable);

    return ['premier' => $issue, 'attentes' => $attentes];
}

/**
 * Les séances en cours du compte.
 */
function demarragesConcurrentsEnCours(User $user): int
{
    return Workout::query()->where('user_id', $user->id)->whereNull('ended_at')->count();
}

it('refuse le second démarrage quand deux appareils démarrent au même instant', function (): void {
    $user = User::factory()->create();
    $demarrer = app(DemarrerUneSeanceAction::class);
    $second = null;

    $issue = demarragesConcurrentsEntrelacer(
        fn (): Workout => $demarrer->execute($user, '0b6f5d1e-2049-4c11-9d1a-000000000001'),
        function () use ($demarrer, $user, &$second): void {
            try {
                $demarrer->execute($user, '0b6f5d1e-2049-4c11-9d1a-000000000002');
            } catch (UneSeanceEstDejaEnCours $refus) {
                $second = $refus;
            }
        },
    );

    expect(demarragesConcurrentsEnCours($user))->toBe(1)
        ->and($issue['premier'])->toBeInstanceOf(Workout::class)
        ->and($second)->toBeInstanceOf(UneSeanceEstDejaEnCours::class)
        // Le refus nomme la séance que le premier a ouverte : la page y renvoie.
        ->and($second?->seanceEnCours->id)->toBe($issue['premier'] instanceof Workout ? $issue['premier']->id : null)
        // Le second a bien trouvé le verrou pris : il a attendu le premier.
        ->and($issue['attentes'])->toBeGreaterThan(0);
});

it('rend la même séance à deux rejeux simultanés de la même clé', function (): void {
    $user = User::factory()->create();
    $demarrer = app(DemarrerUneSeanceAction::class);
    $cle = '0b6f5d1e-2049-4c11-9d1a-000000000003';
    $second = null;

    $issue = demarragesConcurrentsEntrelacer(
        fn (): Workout => $demarrer->execute($user, $cle),
        function () use ($demarrer, $user, $cle, &$second): void {
            $second = $demarrer->execute($user, $cle);
        },
    );

    expect(Workout::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($issue['premier'])->toBeInstanceOf(Workout::class)
        ->and($second?->id)->toBe($issue['premier'] instanceof Workout ? $issue['premier']->id : null)
        ->and($issue['attentes'])->toBeGreaterThan(0);
});

/*
 * Le verrou peut manquer : expiré, évincé du cache, ou une écriture qui ne
 * passe pas par lui. La gagnante d'une clé est alors écrite entre la lecture
 * et la création du premier démarrage : l'index unique `(user_id,
 * idempotency_key)` refuse la seconde, et le premier rend la gagnante, au
 * lieu d'une erreur 500 que la file réessaierait sans fin.
 */
it('rend la gagnante de la clé quand le verrou n’a pas tenu', function (): void {
    $user = User::factory()->create();
    $demarrer = app(DemarrerUneSeanceAction::class);
    $cle = '0b6f5d1e-2049-4c11-9d1a-000000000004';
    $gagnante = null;

    $issue = demarragesConcurrentsEntrelacer(
        fn (): Workout => $demarrer->execute($user, $cle),
        function () use ($user, $cle, &$gagnante): void {
            $gagnante = Workout::factory()->for($user)->make(['started_at' => now(), 'ended_at' => null]);
            $gagnante->idempotency_key = $cle;
            $gagnante->saveQuietly();
        },
    );

    expect($issue['premier'])->toBeInstanceOf(Workout::class)
        ->and($issue['premier'] instanceof Workout ? $issue['premier']->id : null)->toBe($gagnante?->id)
        ->and(Workout::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('ne fait pas attendre le démarrage d’un autre compte', function (): void {
    $user = User::factory()->create();
    $autre = User::factory()->create();
    $demarrer = app(DemarrerUneSeanceAction::class);
    $deLAutre = null;

    $issue = demarragesConcurrentsEntrelacer(
        fn (): Workout => $demarrer->execute($user, '0b6f5d1e-2049-4c11-9d1a-000000000005'),
        function () use ($demarrer, $autre, &$deLAutre): void {
            $deLAutre = $demarrer->execute($autre, '0b6f5d1e-2049-4c11-9d1a-000000000006');
        },
    );

    expect($issue['attentes'])->toBe(0)
        ->and($deLAutre?->user_id)->toBe($autre->id)
        ->and($issue['premier'])->toBeInstanceOf(Workout::class);
});

/*
 * Un rejeu qui n'est pas le sien ne rend jamais la séance d'un autre compte,
 * même sous la clé exacte : la clé ne se cherche que parmi les séances du
 * compte, et l'index est par compte.
 */
it('ne rend jamais la séance d’un autre compte à qui rejoue sa clé', function (): void {
    $user = User::factory()->create();
    $autre = User::factory()->create();
    $demarrer = app(DemarrerUneSeanceAction::class);
    $cle = '0b6f5d1e-2049-4c11-9d1a-000000000007';

    $deLAutre = $demarrer->execute($autre, $cle);
    $mienne = $demarrer->execute($user, $cle);

    expect($mienne->id)->not->toBe($deLAutre->id)
        ->and($mienne->user_id)->toBe($user->id)
        ->and($mienne->idempotency_key)->toBe($cle);
});

/*
 * Ce que le premier démarrage lève quand l'index refuse sa création et que
 * rien ne porte sa clé : l'erreur remonte telle quelle, elle n'est pas
 * masquée par une séance qui ne serait pas la sienne.
 */
it('laisse remonter un refus de l’index qui ne vient pas de sa clé', function (): void {
    $user = User::factory()->create();
    $demarrer = app(DemarrerUneSeanceAction::class);

    Workout::creating(function (): never {
        throw new UniqueConstraintViolationException('mysql', 'insert into `workouts`', [], new RuntimeException('Duplicate entry'));
    });

    expect(fn (): Workout => $demarrer->execute($user, '0b6f5d1e-2049-4c11-9d1a-000000000008'))
        ->toThrow(UniqueConstraintViolationException::class);
});
