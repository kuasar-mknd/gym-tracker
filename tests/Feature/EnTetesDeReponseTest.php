<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Vite;
use Illuminate\Routing\Route as RouteLaravel;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les en-têtes d'une page complète tiennent dans le tampon du proxy de DSM.
 *
 * En production, l'application répond derrière le proxy inverse de DSM. Son
 * nginx lit tous les en-têtes d'une réponse dans un tampon de 4 Kio
 * (`proxy_buffer_size`, une page mémoire, que le gabarit de DSM ne change pas) :
 * au-delà, il consigne « upstream sent too big header » et rend un 502, que DSM
 * habille de sa page « Désolé, la page que vous recherchez est introuvable ».
 *
 * C'est arrivé : l'en-tête `Link` de `AddLinkHeadersForPreloadedAssets` portait
 * 2 293 octets sur l'accueil, et `/dashboard` chargé en entier envoyait 4 355
 * octets d'en-têtes. Ouvrir la PWA avec le cookie « se souvenir de moi » menait
 * droit sur ce 502, `/login` aussi puisqu'il renvoie l'utilisateur connecté à
 * l'accueil — et seule une navigation Inertia, qui ne porte pas l'en-tête,
 * passait encore.
 *
 * Le budget laisse 1 Kio de marge sous la limite : Caddy ajoute ses propres
 * en-têtes (`Server`, `Content-Encoding`, `Vary`, `Transfer-Encoding`, une
 * centaine d'octets), et le vrai nom d'hôte peut être plus long que celui-ci.
 */
function enTetesBudgetEnOctets(): int
{
    return 3 * 1024;
}

/**
 * Un nom d'hôte de NAS plausible, plutôt long : toute URL absolue d'un en-tête
 * (`Location`, ou un `Link` qui reviendrait) grandit avec lui.
 */
function enTetesHoteDeProduction(): string
{
    return 'https://gym-tracker.un-nas-au-nom-assez-long.synology.me';
}

/**
 * La taille du bloc d'en-têtes tel que nginx le lit : ligne de statut, une
 * ligne « Nom: valeur » par en-tête et par cookie, puis la ligne vide.
 *
 * `(string) $reponse->headers` ne convient pas : il aligne les noms en les
 * complétant d'espaces, ce qui gonfle le compte de plusieurs centaines d'octets.
 */
function enTetesTailleDuBloc(TestResponse|Response $reponse): int
{
    // Après `followingRedirects()`, la réponse de base est elle-même enveloppée.
    while ($reponse instanceof TestResponse) {
        $reponse = $reponse->baseResponse;
    }

    $taille = strlen(sprintf(
        'HTTP/%s %d %s',
        $reponse->getProtocolVersion(),
        $reponse->getStatusCode(),
        Response::$statusTexts[$reponse->getStatusCode()] ?? '',
    )) + 2;

    foreach ($reponse->headers->all() as $nom => $valeurs) {
        foreach ($valeurs as $valeur) {
            $taille += strlen((string) $nom) + 2 + strlen((string) $valeur) + 2;
        }
    }

    return $taille + 2;
}

/**
 * Les pages GET de l'application, sans paramètre, servies par le groupe web.
 *
 * Le panneau d'administration a sa propre pile de middlewares, et les routes
 * des paquets ne rendent pas nos pages : seules comptent les actions de `App\`,
 * les fermetures de `routes/web.php`, et les pages déclarées directement par
 * `Route::inertia()` ou `Route::view()`.
 *
 * @return list<RouteLaravel>
 */
function enTetesPagesDeLApplication(): array
{
    $controleursDePage = ['Inertia\\Controller', 'Illuminate\\Routing\\ViewController'];

    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteLaravel $route): bool => in_array('GET', $route->methods(), true))
        ->filter(fn (RouteLaravel $route): bool => ! str_contains($route->uri(), '{'))
        ->filter(fn (RouteLaravel $route): bool => in_array('web', $route->gatherMiddleware(), true))
        ->filter(fn (RouteLaravel $route): bool => str_starts_with($route->getActionName(), 'App\\')
            || in_array(ltrim($route->getActionName(), '\\'), $controleursDePage, true)
            || ($route->getActionName() === 'Closure' && ! str_starts_with($route->uri(), '_')))
        ->values()
        ->all();
}

/**
 * Si la route exige une connexion, garde nommée ou non (`auth`, `auth:web`).
 */
function enTetesExigeUneConnexion(RouteLaravel $route): bool
{
    return collect($route->gatherMiddleware())
        ->contains(fn (mixed $middleware): bool => $middleware === 'auth'
            || (is_string($middleware) && str_starts_with($middleware, 'auth:')));
}

/**
 * Les pages qui redirigent même visitées dans le bon état : `/` mène toujours
 * à l'accueil, et la demande de vérification renvoie un compte déjà vérifié.
 *
 * @return list<string>
 */
function enTetesRedirectionsAttendues(): array
{
    return ['/', '/verify-email'];
}

/**
 * Ce qu'Octane remet à zéro entre deux requêtes, et que la suite de tests garde
 * sinon d'une requête à l'autre : la garde, la session, les actifs préchargés
 * par Vite (`FlushVite`) et les cookies mis en file (`FlushQueuedCookies`).
 * Sans eux, une page mesurerait aussi ce que la précédente a laissé.
 */
function enTetesRemettreAZeroCommeOctane(): void
{
    app('auth')->forgetGuards();
    app(Vite::class)->flush();
    app('cookie')->flushQueuedCookies();
    app('session')->flush();
}

