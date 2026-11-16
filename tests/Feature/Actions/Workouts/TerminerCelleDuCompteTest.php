<?php

declare(strict_types=1);

use App\Actions\Workouts\TerminerLaSeanceAction;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

/*
 * La fin que la file envoie (#2049) lit la séance une seule fois, sous le
 * verrou du compte, et c'est là que son appartenance se vérifie (#2325).
 */

it('termine la séance du compte à l heure bornée du geste', function (): void {
    $compte = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => null, 'started_at' => now()->subHour()]);

    $close = app(TerminerLaSeanceAction::class)->terminerCelleDuCompte($compte, $seance->id, 600);

    expect($close->ended_at)->not->toBeNull()
        ->and($close->ended_at->diffInSeconds(now()->subSeconds(600), true))->toBeLessThan(5)
        ->and($seance->fresh()?->ended_at)->not->toBeNull();
});

it('garde la première fin d une séance déjà close', function (): void {
    $compte = User::factory()->create();
    $premiere = Carbon::parse('2026-11-01 10:00:00');
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => $premiere->copy()->subHour(), 'ended_at' => $premiere]);

    $close = app(TerminerLaSeanceAction::class)->terminerCelleDuCompte($compte, $seance->id, 0);

    expect($close->ended_at?->equalTo($premiere))->toBeTrue()
        ->and($seance->fresh()?->ended_at?->equalTo($premiere))->toBeTrue();
});

it('refuse la séance d un autre compte avant toute écriture', function (): void {
    $compte = User::factory()->create();
    $autre = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $autre->id, 'ended_at' => null]);

    expect(fn () => app(TerminerLaSeanceAction::class)->terminerCelleDuCompte($compte, $seance->id, 0))
        ->toThrow(AuthorizationException::class)
        ->and($seance->fresh()?->ended_at)->toBeNull();
});

it('refuse une séance absente', function (): void {
    $compte = User::factory()->create();

    expect(fn () => app(TerminerLaSeanceAction::class)->terminerCelleDuCompte($compte, 999_999_999, 0))
        ->toThrow(ModelNotFoundException::class);
});
