<?php

declare(strict_types=1);

use App\Http\Middleware\VerifieLeCompteDeLEcriture;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\EntetesLus;
use Tests\Support\RequetesMalFormees;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\call;
use function Pest\Laravel\json;

/*
 * Aucune route de l'application ne répond 500 à une valeur inattendue d'un
 * en-tête qu'elle lit elle-même (#2094).
 *
 * `UneRequeteMalFormeeNeRepondPas500Test` fait varier la chaîne de requête
 * et le corps, jamais les en-têtes. Or l'application en lit quatre :
 * `Idempotency-Key` (les séries, les lignes et les séances), l'heure de
 * l'appareil au départ (`X-Heure-De-L-Appareil`), le compte d'une écriture
 * de la file (`X-Compte-De-L-Ecriture`) et la marque du préchauffage
 * (`X-Copies-Hors-Ligne`). Une heure de l'appareil suivie de mots démesurés
 * répondait 500 avant la validation (relu pendant #2093), une clé hors UTF-8
 * allait jusqu'à MySQL (#2071) : chacun avait son test, aucune garde ne
 * tenait la règle pour tous (`.ai/rules/controllers.md`).
 *
 * Le recensement. `Tests\Support\EntetesLus` lit le code de app/ et de
 * routes/ et y trouve chaque lecture d'en-tête (`header()`, `headers->get()`,
 * `server('HTTP_…')`, `$_SERVER`, `getenv()`, `bearerToken()`,
 * `userAgent()`…), son nom lu dans le texte, une chaîne ou une constante de
 * classe. Chaque en-tête lu doit être
 * recensé (`EntetesLus::recenses()`), avec sa valeur attendue et ce que
 * l'application fait d'une autre : l'ignorer (la requête répond comme sans
 * l'en-tête) ou la refuser par un statut (409 pour le compte d'une écriture).
 * Un nom que le texte ne dit pas, un en-tête recensé que plus rien ne lit, ou
 * une lecture hors d'un middleware, d'un contrôleur, d'une requête de
 * validation ou de la fermeture d'une route (dont les routes ne se déduisent
 * pas) font tomber la garde.
 *
 * Les routes. Chaque lecture donne ses routes : celles qui nomment son
 * middleware (les groupes `web` et `api`, le panneau), celles de son
 * contrôleur (de la méthode qui lit, quand c'est une action), celles dont
 * l'action reçoit sa requête de validation, ou une requête qui utilise son
 * trait, celle dont la fermeture la porte. Une écriture qui lit un en-tête après ses middlewares envoie sa
 * requête valide (`EntetesLus::requetesValides()`), pour un compte neuf à
 * chaque essai : sans elle, la validation la refuserait avant la lecture, et
 * une écriture nouvelle qui en lit un fait tomber la garde jusqu'à ce qu'elle
 * y soit. Les autres routes sont demandées comme une page (GET, sans corps)
 * ou reçoivent un corps JSON vide, leurs paramètres créés pour elles : la
 * lecture se fait dans un middleware, avant la validation.
 *
 * Les formes. Chaque route est demandée sans l'en-tête, le témoin, qui doit
 * atteindre la route (une page selon `RequetesMalFormees::temoinAtteint()`,
 * une requête valide sous 400, une autre écriture ni 401, ni 404, ni 405),
 * puis sous chaque valeur inattendue de `EntetesLus::formes()` : vide, très
 * longue, répétée (en deux lignes et en une), hors UTF-8, hors ASCII, la
 * valeur attendue suivie de mots, une date suivie de mots, les deux bouts
 * du calendrier, des nombres démesurés. Aucune réponse n'est une 500,
 * aucune exception n'est rapportée (`RequetesMalFormees::defauts()`,
 * `Exceptions::fake()` avant chaque requête), et chacune répond comme le
 * témoin, ou par le refus que l'en-tête déclare : une valeur ignorée laisse
 * l'écriture se faire.
 *
 * Le corps et l'en-tête à la fois. Une requête de validation lit l'heure de
 * l'appareil avec l'instant du corps, avant de le valider : deux dates
 * suivies de mots démesurés, l'une au corps, l'autre à l'en-tête, faisaient
 * déborder leur soustraction (#2093), ce qu'aucune des deux gardes ne voit
 * seule, l'une n'envoyant que des en-têtes sous un corps valide, l'autre que
 * des corps sans en-tête. Chaque écriture qui lit un en-tête après ses
 * middlewares reçoit donc aussi chaque valeur inattendue de l'en-tête sous
 * chaque corps mal formé de `UneRequeteMalFormeeNeRepondPas500Test`
 * (`RequetesMalFormees::formesDeCorps()`, dont une date suivie de mots
 * démesurés) : aucune 500, aucune exception rapportée.
 *
 * Limites. Les pages du panneau ont leur part dans
 * `UnePageDuPanneauNeRepondPas500Test`. Sous `/api/v1`, la requête porte
 * le jeton de Sanctum, comme dans la garde voisine : la session que Sanctum
 * ouvre pour la page (`StartSession`, lié à `OuvreLaSession`) n'y est pas
 * ouverte, et la marque du préchauffage n'y est pas lue. Le rappel d'Apple
 * n'est pas visité (il appellerait le fournisseur), ni les routes des
 * paquets. Seules les lectures que le texte montre sont recensées : un
 * en-tête lu par un paquet (Sanctum, Inertia), ou par le framework pour
 * l'application (`expectsJson()`, `ip()`, `getHost()`), n'en est pas.
 */