beforeEach(function (): void {
    // Comme en production : le cookie de session y porte l'attribut `secure`.
    config(['session.secure' => true]);

    // Toujours le manifeste construit, jamais le serveur de développement : un
    // `npm run dev` ouvert remplacerait les préchargements par une seule
    // balise, et le test ne mesurerait plus ce qui part en production.
    $this->app->make(Vite::class)->useHotFile(storage_path('framework/testing/vite-hot-absent'));
});

it('garde les en-têtes de chaque page complète sous le budget', function (): void {
    $utilisateur = User::factory()->create();
    $pages = enTetesPagesDeLApplication();
    $tropLourdes = [];
    $nonRendues = [];

    foreach ($pages as $route) {
        enTetesRemettreAZeroCommeOctane();

        $chemin = '/'.ltrim($route->uri(), '/');
        $requete = enTetesExigeUneConnexion($route) ? $this->actingAs($utilisateur) : $this;
        $reponse = $requete->get(enTetesHoteDeProduction().$chemin);
        $taille = enTetesTailleDuBloc($reponse);

        /*
         * Une mesure ne vaut que sur la page que `@vite` a rendue : un 500, ou
         * une redirection due au mauvais état de connexion, passerait sous le
         * budget sans rien prouver.
         */
        $renduParVite = $reponse->isOk() && str_contains((string) $reponse->getContent(), 'modulepreload');
        $redirectionAttendue = $reponse->isRedirect() && in_array($chemin, enTetesRedirectionsAttendues(), true);

        if (! $renduParVite && ! $redirectionAttendue) {
            $nonRendues[] = "{$chemin} ({$reponse->status()})";
        }

        if ($taille > enTetesBudgetEnOctets()) {
            $tropLourdes[] = "{$chemin} ({$reponse->status()}) : {$taille} octets";
        }
    }

    expect($pages)->not->toBeEmpty()
        ->and($nonRendues)->toBeEmpty(
            'Ces pages n’ont pas été rendues en entier, leur mesure ne prouve rien : '.implode(', ', $nonRendues)
            .'. Une redirection voulue s’ajoute à enTetesRedirectionsAttendues().'
        )
        ->and($tropLourdes)->toBeEmpty(
            'Ces réponses dépassent '.enTetesBudgetEnOctets().' octets d’en-têtes, et le proxy de DSM rend un 502 '
            .'au-delà de 4 Kio : '.implode(', ', $tropLourdes)
            .'. Un en-tête `Link` qui liste les actifs préchargés est le suspect habituel.'
        );
});

/**
 * Le cas du signalement, de bout en bout et étape par étape : la connexion
 * avec « se souvenir de moi », puis, des semaines plus tard, la PWA qui s'ouvre
 * sur `/` avec ce seul cookie et suit la redirection jusqu'à un accueil chargé
 * en entier — c'est-à-dire rendu par `@vite`, là où l'en-tête `Link` se
 * remplissait.
 *
 * La réponse à la connexion est la plus chargée de l'application : elle pose
 * trois cookies (session, XSRF, et celui qui se souvient). Si elle passait la
 * limite, le 502 tomberait à la connexion même, et effacer les cookies ne
 * suffirait plus.
 */
it('ouvre l’accueil en entier depuis le seul cookie « se souvenir de moi » sous le budget', function (): void {
    $utilisateur = User::factory()->create();

    $nomDuCookie = $this->app['auth']->guard('web')->getRecallerName();

    $connexion = $this->post(enTetesHoteDeProduction().'/login', [
        'email' => $utilisateur->email,
        'password' => 'password',
        'remember' => true,
    ]);
    $cookie = $connexion->getCookie($nomDuCookie);

    expect($cookie)->not->toBeNull()
        ->and($connexion->baseResponse->headers->getCookies())->toHaveCount(3)
        ->and(enTetesTailleDuBloc($connexion))->toBeLessThanOrEqual(enTetesBudgetEnOctets());

    // Des semaines plus tard : la session a expiré, seul le cookie reste.
    enTetesRemettreAZeroCommeOctane();

    $redirection = $this->withCookie($nomDuCookie, (string) $cookie?->getValue())
        ->get(enTetesHoteDeProduction().'/');

    $redirection->assertRedirect(enTetesHoteDeProduction().'/dashboard');

    expect(enTetesTailleDuBloc($redirection))->toBeLessThanOrEqual(enTetesBudgetEnOctets());

    enTetesRemettreAZeroCommeOctane();

    $reponse = $this->get((string) $redirection->headers->get('Location'));

    $reponse->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('Dashboard'));

    expect($this->app['auth']->guard('web')->viaRemember())->toBeTrue()
        ->and((string) $reponse->getContent())->toContain('modulepreload')
        ->and(enTetesTailleDuBloc($reponse))->toBeLessThanOrEqual(enTetesBudgetEnOctets());
});

/**
 * La séance en cours est l'autre écran qui s'ouvre sans réseau, et le plus
 * lourd en morceaux : on la recharge en pleine séance.
 */
it('garde la séance en cours sous le budget', function (): void {
    $utilisateur = User::factory()->create();
    $seance = Workout::factory()->for($utilisateur)->create(['ended_at' => null]);

    $reponse = $this->actingAs($utilisateur)
        ->get(enTetesHoteDeProduction().route('workouts.show', $seance, absolute: false));

    $reponse->assertOk();

    expect(enTetesTailleDuBloc($reponse))->toBeLessThanOrEqual(enTetesBudgetEnOctets());
});
