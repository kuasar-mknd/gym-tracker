<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]);

        $middleware->prepend(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->statefulApi();

        $middleware->api(append: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\ConditionalCspHeaders::class,
        ]);

        $middleware->preventRequestForgery(except: [
            'api/*',
            '_dusk/*',
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        \Sentry\Laravel\Integration::handles($exceptions);

        /*
         * The single answer the API gives for "not yours" and "not there".
         *
         * Built once and shared by the handlers below, because the whole point
         * is that the answers are indistinguishable; separate literals could
         * drift apart without anyone noticing. It is a closure rather than a
         * constant because `artisan test -p` boots the application several
         * times in one process, and a file-scope constant cannot be declared
         * twice.
         */
        $notFound = static fn (): \Illuminate\Http\JsonResponse => response()->json(
            ['message' => 'Resource not found.'],
            404,
        );

        /*
         * Whether this refusal is about a resource the caller cannot even see.
         *
         * That is the line between a refusal worth admitting and one that would
         * confirm an existence. A refused `viewAny` or `create` says nothing
         * about anyone's data — the ability is about the collection. A refusal
         * to touch a resource the caller *can* view is about the action, not
         * about access: editing one's own finished session is refused because
         * the session is closed, and answering "not found" about a row the user
         * is looking at would be a lie that protects nothing.
         *
         * `view` is what separates them, asked here rather than declared policy
         * by policy. A policy with no `view` at all denies, and the answer is
         * the discreet one.
         *
         * Scoped to `api/v1/*` rather than `api/*`: the JSON API lives there,
         * while `api/documentation` is the Swagger UI, an HTML page that binds
         * no model and has no business receiving a JSON error body.
         */
        $hidesResource = static function (\Illuminate\Http\Request $request): bool {
            if (! $request->is('api/v1/*')) {
                return false;
            }

            $route = $request->route();

            if (! $route instanceof \Illuminate\Routing\Route) {
                return false;
            }

            $resource = collect($route->parameters())
                ->first(fn (mixed $parameter): bool => $parameter instanceof \Illuminate\Database\Eloquent\Model);

            if (! $resource instanceof \Illuminate\Database\Eloquent\Model) {
                return false;
            }

            return $request->user()?->can('view', $resource) !== true;
        };

        /*
         * On the API, refusing a named resource must look like not finding one.
         *
         * The endpoints that resolve by model binding answered 403, which an
         * unknown id never does — and that difference is an oracle. Access
         * stayed refused either way; what leaked was existence. Walking the id
         * space told a caller how many sets exist and how fast they are created.
         *
         * Reported against three verbs in #1418; the contract test that came
         * with the fix counted 82.
         */
        $exceptions->render(
            // Caught as the Symfony exception, not as AuthorizationException:
            // the handler runs prepareException() before its render callbacks,
            // so the Laravel exception has already been converted by the time
            // we are asked.
            fn (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, \Illuminate\Http\Request $request): ?\Symfony\Component\HttpFoundation\Response => $hidesResource($request) ? $notFound() : null);

        /*
         * The same, for the refusal that arrives dressed as a validation error.
         *
         * A FormRequest validates before the controller authorises, so a
         * malformed payload aimed at someone else's row answered 422 while the
         * same payload aimed at an id that does not exist answered 404. The
         * status-code fix above never sees those: authorisation is never
         * reached. Twelve routes leaked this way, and the first version of the
         * contract test could not see it — it sent no body at all, which the
         * `nullable` rules let through.
         *
         * Handled here rather than by moving authorisation into each of the
         * twenty-four request classes. That was the first attempt: it needed a
         * trait resolving the bound model by type, which failed open on three
         * separate inputs — no bound model, two models of the same type, a
         * model of an unexpected type — each of which authorised a request it
         * should have refused. One gate that cannot be forgotten beats
         * twenty-four that can, and this one has nothing to fail open with.
         */
        $exceptions->render(fn (\Illuminate\Validation\ValidationException $e, \Illuminate\Http\Request $request): ?\Symfony\Component\HttpFoundation\Response => $hidesResource($request) ? $notFound() : null);

        /*
         * And the two answers have to read alike, not merely count alike.
         *
         * Model binding reports `No query results for model [App\Models\Set] 12`,
         * which production does show: an HttpException's message is deemed safe
         * and is rendered verbatim. Leaving it in place would move the oracle
         * from the status line into the body rather than close it.
         *
         * The neutral message is used on every path, so the responses are
         * identical by construction rather than by a wording that has to be
         * kept in step with the framework's.
         */
        $exceptions->render(fn (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request): ?\Symfony\Component\HttpFoundation\Response => $request->is('api/v1/*') ? $notFound() : null);
    })->create();