beforeEach(function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    Notification::fake();
    Mail::fake();
    Queue::fake();
});

/**
 * Envoie à une route qui lit `$entete` sa requête, sans l'en-tête (`$forme`
 * nulle, le témoin) ou sous cette forme de `EntetesLus::formes()`. Une route
 * qui a une requête valide la reçoit pour un compte neuf ; une autre, pour
 * le compte commun, avec des paramètres neufs pour une écriture. Un
 * `$corpsMalForme` remplace le corps d'une écriture, pour le compte commun.
 *
 * @param  Closure(string): string  $valeursDesPages
 * @param  array<mixed>|null  $corpsMalForme
 * @return TestResponse<Response>
 */
function enteteMalFormeEssayer(Route $route, string $entete, ?string $forme, User $compteCommun, Closure $valeursDesPages, ?array $corpsMalForme = null): TestResponse
{
    $valide = EntetesLus::requetesValides()[(string) $route->getName()] ?? null;
    $ecriture = $route->methods() !== ['GET', 'HEAD'];
    $compte = $valide === null || $corpsMalForme !== null ? $compteCommun : User::factory()->create();
    $adresse = RequetesMalFormees::adresse($route, $ecriture ? RequetesMalFormees::valeursDeRoute($compte, neuve: true) : $valeursDesPages);
    $corps = $corpsMalForme ?? ($valide === null ? [] : $valide($compte));
    $entetes = $forme === null ? [] : [$entete => EntetesLus::formes(EntetesLus::recenses()[$entete]['attendue']($compte))[$forme]];

    Exceptions::fake();
    Auth::forgetGuards();

    if (str_starts_with($route->uri(), 'api/')) {
        Sanctum::actingAs($compte, ['*']);
    } elseif (! RequetesMalFormees::pourUnInvite($route)) {
        actingAs($compte);
    }

    if (! $ecriture) {
        return call('GET', $adresse, [], [], [], RequetesMalFormees::serveur($entetes, null));
    }

    return json(array_first(array_diff($route->methods(), ['GET', 'HEAD'])) ?? 'POST', $adresse, $corps, $entetes);
}

