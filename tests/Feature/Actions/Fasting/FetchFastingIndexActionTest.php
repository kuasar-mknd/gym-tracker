<?php

declare(strict_types=1);

/*
 * `FetchFastingIndexAction` etait execute par FastingControllerTest, qui ne
 * creait jamais assez de jeunes pour qu'une page se remplisse : la taille de
 * page passait de 10 a 9 ou a 11 sans qu'une seule assertion bronche.
 *
 * Ce que cela laissait passer : une pagination silencieusement decalee, donc
 * un jeune qui disparait de la premiere page — ou un de trop qui s'y invite —
 * sans que rien ne le signale.
 */

use App\Actions\Fasting\FetchFastingIndexAction;
use App\Models\Fast;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Onze jeunes termines, plus un en cours.
 *
 * Onze, et pas dix : c'est le premier compte auquel la page deborde, donc le
 * seul ou la taille de page est observable. Les `created_at` sont poses un par
 * jour pour que `latest()` ait un ordre defini — la fabrique les tirerait tous
 * a la meme seconde, et le tri serait alors laisse au hasard de MySQL.
 *
 * `target_duration_minutes` sert d'etiquette : le jeune numero N vaut N.
 */
function scenePourHistoriqueDeJeunes(): User
{
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00'));

    $user = User::factory()->create();

    foreach (range(1, 11) as $rang) {
        Fast::factory()->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'target_duration_minutes' => $rang,
            'created_at' => Carbon::parse('2026-06-01 08:00:00')->addDays($rang),
        ]);
    }

    Fast::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'target_duration_minutes' => 999,
        'created_at' => Carbon::parse('2026-06-17 07:00:00'),
    ]);

    return $user;
}

it('pagine l historique par dix, du plus recent au plus ancien', function (): void {
    $user = scenePourHistoriqueDeJeunes();

    /** @var \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Fast> $historique */
    $historique = app(FetchFastingIndexAction::class)->execute($user)['history'];

    expect($historique)->toBeInstanceOf(LengthAwarePaginator::class);

    // Dix par page, ni neuf ni onze. La taille annoncee et le contenu reellement
    // rendu : les deux, parce que `perPage()` seul ne dirait pas que la requete
    // a bien coupe, et le compte seul ne dirait pas ce que la page annonce a
    // l'interface.
    expect($historique->perPage())->toBe(10);
    expect($historique->items())->toHaveCount(10);
    expect($historique->total())->toBe(11);
    expect($historique->lastPage())->toBe(2);

    // Les dix plus recents, dans l'ordre : le onzieme (le plus ancien) est
    // repousse en page deux. Une page de neuf perdrait le 2, une page de onze
    // le rapatrierait.
    expect(collect($historique->items())->pluck('target_duration_minutes')->all())
        ->toBe([11, 10, 9, 8, 7, 6, 5, 4, 3, 2]);
});

it('sort le jeune en cours de l historique et le rend a part', function (): void {
    $user = scenePourHistoriqueDeJeunes();

    $donnees = app(FetchFastingIndexAction::class)->execute($user);

    /** @var \App\Models\Fast $enCours */
    $enCours = $donnees['activeFast'];

    /** @var \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Fast> $historique */
    $historique = $donnees['history'];

    expect($enCours)->toBeInstanceOf(Fast::class);
    expect($enCours->target_duration_minutes)->toBe(999);

    // Le jeune en cours est le plus recent de tous : sans le filtre de statut
    // il occuperait la premiere ligne de l'historique.
    expect(collect($historique->items())->pluck('target_duration_minutes')->all())
        ->not->toContain(999);
});
