<?php

declare(strict_types=1);

use App\Actions\Stats\GetStatsDashboardAction;
use App\DTOs\Stats\DurationHistoryPoint;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;

/*
 * La vue « performance » de la page Statistiques trace la durée des trente
 * dernières séances terminées, de la plus ancienne à la plus récente. Rien ne
 * tenait ce nombre : vingt-neuf ou trente-et-une passaient.
 *
 * Trente-et-une séances, écrites d'une seule requête : les écouteurs d'une
 * séance (série de jours, succès, objectifs) ne disent rien de cette borne.
 */
it('trace la durée des trente dernières séances terminées, pas une de plus', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-30 12:00:00'));
    $user = User::factory()->create();

    Workout::insert(array_map(fn (int $jour): array => [
        'user_id' => $user->id,
        'name' => sprintf('S%02d', $jour),
        'started_at' => Carbon::parse('2026-05-31 10:00:00')->addDays($jour),
        'ended_at' => Carbon::parse('2026-05-31 11:00:00')->addDays($jour),
        'created_at' => now(),
        'updated_at' => now(),
    ], range(0, 30)));

    /** @var list<DurationHistoryPoint> $durees */
    $durees = app(GetStatsDashboardAction::class)->performanceOverview($user, 30)['durationHistory'];

    expect(array_map(fn (DurationHistoryPoint $point): string => $point->name, $durees))
        ->toBe(array_map(fn (int $jour): string => sprintf('S%02d', $jour), range(1, 30)));
});
