<?php

declare(strict_types=1);

use App\Actions\Fasting\DemarrerUnJeuneAction;
use App\Models\Fast;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

/**
 * @return array<string, mixed>
 */
function donneesDUnJeune(): array
{
    return ['start_time' => now()->toDateTimeString(), 'target_duration_minutes' => 960, 'type' => '16:8'];
}

it('démarre un jeûne actif', function (): void {
    $user = User::factory()->create();

    $jeune = app(DemarrerUnJeuneAction::class)->execute($user, donneesDUnJeune());

    expect($jeune->status)->toBe('active')
        ->and($user->fasts()->count())->toBe(1);
});

it('refuse un second jeûne quand un est déjà en cours', function (): void {
    $user = User::factory()->create();
    Fast::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    expect(fn () => app(DemarrerUnJeuneAction::class)->execute($user, donneesDUnJeune()))
        ->toThrow(ValidationException::class, DemarrerUnJeuneAction::MESSAGE);

    expect($user->fasts()->count())->toBe(1);
});

/*
 * Le premier démarrage s'arrête juste après sa lecture « aucun jeûne en
 * cours » ; le second part à cet instant. Sans verrou, il passe en entier et
 * deux jeûnes restent actifs.
 */
it('ne laisse pas deux démarrages simultanés ouvrir deux jeûnes', function (): void {
    $user = User::factory()->create();
    $demarrer = app(DemarrerUnJeuneAction::class);

    $suspendu = false;
    $attentes = 0;
    $fibre = new Fiber(function () use ($demarrer, $user): void {
        $demarrer->execute($user, donneesDUnJeune());
    });

    DB::listen(function (QueryExecuted $requete) use (&$suspendu, $fibre): void {
        if (! $suspendu && Fiber::getCurrent() === $fibre && str_contains($requete->sql, 'from `fasts`')) {
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

    expect($fibre->isSuspended())->toBeTrue();

    $refus = null;

    try {
        $demarrer->execute($user, donneesDUnJeune());
    } catch (ValidationException $leve) {
        $refus = $leve;
    }

    if ($fibre->isSuspended()) {
        $fibre->resume();
    }

    expect($fibre->isTerminated())->toBeTrue()
        ->and($user->fasts()->where('status', 'active')->count())->toBe(1)
        ->and($refus)->toBeInstanceOf(ValidationException::class)
        ->and($attentes)->toBeGreaterThan(0);
});
