<?php

declare(strict_types=1);

/*
 * `parsePeriod()` etait executee par les tests de la page statistiques sans que
 * deux de ses cinq branches soient jamais verifiees : celle de « 30j » et le
 * repli.
 *
 * Ce que cela laissait passer : decaler l'une ou l'autre a 29 ou a 31 jours
 * passait la suite au vert, alors que ce nombre est la fenetre de toutes les
 * courbes de la page.
 */

use App\Actions\Stats\FetchStatsOverviewAction;

it('traduit chaque periode connue en un nombre de jours exact', function (string $periode, int $jours): void {
    expect(app(FetchStatsOverviewAction::class)->parsePeriod($periode))->toBe($jours);
})->with([
    ['7j', 7],
    ['30j', 30],
    ['90j', 90],
    ['1a', 365],
]);

it('retombe sur trente jours pour une periode inconnue', function (): void {
    // Le repli a son propre test : le jeu ci-dessus n'atteint jamais la branche
    // `default`, donc il ne peut rien dire de ce qu'elle vaut.
    expect(app(FetchStatsOverviewAction::class)->parsePeriod('6 mois'))->toBe(30);
});
