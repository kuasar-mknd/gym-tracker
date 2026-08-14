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
         * Built once and shared by both handlers below, because the whole point
         * is that the two are indistinguishable; two literals could drift apart
         * without anyone noticing.
         *
         * A closure rather than a constant: `artisan test -p` boots the
         * application several times in one process, and a file-scope constant
         * cannot be declared twice — which is how the first attempt failed CI
         * while passing every local run, none of which was parallel.
         */
        $notFound = static fn (): \Illuminate\Http\JsonResponse => response()->json(
            ['message' => 'Resource not found.'],
            404,
        );

        /*
         * On the API, refusing a named resource must look like not finding one.
         *
         * The creation endpoints already behave that way: ownership rides in the
         * validation rule, so `Rule::exists(...)->where('user_id', $id)` answers
         * 422 whether the id belongs to someone else or to nobody. The endpoints
         * that resolve by model binding answered 403, which an unknown id never
         * does — and that difference is an oracle. Access stayed refused either
         * way; what leaked was existence. Walking the id space told a caller how
         * many sets exist and how fast they are created.
         *
         * Reported against three verbs in #1418; the contract test that came
         * with the fix counted 82.
         *
         * One kind of denial is converted: the one where the caller cannot see
         * the resource at all. A refused `viewAny` or `create` says nothing
         * about anyone's data — the ability is about the collection — and a
         * refusal to touch a resource the caller *can* view is about the action,
         * not about access: editing one's own finished session is refused
         * because the session is closed, and answering "not found" about a row
         * the user is looking at would be a lie that protects nothing.
         *
         * `view` is what separates the two, which is why it is asked here rather
         * than left to each policy to declare. A policy with no `view` at all
         * denies, and the answer is the discreet one.
         */
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, \Illuminate\Http\Request $request) use ($notFound): ?\Symfony\Component\HttpFoundation\Response {
            if (! $request->is('api/*')) {
                return null;
            }

            $route = $request->route();

            if (! $route instanceof \Illuminate\Routing\Route) {
                return null;
            }

            $resource = collect($route->parameters())
                ->first(fn (mixed $parameter): bool => $parameter instanceof \Illuminate\Database\Eloquent\Model);

            if (! $resource instanceof \Illuminate\Database\Eloquent\Model) {
                return null;
            }

            if ($request->user()?->can('view', $resource) === true) {
                return null;
            }

            return $notFound();
        });

        /*
         * And the two answers have to read alike, not merely count alike.
         *
         * Model binding reports `No query results for model [App\Models\Set] 12`,
         * which production does show: an HttpException's message is deemed safe
         * and is rendered verbatim. Leaving it in place would move the oracle
         * from the status line into the body rather than close it.
         *
         * The neutral message is used on both paths, so the two responses are
         * identical by construction rather than by a wording that has to be kept
         * in step with the framework's.
         */
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) use ($notFound): ?\Symfony\Component\HttpFoundation\Response {
            if (! $request->is('api/*')) {
                return null;
            }

            return $notFound();
        });
    })->create();
