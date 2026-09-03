<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * Habits, Supplements, Journal and body-part Measurements shipped routed, tested
 * and fully working — with nothing anywhere in the app linking to them. They were
 * reachable only by typing the URL, which on a phone means never. Nothing in the
 * suite noticed, because every one of those features had passing tests hitting
 * its route directly.
 *
 * Known limitation: this counts links, it does not walk reachability. A page
 * linked only from another unreachable page still counts as linked — which is
 * exactly how body-parts.index hid, referenced solely by its own detail view.
 * Catching that needs a graph traversal from the real entry points.
 */
it('leaves no authenticated page without a way in', function (): void {
    $referenced = referencedRouteNames();

    $unreachable = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => filled($route->getName()))
        ->filter(fn ($route): bool => in_array('GET', $route->methods(), true))
        // Parameterised routes are reached from a parent page, not a menu.
        ->filter(fn ($route): bool => ! str_contains($route->uri(), '{'))
        ->filter(fn ($route): bool => ! isThirdPartyPanel($route->getName()))
        ->filter(fn (\Illuminate\Routing\Route $route): bool => isBehindAuth($route))
        ->map(fn ($route): string => $route->getName())
        ->reject(fn (string $name): bool => in_array($name, allowedEntryPoints(), true))
        ->reject(fn (string $name): bool => $referenced->contains($name))
        ->values()
        ->all();

    expect($unreachable)->toBeEmpty(
        'No link anywhere in resources/js points to: '.implode(', ', $unreachable)
        .'. Either add an entry point or list it in allowedEntryPoints() with a reason.'
    );
});

/**
 * Two shapes are in use: direct `route('x.index')` calls, and menu definitions
 * that hold the name as data (`route: 'x.index'`) and resolve it at render time.
 * Only matching the first would miss every hub entry.
 *
 * @return \Illuminate\Support\Collection<int, string>
 */
function referencedRouteNames(): \Illuminate\Support\Collection
{
    $sources = collect(
        \Illuminate\Support\Facades\File::allFiles(resource_path('js'))
    )->filter(fn ($file): bool => in_array($file->getExtension(), ['vue', 'js'], true))
        ->map(fn ($file): string => (string) file_get_contents($file->getPathname()))
        ->implode("\n");

    preg_match_all(
        '/(?:route\(\s*[\'"]|route:\s*[\'"])([a-zA-Z0-9._-]+)[\'"]/',
        $sources,
        $matches
    );

    return collect($matches[1])->unique();
}

function isBehindAuth(\Illuminate\Routing\Route $route): bool
{
    return collect($route->gatherMiddleware())
        ->contains(fn ($middleware): bool => is_string($middleware)
            && str_contains(strtolower($middleware), 'auth'));
}

/**
 * Ships its own navigation and is not part of the Inertia frontend, so a sweep of
 * resources/js can say nothing useful about it.
 */
function isThirdPartyPanel(string $name): bool
{
    return array_any(['api.', 'filament.', 'horizon.', 'telescope', 'pulse'], fn (string $prefix): bool => str_starts_with($name, $prefix));
}

/**
 * Reached by means a link sweep cannot see, so absence here is not a defect.
 *
 * @return array<int, string>
 */
function allowedEntryPoints(): array
{
    return [
        // Redirect targets and layout-level destinations rather than menu entries.
        'dashboard',
        'profile.edit',
        'verification.notice',
        'password.confirm',

        // Known dead: Route::resource scaffolding with no controller method and no
        // page — Goals creates through an inline form on its index. Left listed so
        // it stays visible; removing the route is a separate call.
        'goals.create',
    ];
}
