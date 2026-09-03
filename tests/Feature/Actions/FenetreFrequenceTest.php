<?php

declare(strict_types=1);

use App\Actions\Workouts\FetchWorkoutsIndexAction;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

function seanceLeJour(User $user, string $jour): void
{
    Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::parse($jour)->setTime(10, 0),
        'ended_at' => Carbon::parse($jour)->setTime(11, 0),
    ]);
}

/**
 * Deux cartes voisines qui comptent des periodes differentes sans le dire.
 *
 * « Frequence Mensuelle » etait bornee a six mois, « Frequence par Jour » ne
 * l'etait pas : elle lisait toutes les seances du compte — 109 lectures
 * d'index a 30 seances anciennes, 1 249 a 600. Les deux tirent desormais la
 * meme fenetre de `debutDeLaFenetre()`, et les deux sous-titres l'annoncent.
 */
it('ne compte que les six derniers mois par jour de semaine', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-28 12:00:00'));

    $user = User::factory()->create();

    // Trois lundis dans la fenetre, un quatrieme bien avant.
    seanceLeJour($user, '2026-08-24');
    seanceLeJour($user, '2026-08-17');
    seanceLeJour($user, '2026-03-02');
    seanceLeJour($user, '2024-01-01');

    Cache::flush();
    $action = app(FetchWorkoutsIndexAction::class);
    $parJour = $action->getDeferredData($user)['charts']['day_of_week_frequency'];

    $lundi = $parJour->firstWhere('day', 'Lun');

    expect($lundi)->not->toBeNull()
        ->and($lundi['count'] ?? 0)->toBe(3);
});

it('borne les deux cartes de fréquence à la même fenêtre', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-28 12:00:00'));

    $user = User::factory()->create();

    // Le premier jour de la fenetre, et le dernier jour d'avant.
    seanceLeJour($user, '2026-03-01');
    seanceLeJour($user, '2026-02-28');

    Cache::flush();
    $action = app(FetchWorkoutsIndexAction::class);
    $graphiques = $action->getDeferredData($user)['charts'];

    $totalParJour = $graphiques['day_of_week_frequency']->sum('count');
    $totalParMois = $graphiques['monthly_frequency']->sum('count');

    expect($totalParJour)->toBe(1)
        ->and($totalParMois)->toBe(1);
});
