<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Support\RequetesMalFormees;

use function Pest\Laravel\actingAs;

/*
 * Aucune page du panneau ne répond 500 à une chaîne de requête mal formée
 * (#2071) : la moitié de `UneRequeteMalFormeeNeRepondPas500Test` pour les
 * pages du panneau, dont les classes vivent dans app/Filament, séparée pour
 * tourner en parallèle (une page du panneau coûte quatre fois une page
 * Inertia). Même portée, mêmes formes, mêmes limites. Les tables de Filament
 * lisaient `search` par `strval()` (un tableau répondait 500), Livewire
 * recopie dans l'état du composant chaque nom qu'il lie à la chaîne de
 * requête (un octet hors UTF-8 répondait 500), et la pagination de Livewire
 * prenait toute page : ces noms (`#[Url]`, sous leur alias) s'ajoutent à ceux
 * que la garde essaie. Un administrateur qui ouvre chaque page les visite ;
 * le limiteur du panneau est remis à zéro avant chaque requête.
 */

it('aucune page du panneau ne répond 500 à une chaîne de requête mal formée', function (): void {
    $routes = array_values(array_filter(RequetesMalFormees::routes(['GET']), RequetesMalFormees::duPanneau(...)));
    $administrateur = RequetesMalFormees::administrateur($routes);

    $visite = RequetesMalFormees::visiterChaquePage(
        $routes,
        RequetesMalFormees::valeursDeRoute(User::factory()->create()),
        static function (Route $route, string $url, array $entetes) use ($administrateur): TestResponse {
            Exceptions::fake();
            RateLimiter::clear('admin-panel:127.0.0.1');

            return actingAs($administrateur, 'admin')->get($url, $entetes);
        },
    );

    expect($visite['defauts'])->toBe([])
        ->and(count($routes))->toBeGreaterThanOrEqual(20)
        ->and($visite['requetes'])->toBe(5 * count($routes));
});
