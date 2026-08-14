<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/**
 * Reaching for someone else's resource must look like reaching for one that
 * does not exist.
 *
 * The creation endpoints already behave that way, because they carry ownership
 * in the validation rule: `Rule::exists(...)->where('user_id', $userId)` answers
 * 422 whether the id belongs to someone else or to nobody. The endpoints that
 * resolve by model binding do not — they answer 403, which is distinguishable
 * from the 404 an unknown id produces.
 *
 * That difference is an oracle. Access stays refused either way, so nothing
 * leaks by content; what leaks is existence. Walking the id space tells an
 * attacker how many sets exist and how fast they are created — and the gap is
 * the more glaring for the creation endpoints taking care not to give it.
 *
 * Reported as #1418 against three verbs, found by comparing the two families.
 * This test does not take that count on trust: it walks every API route that
 * carries a model parameter and checks each one.
 */

/**
 * The model a route resolves, read off the controller's signature.
 *
 * Route model binding is declared by type hint and nowhere else, so the type
 * hint is what the route means by its parameter.
 */
/**
 * @return class-string<Model>|null
 */
function boundModelClass(\Illuminate\Routing\Route $route): ?string
{
    $action = $route->getAction('uses');

    if (! is_string($action) || ! str_contains($action, '@')) {
        return null;
    }

    [$controller, $method] = explode('@', $action);

    if (! class_exists($controller) || ! method_exists($controller, $method)) {
        return null;
    }

    foreach (new ReflectionMethod($controller, $method)->getParameters() as $parameter) {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            continue;
        }

        /** @var class-string $candidate */
        $candidate = $type->getName();

        if (is_subclass_of($candidate, Model::class)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Every API route that resolves exactly one model from the URL.
 *
 * @return list<array{name: string, method: string, uri: string, model: class-string<Model>}>
 */
function boundApiRoutes(): array
{
    $found = [];

    /** @var \Illuminate\Routing\RouteCollectionInterface $collection */
    $collection = Route::getRoutes();

    foreach ($collection->getRoutes() as $route) {
        $name = $route->getName() ?? '';

        if (! str_starts_with($name, 'api.v1.')) {
            continue;
        }

        if (count($route->parameterNames()) !== 1) {
            continue;
        }

        $model = boundModelClass($route);

        if ($model === null) {
            continue;
        }

        $verbs = [];

        foreach ($route->methods() as $verb) {
            // HEAD is registered alongside every GET and would double the sweep
            // without probing anything a GET does not already probe.
            if (is_string($verb) && $verb !== 'HEAD') {
                $verbs[] = $verb;
            }
        }

        $found[] = [
            'name' => $name,
            'method' => $verbs[0] ?? 'GET',
            'uri' => $route->uri(),
            'model' => $model,
        ];
    }

    return $found;
}

/**
 * Every route is walked in one test rather than one test per route: the routes
 * can only be enumerated once the application has booted, which is after Pest
 * has resolved its datasets. Collecting the offenders and reporting them
 * together also beats stopping at the first, for a contract meant to describe
 * the whole surface.
 */
it('answers the same for a resource owned by someone else as for one that does not exist', function (): void {
    $routes = boundApiRoutes();

    // A guard against the enumeration silently finding nothing — an empty list
    // would make this test pass while checking not one route.
    expect($routes)->not->toBeEmpty();

    $intruder = User::factory()->create();
    $discloses = [];
    $checked = 0;

    foreach ($routes as $route) {
        // The factory chain brings its own owner, which is what we want: any
        // owner that is not the intruder. Only User has to be pinned, since
        // there the resource and its owner are the same row.
        // Reached through the framework's own resolver rather than through the
        // model, so a model that does not declare HasFactory is still covered
        // instead of quietly falling out of the sweep.
        $foreign = \Illuminate\Database\Eloquent\Factories\Factory::factoryForModel($route['model'])->createOne();

        /** @var int|string $key */
        $key = $foreign->getKey();

        $path = fn (string $id): string => '/'.(preg_replace('/\{[^}]+\}/', $id, $route['uri']));

        $this->actingAs($intruder, 'sanctum');

        // An id far past anything the factories produce. Deleting the foreign
        // row to reuse its id would be tidier still, but soft deletes and
        // cascades make "absent" mean different things per model.
        /*
         * A resource the caller may simply read is not being hidden, so nothing
         * about it can leak: exercises and achievements are the library, shared
         * by everyone. Being told that one of them may not be edited discloses
         * an existence a plain GET already returns.
         *
         * The check is that GET, not the policy the fix consults — an outside
         * observation of what the API gives away, so the test is not merely
         * agreeing with the implementation it guards.
         */
        /** @var \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse> $readable */
        $readable = $this->json('GET', $path((string) $key));

        if ($readable->getStatusCode() < 300) {
            continue;
        }

        /** @var \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse> $absent */
        $absent = $this->json($route['method'], $path('999999999'));
        /** @var \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse> $present */
        $present = $this->json($route['method'], $path((string) $key));

        // Some verbs succeed on a foreign resource by design; there is no
        // refusal to disguise.
        if ($present->getStatusCode() < 300) {
            continue;
        }

        $checked++;

        /**
         * The body is compared as well as the status, because a matching pair of
         * 404s that read differently would only have moved the oracle into the
         * JSON. Only `message` is compared: with APP_DEBUG on, as it is here,
         * the payload also carries the file and line it was raised from, which
         * differ for reasons that are not disclosure and never reach production.
         */
        /** @param \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse> $response */
        $describe = static fn (\Illuminate\Testing\TestResponse $response): string => sprintf(
            '%d %s',
            $response->getStatusCode(),
            (string) json_encode($response->json('message')),
        );

        if ($describe($present) !== $describe($absent)) {
            $discloses[] = sprintf(
                '%s (%s): %s for a %s owned by someone else, %s for an unknown id',
                $route['name'],
                $route['method'],
                $describe($present),
                class_basename($route['model']),
                $describe($absent),
            );
        }
    }

    expect($checked)->toBeGreaterThan(0);

    expect($discloses)->toBe([], sprintf(
        "%d of %d protected routes tell an intruder that a resource exists:\n- %s",
        count($discloses),
        $checked,
        implode("\n- ", $discloses),
    ));
});
