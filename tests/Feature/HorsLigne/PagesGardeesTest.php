<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RouteLaravel;
use Illuminate\Support\Facades\Route;
use Tests\Support\PagesGardees;

/*
 * La liste blanche des pages gardées pour la salle (#2048) vit dans
 * `resources/js/Utils/pagesGardees.js`, que la page, et plus tard le worker et
 * `vite.config.js`, lisent sans Ziggy : chaque chemin y est écrit en dur.
 * Une route renommée ou déplacée laisserait la page garder, et plus tard
 * servir, une adresse que le serveur ne sert plus, sans que rien ne le dise.
 * Chaque chemin se compare donc à `route($nom, absolute: false)`.
 */

/**
 * La route que nomme une entrée de la liste, ou un échec qui le dit.
 */
function pagesGardeesRoute(string $nom): RouteLaravel
{
    $route = Route::getRoutes()->getByName($nom);

    expect($route)->toBeInstanceOf(RouteLaravel::class, "La route `{$nom}` de `PAGES_GARDEES` n’existe pas.");

    /** @var RouteLaravel $route */
    return $route;
}

/**
 * Chaque entrée de la liste, par son composant : composant, route, chemin.
 *
 * @return array<string, array{string, string, string}>
 */
function pagesGardeesJeu(): array
{
    $jeu = [];

    foreach (PagesGardees::liste() as $page) {
        $jeu[$page['composant']] = [$page['composant'], $page['route'], $page['chemin']];
    }

    return $jeu;
}

it('écrit chaque chemin comme sa route le donne', function (string $composant, string $nom, string $chemin): void {
    $route = pagesGardeesRoute($nom);
    /** @var list<string> $parametres */
    $parametres = $route->parameterNames();

    preg_match_all('/\{(\w+)\}/', $chemin, $segments);

    expect($segments[1])->toBe($parametres, "Les segments variables de `{$chemin}` ne sont pas ceux de la route `{$nom}`.");

    expect((string) preg_replace('/\{\w+\}/', '4242', $chemin))->toBe(route($nom, array_fill_keys($parametres, 4242), absolute: false));
})->with(fn (): array => pagesGardeesJeu());

it('ne garde qu’une page de compte, servie en GET', function (string $composant, string $nom): void {
    $route = pagesGardeesRoute($nom);

    expect($route->methods())->toContain('GET')
        ->and($route->gatherMiddleware())->toContain('auth');
})->with(fn (): array => pagesGardeesJeu());

it('nomme un composant que l’application a', function (string $composant): void {
    expect(resource_path("js/Pages/{$composant}.vue"))->toBeReadableFile();
})->with(fn (): array => pagesGardeesJeu());

it('lit chaque ligne de la liste sous la forme d’une entrée', function (): void {
    expect(PagesGardees::liste())->not->toBeEmpty();
});

it('ne nomme chaque composant et chaque route qu’une fois', function (): void {
    $liste = PagesGardees::liste();

    expect(array_column($liste, 'composant'))->toBe(array_values(array_unique(array_column($liste, 'composant'))))
        ->and(array_column($liste, 'route'))->toBe(array_values(array_unique(array_column($liste, 'route'))));
});