it('aucune route de l’application ne répond 500 à une valeur inattendue d’un en-tête qu’elle lit', function (): void {
    $compte = User::factory()->create();
    $valeursDesPages = RequetesMalFormees::valeursDeRoute($compte);
    $formes = array_keys(EntetesLus::formes('valeur'));
    $defauts = [];
    $essais = [];

    foreach (EntetesLus::routesQuiLisent() as $entete => $lisent) {
        $refus = EntetesLus::recenses()[$entete]['refus'];

        foreach ($lisent as ['route' => $route]) {
            if (RequetesMalFormees::duPanneau($route) || $route->getName() === 'social.callback.apple') {
                continue;
            }

            $ecriture = $route->methods() !== ['GET', 'HEAD'];
            $methode = $ecriture ? array_first(array_diff($route->methods(), ['GET', 'HEAD'])) : 'GET';
            $temoin = enteteMalFormeEssayer($route, $entete, null, $compte, $valeursDesPages);
            $attendu = $temoin->getStatusCode();
            $cas = "{$methode} {$route->uri()}, {$entete}";
            $defauts = [...$defauts, ...RequetesMalFormees::defauts($temoin, "{$cas} (témoin)")];

            $atteinte = match (true) {
                ! $ecriture => RequetesMalFormees::temoinAtteint($route, $attendu),
                array_key_exists((string) $route->getName(), EntetesLus::requetesValides()) => $attendu < 400,
                default => ! in_array($attendu, [401, 404, 405], true),
            };

            if (! $atteinte) {
                $defauts[] = "{$cas} (témoin) : {$attendu}, la route n'est pas atteinte";
            }

            foreach ($formes as $forme) {
                $reponse = enteteMalFormeEssayer($route, $entete, $forme, $compte, $valeursDesPages);
                $statut = $reponse->getStatusCode();
                $essais[$entete] = ($essais[$entete] ?? 0) + 1;
                $defauts = [...$defauts, ...RequetesMalFormees::defauts($reponse, "{$cas} ({$forme})")];

                if ($statut !== $attendu && $statut !== $refus) {
                    $defauts[] = "{$cas} ({$forme}) : {$statut}, ni comme sans l'en-tête ({$attendu}) ni par son refus";
                }
            }
        }
    }

    expect($defauts)->toBe([])
        ->and(array_keys($essais))->toEqualCanonicalizing(array_keys(EntetesLus::recenses()))
        ->and($essais['X-Compte-De-L-Ecriture'] ?? 0)->toBeGreaterThanOrEqual(100 * count($formes));
});

it('aucune écriture qui lit un en-tête après ses middlewares ne répond 500 sous un en-tête et un corps mal formés à la fois', function (): void {
    $compte = User::factory()->create();
    $valeursDesPages = RequetesMalFormees::valeursDeRoute($compte);
    $formes = array_keys(EntetesLus::formes('valeur'));
    $defauts = [];
    $essais = 0;

    foreach (EntetesLus::routesQuiLisent() as $entete => $lisent) {
        foreach ($lisent as ['route' => $route, 'apresLesMiddlewares' => $apres]) {
            if (! $apres || $route->methods() === ['GET', 'HEAD']) {
                continue;
            }

            $methode = array_first(array_diff($route->methods(), ['GET', 'HEAD']));

            foreach (RequetesMalFormees::formesDeCorps() as $formeDuCorps => $valeur) {
                foreach ($formes as $forme) {
                    $reponse = enteteMalFormeEssayer($route, $entete, $forme, $compte, $valeursDesPages, RequetesMalFormees::corpsMalForme($valeur));
                    $essais++;
                    $defauts = [...$defauts, ...RequetesMalFormees::defauts($reponse, "{$methode} {$route->uri()}, {$entete} ({$forme}), corps ({$formeDuCorps})")];
                }
            }
        }
    }

    expect($defauts)->toBe([])
        ->and($essais)->toBeGreaterThanOrEqual(7 * count(RequetesMalFormees::formesDeCorps()) * count($formes));
});

it('recense chaque en-tête que le code de app/ lit, et en déduit les routes qui le lisent', function (): void {
    $routes = array_map(
        static fn (array $lisent): array => array_values(array_unique(array_map(static fn (array $lit): string => (string) $lit['route']->getName(), $lisent))),
        EntetesLus::routesQuiLisent(),
    );

    expect(EntetesLus::defautsDuRecensement())->toBe([], 'recense l’en-tête dans EntetesLus::recenses(), ou lis-le dans un middleware, un contrôleur ou une requête de validation (.ai/rules/controllers.md)')
        ->and(count(EntetesLus::lecturesDeLApplication()))->toBeGreaterThanOrEqual(8)
        ->and($routes['Idempotency-Key'] ?? [])->toEqualCanonicalizing(['api.v1.sets.store', 'api.v1.workout-lines.store', 'api.v1.workouts.store', 'workouts.store', 'templates.execute'])
        ->and($routes['X-Heure-De-L-Appareil'] ?? [])->toEqualCanonicalizing(['api.v1.workouts.store', 'api.v1.workouts.finish'])
        ->and($routes['X-Compte-De-L-Ecriture'] ?? [])->toContain('api.v1.sets.store', 'workouts.store', 'dashboard', 'profile.update')
        ->and($routes['X-Copies-Hors-Ligne'] ?? [])->toContain('dashboard', 'workouts.show')
        ->and($routes['X-Copies-Hors-Ligne'] ?? [])->not->toContain('api.v1.sets.store');
});

