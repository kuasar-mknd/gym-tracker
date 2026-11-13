<?php

declare(strict_types=1);

use App\Actions\Fasting\DemarrerUnJeuneAction;
use App\Actions\Fasting\MettreAJourUnJeuneAction;
use App\Models\Fast;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
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

it('refuse proprement, sans erreur 500, quand le verrou du compte reste pris au-delà de l’attente', function (): void {
    $user = User::factory()->create();
    $autre = Cache::lock("jeunes-du-compte:{$user->id}", 60, 'un-autre-processus');
    expect($autre->get())->toBeTrue();
    Sleep::fake(syncWithCarbon: true);

    $refus = null;

    try {
        app(DemarrerUnJeuneAction::class)->execute($user, donneesDUnJeune());
    } catch (ValidationException $leve) {
        $refus = $leve;
    }

    expect($refus)->toBeInstanceOf(ValidationException::class)
        ->and($refus?->errors())->toBe(['base' => [DemarrerUnJeuneAction::MESSAGE_VERROU_PRIS]])
        ->and($user->fasts()->count())->toBe(0);
});

it('refuse aussi, en validation, la reprise d’un jeûne quand le verrou du compte reste pris', function (): void {
    $user = User::factory()->create();
    $termine = Fast::factory()->for($user)->create(['status' => 'completed']);
    Cache::lock("jeunes-du-compte:{$user->id}", 60, 'un-autre-processus')->get();
    Sleep::fake(syncWithCarbon: true);

    expect(fn () => app(MettreAJourUnJeuneAction::class)->execute($user, $termine, ['status' => 'active']))
        ->toThrow(ValidationException::class, DemarrerUnJeuneAction::MESSAGE_VERROU_PRIS);
});

it('garde la première fin d’un jeûne quand un second appareil rejoue la sienne', function (): void {
    $user = User::factory()->create();
    $jeune = Fast::factory()->for($user)->create(['start_time' => now()->subHours(20)]);
    $premiere = Fast::query()->whereKey($jeune->getKey())->firstOrFail();
    $rejeu = Fast::query()->whereKey($jeune->getKey())->firstOrFail();

    $finDuPremier = now()->subHours(2)->startOfSecond();
    $finRejouee = now()->startOfSecond();

    app(MettreAJourUnJeuneAction::class)->execute($user, $premiere, ['end_time' => $finDuPremier, 'status' => 'completed']);
    app(MettreAJourUnJeuneAction::class)->execute($user, $rejeu, ['end_time' => $finRejouee, 'status' => 'broken']);

    $relu = $jeune->fresh();

    expect($relu?->end_time?->getTimestamp())->toBe($finDuPremier->getTimestamp())
        ->and($relu?->status)->toBe('completed');
});

it('prend la nouvelle fin d’un jeûne repris puis terminé de nouveau', function (): void {
    $user = User::factory()->create();
    $jeune = Fast::factory()->for($user)->create(['start_time' => now()->subHours(20)]);
    $premiereFin = now()->subHours(3)->startOfSecond();
    $secondeFin = now()->startOfSecond();
    $action = app(MettreAJourUnJeuneAction::class);

    $action->execute($user, $jeune, ['end_time' => $premiereFin, 'status' => 'completed']);
    $action->execute($user, $jeune->fresh() ?? $jeune, ['status' => 'active']);
    $action->execute($user, $jeune->fresh() ?? $jeune, ['end_time' => $secondeFin, 'status' => 'completed']);

    expect($jeune->fresh()?->end_time?->getTimestamp())->toBe($secondeFin->getTimestamp());
});

it('pose la fin d’un jeûne en cours', function (): void {
    $user = User::factory()->create();
    $jeune = Fast::factory()->for($user)->create(['start_time' => now()->subHours(20)]);
    $fin = now()->startOfSecond();

    app(MettreAJourUnJeuneAction::class)->execute($user, $jeune, ['end_time' => $fin, 'status' => 'completed']);

    expect($jeune->fresh()?->end_time?->getTimestamp())->toBe($fin->getTimestamp());
});
