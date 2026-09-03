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

        /*
         * Les trois decorateurs ci-dessus doivent envelopper la liaison de
         * modele, pas etre enveloppes par elle.
         *
         * `web(append:)` les place en fin de groupe, donc a l'INTERIEUR de
         * `SubstituteBindings`. Or `Pipeline::carry()` met un `catch` autour de
         * chaque maillon : quand la liaison ne trouve pas la ligne, c'est le
         * maillon qui l'enveloppe qui rend la reponse, et tout ce qui est plus
         * a l'interieur ne s'execute jamais. Un identifiant inconnu ressortait
         * donc sans `Content-Security-Policy` et sans `Vary: X-Inertia`, quand
         * un refus de policy — leve a la destination du pipeline — ressortait
         * avec les deux.
         *
         * C'est exactement l'oracle que #1418 et #1432 retirent du statut et du
         * corps, reapparu dans les en-tetes : `curl -I` suffisait a savoir si la
         * ressource existe. Mesure : 204 appels sur 204 distinguaient les deux
         * reponses, dans les deux ordres d'appel et sur les deux charges.
         *
         * On egalise vers le haut plutot que vers le bas — la page 404 est une
         * page, elle merite le meme CSP que les autres, qu'elle n'avait jamais
         * eu. Le prix est que le chemin absent prepare la page comme le chemin
         * present : c'est le prix de l'indiscernabilite, et c'est le meme
         * raisonnement qu'en #1433 pris dans l'autre sens, faute de pouvoir
         * retirer un travail qui est ici legitime.
         *
         * Aucun des trois ne lit la route : verifie avant de les deplacer, sans
         * quoi remonter au-dessus de la liaison leur retirerait ce qu'ils lisent.
         */
        foreach ([
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\ConditionalCspHeaders::class,
        ] as $decorateur) {
            $middleware->prependToPriorityList(
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
                $decorateur,
            );
        }

        $middleware->preventRequestForgery(except: [
            '_dusk/*',
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        \Sentry\Laravel\Integration::handles($exceptions);

        /*
         * Whether this caller is served JSON rather than a page.
         *
         * `api/v1/*` answers JSON whatever the request declares, which is what
         * #1418 settled and what its contract test holds. The web router also
         * serves JSON on a handful of routes — `stats.exercise` and
         * `exercises/{id}`, called in axios from `ExerciseProgressCard.vue` —
         * and there it is the Accept header that says so, exactly as it does
         * for the genuine 404 those routes already return.
         *
         * Inertia lands on the other side: it sends `X-Inertia` but asks for
         * `text/html`, so it receives the page. That is deliberate — see the
         * discreet answer below.
         */
        $speaksJson = static fn (\Illuminate\Http\Request $request): bool => $request->is('api/v1/*')
            || $request->expectsJson();

        /*
         * The JSON half of the single answer for "not yours" and "not there".
         *
         * Built once and shared by the handlers below, because the whole point
         * is that the answers are indistinguishable; separate literals could
         * drift apart without anyone noticing. It is a closure rather than a
         * constant because `artisan test -p` boots the application several
         * times in one process, and a file-scope constant cannot be declared
         * twice.
         */
        $jsonNotFound = static fn (): \Illuminate\Http\JsonResponse => response()->json(
            ['message' => 'Resource not found.'],
            404,
        );

        /*
         * The single answer, whichever router asked.
         *
         * On the web the indistinguishable answer is the 404 page — and it is
         * that page, not a copy of it: the handler is asked to render a
         * NotFoundHttpException, so the discreet answer travels the same code
         * path as the real one and cannot drift from it. Hand-building
         * `response()->view('errors.404')` would have matched today and stopped
         * matching the day someone gave `errors::404` a variable, a header or a
         * vendor override.
         *
         * That also settles Inertia, which #1432 asks about. The application
         * installs no Inertia error renderer, so an `X-Inertia` caller receives
         * raw HTML today — for the 403 as much as for the 404. Sending it
         * through the same render as the 404 keeps the two answers identical
         * without deciding what an Inertia error page should look like, which
         * is a product question and not this one.
         *
         * No recursion: the delegation happens only on the non-JSON branch,
         * while the NotFoundHttpException handler below only ever returns
         * `$jsonNotFound()`, which renders nothing.
         *
         * It does finalise twice, and that is the price of borrowing the real
         * render. `Handler::render()` ends in `finalizeRenderedResponse()`, so
         * this branch runs it once for the NotFoundHttpException it delegates
         * and once for the AccessDeniedHttpException it answers — measured, one
         * call everywhere else. Nothing reads that today: the application
         * installs no `$exceptions->respond()` callback. Whoever installs one
         * must make it idempotent, or this branch will apply it twice and every
         * other answer once, which is a fresh oracle in the shape of a fix for
         * the old one.
         *
         * `$exceptions->map()` would have converted the exception once, before
         * either render callback, and looked like the cleaner answer. It is not:
         * `mapException()` runs in `report()` as well as in `render()`, so the
         * predicate below would be evaluated twice per refusal — and Sentry
         * would receive a NotFoundHttpException instead of the refusal that
         * actually happened. #1418 hides the existence from the caller, not the
         * reason from the operator.
         */
        $notFound = static fn (\Illuminate\Http\Request $request): \Symfony\Component\HttpFoundation\Response => $speaksJson($request)
            ? $jsonNotFound()
            : app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->render(
                $request,
                new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(),
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
         * Unscoped, since #1432: the question it asks has no path in it. The
         * request must resolve a model from its URL and the caller must fail to
         * view it, which no page without a bound resource can do — not the
         * Filament panel, which
         * resolves its records itself and binds strings, not models.
         *
         * It is also what keeps the fix from swallowing the ordinary refusal.
         * On the web a redirect carrying an error bag is how a form tells its
         * author what they mistyped; answering 404 to every failed validation
         * would hold the disclosure test while breaking the application. A
         * caller who can view the row gets the ordinary refusal, and
         * `WebResourceDisclosureContractTest` holds that half too.
         */
        $hidesResource = static function (\Illuminate\Http\Request $request): bool {
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
         * Refusing a named resource must look like not finding one.
         *
         * The endpoints that resolve by model binding answered 403, which an
         * unknown id never does — and that difference is an oracle. Access
         * stayed refused either way; what leaked was existence. Walking the id
         * space told a caller how many sets exist and how fast they are created.
         *
         * Reported against three verbs in #1418; the contract test that came
         * with the fix counted 82. It was fixed on `api/v1/*` only, and #1432
         * then found the same rows reachable through the web router with the
         * same tell: 198 of the 204 calls a web sonde makes told an intruder
         * whether the resource existed.
         */
        $exceptions->render(
            // Caught as the Symfony exception, not as AuthorizationException:
            // the handler runs prepareException() before its render callbacks,
            // so the Laravel exception has already been converted by the time
            // we are asked.
            fn (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, \Illuminate\Http\Request $request): ?\Symfony\Component\HttpFoundation\Response => $hidesResource($request) ? $notFound($request) : null);

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
        $exceptions->render(fn (\Illuminate\Validation\ValidationException $e, \Illuminate\Http\Request $request): ?\Symfony\Component\HttpFoundation\Response => $hidesResource($request) ? $notFound($request) : null);

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
         *
         * Only where a body is read. The 404 page renders no message at all, so
         * a browser and an Inertia caller already see the same bytes whichever
         * exception produced them, and rewriting their answer would gain
         * nothing while costing every genuine `abort(404, '…')` its wording.
         * The JSON web routes do read it — that is where `stats.exercise` would
         * otherwise have answered "Resource not found." for the hidden row and
         * "No query results for model [App\Models\Exercise] 999999999" for the
         * absent one.
         */
        $exceptions->render(fn (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request): ?\Symfony\Component\HttpFoundation\Response => $speaksJson($request) ? $jsonNotFound() : null);
    })->create();