it('lit chaque forme d’une lecture d’en-tête, son nom par une chaîne ou une constante, et pas celle d’une réponse', function (): void {
    $code = <<<'PHP'
        <?php
        namespace App\Http\Middleware;
        use App\Support\HeureDeLAppareil;
        use Illuminate\Support\Facades\Request;
        final class VerifieLeCompteDeLEcriture
        {
            public function handle($request, $next)
            {
                $request->header('Idempotency-Key');
                $request->headers->get(self::ENTETE);
                $this->header(HeureDeLAppareil::ENTETE);
                request()->hasHeader("X-Un");
                Request::header('X-Deux');
                $request->server('HTTP_X_TROIS');
                $request->server->get('HTTP_X_QUATRE');
                $_SERVER['HTTP_X_CINQ'];
                $request->bearerToken();
                $event->request->userAgent();
                $request->header($nom);
                $request->header();
                $request->headers->all();
                getallheaders();
                $reponse->headers->get('X-Six');
                response()->noContent()->header('Cache-Control', 'no-store');
                $response->headers->set('X-Sept', 'oui');
                $this->reponse->header('X-Huit');
                $request->server('REMOTE_ADDR');
                $request->headers->set('X-Neuf', 'oui');
                Http::get('/')->header('Retry-After');
                \getallheaders();
                getenv('HTTP_X_ONZE');
                getenv('APP_ENV');
            }
        }
        trait LitUnEnTete
        {
            protected function lire(): void
            {
                $this->header('X-Dix');
            }
        }
        PHP;

    $classe = VerifieLeCompteDeLEcriture::class;

    expect(EntetesLus::lecturesDuCode($code))->toBe([
        ['ligne' => 9, 'entete' => 'idempotency-key', 'classe' => $classe, 'methode' => 'handle'],
        ['ligne' => 10, 'entete' => 'x-compte-de-l-ecriture', 'classe' => $classe, 'methode' => 'handle'],
        ['ligne' => 11, 'entete' => 'x-heure-de-l-appareil', 'classe' => $classe, 'methode' => 'handle'],
        ['ligne' => 12, 'entete' => 'x-un', 'classe' => $classe, 'methode' => 'handle'],
        ['ligne' => 13, 'entete' => 'x-deux', 'classe' => $classe, 'methode' => 'handle'],
        ['ligne' => 14, 'entete' => 'x-trois', 'classe' => $classe, 'methode' => 'handle'],
        ['ligne' => 15, 'entete' => 'x-quatre', 'classe' => $classe, 'methode' => 'handle'],
        ['ligne' => 16, 'entete' => 'x-cinq', 'classe' => $classe, 'methode' => 'handle'],
        ['ligne' => 17, 'entete' => 'authorization', 'classe' => $classe, 'methode' => 'handle'],
        ['ligne' => 18, 'entete' => 'user-agent', 'classe' => $classe, 'methode' => 'handle'],
        ['ligne' => 19, 'entete' => null, 'classe' => $classe, 'methode' => 'handle'],
        ['ligne' => 20, 'entete' => '*', 'classe' => $classe, 'methode' => 'handle'],
        ['ligne' => 21, 'entete' => '*', 'classe' => $classe, 'methode' => 'handle'],
        ['ligne' => 22, 'entete' => '*', 'classe' => $classe, 'methode' => 'handle'],
        ['ligne' => 30, 'entete' => '*', 'classe' => $classe, 'methode' => 'handle'],
        ['ligne' => 31, 'entete' => 'x-onze', 'classe' => $classe, 'methode' => 'handle'],
        ['ligne' => 39, 'entete' => 'x-dix', 'classe' => 'App\\Http\\Middleware\\LitUnEnTete', 'methode' => 'lire'],
    ]);
});
