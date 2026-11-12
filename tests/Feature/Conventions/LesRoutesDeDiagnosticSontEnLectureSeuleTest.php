<?php

declare(strict_types=1);

use App\Http\Middleware\ConditionalCspHeaders;
use App\Http\Middleware\ExigeUnJetonDeDiagnostic;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/*
 * Les routes de l'accès de diagnostic (#2032) restent en lecture seule, sous
 * leur garde, hors des groupes `web` et `api` : une route qui y manquerait à
 * l'une de ces règles ouvrirait la lecture sans jeton, ou une écriture.
 */
/**
 * @return list<Route>
 */
function lesRoutesDeDiagnostic(): array
{
    return array_values(array_filter(
        resolve(Router::class)->getRoutes()->getRoutes(),
        static fn (Route $route): bool => str_starts_with((string) $route->getName(), 'diagnostic.'),
    ));
}

it('enregistre des routes de diagnostic', function (): void {
    expect(array_map(static fn (Route $route): ?string => $route->getName(), lesRoutesDeDiagnostic()))
        ->toContain('diagnostic.etat', 'diagnostic.sante', 'diagnostic.exceptions.index', 'diagnostic.exceptions.show', 'diagnostic.exceptions.occurrences');
});

it('ne sert chaque route de diagnostic qu’en GET et HEAD', function (): void {
    foreach (lesRoutesDeDiagnostic() as $route) {
        expect($route->methods())->toBe(['GET', 'HEAD'], (string) $route->getName());
    }
});

it('déclare une portée pour chaque route de diagnostic, et chaque portée déclarée a sa route', function (): void {
    $noms = array_map(static fn (Route $route): ?string => $route->getName(), lesRoutesDeDiagnostic());

    expect(array_diff($noms, array_keys(ExigeUnJetonDeDiagnostic::PORTEES)))->toBe([])
        ->and(array_diff(array_keys(ExigeUnJetonDeDiagnostic::PORTEES), $noms))->toBe([]);
});

it('pose la garde en premier maillon de chaque route de diagnostic, sans session ni liaison de modèle', function (): void {
    $routeur = resolve(Router::class);

    foreach (lesRoutesDeDiagnostic() as $route) {
        $maillons = $routeur->gatherRouteMiddleware($route);

        expect($maillons[0] ?? null)->toBe(ExigeUnJetonDeDiagnostic::class, (string) $route->getName())
            ->and($maillons)->toContain(ConditionalCspHeaders::class)
            ->and($maillons)->not->toContain('web', 'api', SubstituteBindings::class)
            ->and(array_search(ExigeUnJetonDeDiagnostic::class, $maillons, true))->toBeLessThan((int) array_search(ConditionalCspHeaders::class, $maillons, true));
    }
});

it('ne laisse aucun paramètre de route de diagnostic se lier à un modèle', function (): void {
    foreach (lesRoutesDeDiagnostic() as $route) {
        expect($route->bindingFields())->toBe([])
            ->and($route->signatureParameters(['subClass' => Illuminate\Database\Eloquent\Model::class]))->toBe([]);
    }
});
