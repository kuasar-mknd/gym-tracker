<?php

declare(strict_types=1);

use App\DTOs\Stats\DurationHistoryPoint;
use App\Models\User;
use App\Models\Workout;
use App\Services\Stats\WorkoutStatsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/*
 * `getDurationHistory` alimente la courbe de duree des seances.
 *
 * Rien ne verifiait son ordre, sa borne, ni le calcul de la duree elle-meme :
 * la requete trie du plus recent au plus ancien puis `reverse()` remet dans le
 * sens de lecture, et le mutant qui retirait ce `reverse()` affichait la courbe
 * a l'envers sans qu'aucun test ne bronche.
 */

/**
 * Une seance d'une duree donnee, un nombre de jours en arriere.
 */
function seanceDatee(User $user, int $joursEnArriere, int $minutes, ?string $nom = null): Workout
{
    $debut = now()->subDays($joursEnArriere)->setTime(10, 0);

    return Workout::factory()->create([
        'user_id' => $user->id,
        'name' => $nom,
        'started_at' => $debut,
        'ended_at' => $debut->copy()->addMinutes($minutes),
    ]);
}

it('rend les séances de la plus ancienne à la plus récente', function (): void {
    $user = User::factory()->create();

    seanceDatee($user, 1, 10, 'Hier');
    seanceDatee($user, 3, 20, 'Avant-hier');
    seanceDatee($user, 5, 30, 'La semaine dernière');

    $historique = app(WorkoutStatsService::class)->getDurationHistory($user);

    // Le sens compte : c'est l'axe des abscisses de la courbe.
    expect(array_map(fn (DurationHistoryPoint $point): string => $point->name, $historique))
        ->toBe(['La semaine dernière', 'Avant-hier', 'Hier']);
});

it('ne garde que les séances les plus récentes quand on borne', function (): void {
    $user = User::factory()->create();

    foreach ([1, 2, 3, 4, 5] as $joursEnArriere) {
        seanceDatee($user, $joursEnArriere, 10, "J-{$joursEnArriere}");
    }

    $historique = app(WorkoutStatsService::class)->getDurationHistory($user, 3);

    // Les trois plus RÉCENTES, et dans le sens de lecture : la borne s'applique
    // avant le retournement, pas après.
    expect(array_map(fn (DurationHistoryPoint $point): string => $point->name, $historique))
        ->toBe(['J-3', 'J-2', 'J-1']);
});

it('compte la durée en minutes et date au jour de début', function (): void {
    $user = User::factory()->create();

    $seance = seanceDatee($user, 2, 45, 'Séance de 45 minutes');

    $historique = app(WorkoutStatsService::class)->getDurationHistory($user);

    expect($historique)->toHaveCount(1)
        ->and($historique[0]->duration)->toBe(45)
        ->and($historique[0]->date)->toBe($seance->started_at->format('d/m'));
});

/**
 * Une seance en cours n'a pas de duree, elle n'a donc rien a faire dans la courbe.
 */
it('ignore une séance qui n’est pas terminée', function (): void {
    $user = User::factory()->create();

    seanceDatee($user, 2, 30, 'Terminée');

    Workout::factory()->create([
        'user_id' => $user->id,
        'name' => 'En cours',
        'started_at' => now()->subDay(),
        'ended_at' => null,
    ]);

    $historique = app(WorkoutStatsService::class)->getDurationHistory($user);

    expect(array_map(fn (DurationHistoryPoint $point): string => $point->name, $historique))
        ->toBe(['Terminée']);
});

it('ne mélange pas les séances de deux utilisateurs', function (): void {
    $user = User::factory()->create();
    $autre = User::factory()->create();

    seanceDatee($user, 2, 30, 'La mienne');
    seanceDatee($autre, 2, 30, 'Celle du voisin');

    $historique = app(WorkoutStatsService::class)->getDurationHistory($user);

    expect(array_map(fn (DurationHistoryPoint $point): string => $point->name, $historique))
        ->toBe(['La mienne']);
});

/**
 * La duree de vie du cache, encadree des deux cotes.
 *
 * Les tests de cache existants verifient qu'une cle est presente ou absente,
 * jamais combien de temps elle le reste : les mutants qui portaient la duree a 29
 * ou 31 minutes survivaient tous les deux. Un seul des deux instants ne
 * separerait rien — c'est le couple qui fixe la valeur.
 */
it('garde les statistiques en cache trente minutes, pas plus', function (string $methode, string $cle): void {
    $user = User::factory()->create();

    $maintenant = Carbon::parse('2026-06-15 12:00:00');
    Carbon::setTestNow($maintenant);

    seanceDatee($user, 2, 30, 'Peu importe');

    app(WorkoutStatsService::class)->{$methode}($user);

    $cleComplete = \App\Services\Stats\ClesDeStats::seances($user, $cle);

    expect(Cache::has($cleComplete))->toBeTrue();

    Carbon::setTestNow($maintenant->copy()->addMinutes(29)->addSeconds(59));
    expect(Cache::has($cleComplete))->toBeTrue('l’entrée a expiré avant trente minutes');

    Carbon::setTestNow($maintenant->copy()->addMinutes(30)->addSecond());
    expect(Cache::has($cleComplete))->toBeFalse('l’entrée a survécu au-delà de trente minutes');

    Carbon::setTestNow();
})->with([
    'historique de durée' => ['getDurationHistory', 'duration_history.20'],
    'répartitions' => ['getWorkoutDistributions', 'workout_distributions.90'],
]);
