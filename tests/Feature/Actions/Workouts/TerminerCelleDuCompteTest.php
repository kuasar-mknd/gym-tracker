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

/*
 * Une séance oubliée se clôt à l'heure que la personne a choisie (#2319) : la
 * requête l'a déjà jugée, l'action la borne encore sur sa lecture de l'horloge.
 */
it('termine à l heure choisie, entre le début et maintenant', function (): void {
    $compte = User::factory()->create();
    $maintenant = Carbon::parse('2026-11-17 12:00:00');
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => null, 'started_at' => '2026-11-15 09:00:00']);

    $close = app(TerminerLaSeanceAction::class)
        ->terminerCelleDuCompte($compte, $seance->id, 120, Carbon::parse('2026-11-15 11:30:00'), $maintenant);

    expect($close->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-11-15 11:30:00');
});

it('borne l heure choisie : jamais avant le début, jamais après maintenant', function (string $choisie, string $attendue): void {
    $compte = User::factory()->create();
    $maintenant = Carbon::parse('2026-11-17 12:00:00');
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => null, 'started_at' => '2026-11-15 09:00:00']);

    $close = app(TerminerLaSeanceAction::class)
        ->terminerCelleDuCompte($compte, $seance->id, null, Carbon::parse($choisie), $maintenant);

    expect($close->ended_at?->format('Y-m-d H:i:s'))->toBe($attendue);
})->with([
    'avant le début' => ['2026-11-15 08:59:59', '2026-11-15 09:00:00'],
    'au début' => ['2026-11-15 09:00:00', '2026-11-15 09:00:00'],
    'maintenant' => ['2026-11-17 12:00:00', '2026-11-17 12:00:00'],
    'après maintenant' => ['2026-11-17 12:00:01', '2026-11-17 12:00:00'],
]);

it('garde la première fin d une séance déjà close, même avec une heure choisie', function (): void {
    $compte = User::factory()->create();
    $premiere = Carbon::parse('2026-11-01 10:00:00');
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => $premiere->copy()->subHour(), 'ended_at' => $premiere]);

    $close = app(TerminerLaSeanceAction::class)
        ->terminerCelleDuCompte($compte, $seance->id, null, Carbon::parse('2026-11-01 09:30:00'), Carbon::parse('2026-11-17 12:00:00'));

    expect($close->ended_at?->equalTo($premiere))->toBeTrue();
});
