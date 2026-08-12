<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

uses(Tests\TestCase::class);

/**
 * Resolve the controller class and method a route dispatches to.
 *
 * @return array{0: string, 1: string}|null Null when the route is not backed by a first-party controller.
 */
function firstPartyControllerTarget(string $action): ?array
{
    if (! str_starts_with($action, 'App\\')) {
        return null;
    }

    // Invokable controllers are reported without the "@method" suffix.
    return str_contains($action, '@')
        ? explode('@', $action, 2)
        : [$action, '__invoke'];
}

it('dispatches every route to a controller method that exists', function (): void {
    $broken = [];

    foreach (Route::getRoutes() as $route) {
        $target = firstPartyControllerTarget($route->getActionName());

        if ($target === null) {
            continue;
        }

        [$class, $method] = $target;

        if (! class_exists($class)) {
            $broken[] = sprintf('%s (%s) → classe %s introuvable', $route->uri(), $route->getName() ?? '-', $class);

            continue;
        }

        // Livewire/Filament page classes are routed on the class alone and handle
        // dispatch internally, so they legitimately have no __invoke.
        if ($method === '__invoke' && is_subclass_of($class, Livewire\Component::class)) {
            continue;
        }

        if (! method_exists($class, $method)) {
            $broken[] = sprintf('%s (%s) → %s::%s() n\'existe pas', $route->uri(), $route->getName() ?? '-', $class, $method);
        }
    }

    expect($broken)->toBe([]);
});
