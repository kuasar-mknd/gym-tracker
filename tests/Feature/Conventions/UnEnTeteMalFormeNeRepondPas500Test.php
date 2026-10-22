<?php

declare(strict_types=1);

use App\Http\Middleware\OuvreLaSession;
use App\Http\Middleware\TempsDuServeur;
use App\Http\Middleware\VerifieLeCompteDeLEcriture;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\EntetesLus;
use Tests\Support\RequetesMalFormees;
use Tests\Support\SacDEntetesEspionne;

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
 * Le recensement. `Tests\Support\EntetesLus` lit le code de app/, de
 * routes/, de bootstrap/ (hors de son cache) et des vues compilées, et y
 * trouve chaque lecture d'en-tête (`header()`, `headers->get()`,
 * `server('HTTP_…')`, `$_SERVER`, `getenv()`, `bearerToken()`,
 * `userAgent()`, tout autre usage du sac des en-têtes…), son nom lu dans le
 * texte, une chaîne ou une constante de classe. Chaque en-tête lu doit être
 * recensé (`EntetesLus::recenses()`), avec sa valeur attendue et ce que
 * l'application fait d'une autre : l'ignorer (la requête répond comme sans
 * l'en-tête) ou la refuser par un statut (409 pour le compte d'une écriture).
 * Un nom que le texte ne dit pas, un en-tête recensé que plus rien ne lit, ou
 * une lecture hors d'un middleware, d'un contrôleur, d'une requête de
 * validation ou de la fermeture d'une route (dont les routes ne se déduisent
 * pas) font tomber la garde.
 *
 * Les routes. Chaque lecture donne ses routes : toutes pour un middleware
 * de la pile globale, celles qui nomment son middleware (les groupes `web`
 * et `api`, le panneau), celles de son contrôleur (de la méthode qui lit,
 * quand c'est une action), celles dont l'action reçoit sa requête de
 * validation, ou une requête qui utilise son trait, celle dont la fermeture
 * la porte. Une écriture qui lit un en-tête après ses middlewares envoie sa
 * requête valide (`EntetesLus::requetesValides()`), pour un compte neuf à
 * chaque essai : sans elle, la validation la refuserait avant la lecture, et
 * une écriture nouvelle qui en lit un fait tomber la garde jusqu'à ce qu'elle
 * y soit. Les autres routes sont demandées comme une page (GET, sans corps)
 * ou reçoivent un corps JSON vide, leurs paramètres créés pour elles : la
 * lecture se fait dans un middleware, avant la validation. Un en-tête lu
 * sous certaines méthodes seulement le déclare (`EntetesLus::recenses()`) :
 * la marque du préchauffage, que `OuvreLaSession` ne lit que sous GET et
 * HEAD, est essayée sous les deux sur chaque page, et une écriture qui
 * traverse le middleware n'en reçoit que son témoin, qui ne doit pas la
 * lire.
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
 * l'écriture se faire. Chaque requête, témoin compris, doit atteindre la
 * lecture qu'elle éprouve : un sac des en-têtes espion
 * (`Tests\Support\SacDEntetesEspionne`), posé sur la requête et sur chaque
 * requête de validation, retient chaque nom cherché.
 *
 * Le corps et l'en-tête à la fois. Une requête de validation lit l'heure de
 * l'appareil avec l'instant du corps, avant de le valider : deux dates
 * suivies de mots démesurés, l'une au corps, l'autre à l'en-tête, faisaient
 * déborder leur soustraction (#2093), ce qu'aucune des deux gardes ne voit
 * seule, l'une n'envoyant que des en-têtes sous un corps valide, l'autre que
 * des corps sans en-tête. Chaque écriture qui lit un en-tête après ses
 * middlewares et avant toute requête de validation (dans la requête de
 * validation ou son trait, ou dans une action qui n'en reçoit pas) reçoit
 * donc aussi chaque valeur inattendue de l'en-tête sous chaque corps mal
 * formé de `UneRequeteMalFormeeNeRepondPas500Test`
 * (`RequetesMalFormees::formesDeCorps()`, dont une date suivie de mots
 * démesurés) : aucune 500, aucune exception rapportée, et la lecture
 * atteinte. Une lecture qu'une requête de validation précède (la clé des
 * écritures de l'API, lue après `validated()`) n'y est pas : le corps mal
 * formé est refusé avant elle. Une lecture dans un middleware n'y est pas
 * non plus : essayée sur chaque écriture de son groupe, elle multiplierait
 * par huit les essais du premier test, et les deux middlewares qui lisent
 * un en-tête aujourd'hui ne lisent pas le corps.

 * Limites. Les pages du panneau ont leur part dans
 * `UnePageDuPanneauNeRepondPas500Test`. Sous `/api/v1`, la requête porte
 * le jeton de Sanctum, comme dans la garde voisine : la session que Sanctum
 * ouvre pour la page (`StartSession`, lié à `OuvreLaSession`) n'y est pas
 * ouverte, et la marque du préchauffage n'y est pas lue. Le rappel d'Apple
 * n'est pas visité (il appellerait le fournisseur), ni les routes des
 * paquets. Seules les lectures que le texte montre sont recensées : un
 * en-tête lu par un paquet (Sanctum, Inertia), ou par le framework pour
 * l'application (`expectsJson()`, `ip()`, `getHost()`), n'en est pas.
 * config/ et database/ ne sont pas lus.
 */

beforeEach(function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    Notification::fake();
    Mail::fake();
    Queue::fake();
});

/**
 * Envoie à une route qui lit `$entete` sa requête, sous `$methode`, sans
 * l'en-tête (`$forme` nulle, le témoin) ou sous cette forme de
 * `EntetesLus::formes()`, et dit si la requête a cherché l'en-tête (`$lus`,
 * que remplit `SacDEntetesEspionne`). Une route qui a une requête valide la
 * reçoit pour un compte neuf ; une autre, pour le compte commun, avec des
 * paramètres neufs pour une écriture. Un `$corpsMalForme` remplace le corps
 * d'une écriture, pour le compte commun.
 *
 * @param  Closure(string): string  $valeursDesPages
 * @param  ArrayObject<int, string>  $lus
 * @param  array<mixed>|null  $corpsMalForme
 * @return array{reponse: TestResponse<Response>, lu: bool}
 */
function enteteMalFormeEssayer(Route $route, string $methode, string $entete, ?string $forme, User $compteCommun, Closure $valeursDesPages, ArrayObject $lus, ?array $corpsMalForme = null): array
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

    $lus->exchangeArray([]);
    $reponse = in_array($methode, ['GET', 'HEAD'], true)
        ? call($methode, $adresse, [], [], [], RequetesMalFormees::serveur($entetes, null))
        : json($methode, $adresse, $corps, $entetes);

    return ['reponse' => $reponse, 'lu' => in_array(strtolower($entete), $lus->getArrayCopy(), true)];
}

it('aucune route de l’application ne répond 500 à une valeur inattendue d’un en-tête qu’elle lit', function (): void {
    $lus = new ArrayObject();
    SacDEntetesEspionne::poser(app(), $lus);
    $compte = User::factory()->create();
    $valeursDesPages = RequetesMalFormees::valeursDeRoute($compte);
    $formes = array_keys(EntetesLus::formes('valeur'));
    $defauts = [];
    $essais = [];
    $temoinsSansLecture = 0;

    foreach (EntetesLus::routesQuiLisent() as $entete => $lisent) {
        ['refus' => $refus, 'methodes' => $methodesLues] = EntetesLus::recenses()[$entete];

        foreach ($lisent as ['route' => $route]) {
            if (RequetesMalFormees::duPanneau($route) || $route->getName() === 'social.callback.apple') {
                continue;
            }

            $ecriture = $route->methods() !== ['GET', 'HEAD'];
            $principale = $ecriture ? (string) array_first(array_diff($route->methods(), ['GET', 'HEAD'])) : 'GET';
            $methodes = $methodesLues === null ? [$principale] : array_values(array_intersect($methodesLues, $route->methods()));

            if ($methodes === []) {
                $temoinsSansLecture++;

                if (enteteMalFormeEssayer($route, $principale, $entete, null, $compte, $valeursDesPages, $lus)['lu']) {
                    $defauts[] = "{$principale} {$route->uri()}, {$entete} (témoin) : lu sous {$principale}, que EntetesLus::recenses() ne déclare pas";
                }

                continue;
            }

            foreach ($methodes as $methode) {
                ['reponse' => $temoin, 'lu' => $lu] = enteteMalFormeEssayer($route, $methode, $entete, null, $compte, $valeursDesPages, $lus);
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

                if (! $lu) {
                    $defauts[] = "{$cas} (témoin) : la lecture de l'en-tête n'est pas atteinte";
                }

                foreach ($formes as $forme) {
                    ['reponse' => $reponse, 'lu' => $lu] = enteteMalFormeEssayer($route, $methode, $entete, $forme, $compte, $valeursDesPages, $lus);
                    $statut = $reponse->getStatusCode();
                    $essais[$entete][$methode] = ($essais[$entete][$methode] ?? 0) + 1;
                    $defauts = [...$defauts, ...RequetesMalFormees::defauts($reponse, "{$cas} ({$forme})")];

                    if ($statut !== $attendu && $statut !== $refus) {
                        $defauts[] = "{$cas} ({$forme}) : {$statut}, ni comme sans l'en-tête ({$attendu}) ni par son refus";
                    }

                    if (! $lu) {
                        $defauts[] = "{$cas} ({$forme}) : la lecture de l'en-tête n'est pas atteinte";
                    }
                }
            }
        }
    }

    expect($defauts)->toBe([])
        ->and(array_keys($essais))->toEqualCanonicalizing(array_keys(EntetesLus::recenses()))
        ->and(array_sum($essais['X-Compte-De-L-Ecriture'] ?? []))->toBeGreaterThanOrEqual(100 * count($formes))
        ->and(array_keys($essais[OuvreLaSession::ENTETE] ?? []))->toEqualCanonicalizing(['GET', 'HEAD'])
        ->and($temoinsSansLecture)->toBeGreaterThanOrEqual(50);
});

it('aucune écriture qui lit un en-tête avant d’en valider le corps ne répond 500 sous un en-tête et un corps mal formés à la fois', function (): void {
    $lus = new ArrayObject();
    SacDEntetesEspionne::poser(app(), $lus);
    $compte = User::factory()->create();
    $valeursDesPages = RequetesMalFormees::valeursDeRoute($compte);
    $formes = array_keys(EntetesLus::formes('valeur'));
    $defauts = [];
    $paires = [];
    $essais = 0;

    foreach (EntetesLus::routesQuiLisent() as $entete => $lisent) {
        foreach ($lisent as ['route' => $route, 'avantLaValidation' => $avant]) {
            if (! $avant || $route->methods() === ['GET', 'HEAD']) {
                continue;
            }

            $methode = (string) array_first(array_diff($route->methods(), ['GET', 'HEAD']));
            $paires[] = "{$entete} {$route->getName()}";

            foreach (RequetesMalFormees::formesDeCorps() as $formeDuCorps => $valeur) {
                foreach ($formes as $forme) {
                    ['reponse' => $reponse, 'lu' => $lu] = enteteMalFormeEssayer($route, $methode, $entete, $forme, $compte, $valeursDesPages, $lus, RequetesMalFormees::corpsMalForme($valeur));
                    $cas = "{$methode} {$route->uri()}, {$entete} ({$forme}), corps ({$formeDuCorps})";
                    $essais++;
                    $defauts = [...$defauts, ...RequetesMalFormees::defauts($reponse, $cas)];

                    if (! $lu) {
                        $defauts[] = "{$cas} : {$reponse->getStatusCode()}, la lecture de l'en-tête n'est pas atteinte";
                    }
                }
            }
        }
    }

    expect($defauts)->toBe([])
        ->and($paires)->toContain('X-Heure-De-L-Appareil api.v1.workouts.store', 'X-Heure-De-L-Appareil api.v1.workouts.finish')
        ->and(array_intersect($paires, ['Idempotency-Key api.v1.sets.store', 'Idempotency-Key api.v1.workout-lines.store', 'Idempotency-Key api.v1.workouts.store']))->toBe([])
        ->and($essais)->toBe(count($paires) * count(RequetesMalFormees::formesDeCorps()) * count($formes));
});

it('recense chaque en-tête que le code de l’application lit, et en déduit les routes qui le lisent', function (): void {
    $routes = array_map(
        static fn (array $lisent): array => array_values(array_unique(array_map(static fn (array $lit): string => (string) $lit['route']->getName(), $lisent))),
        EntetesLus::routesQuiLisent(),
    );

    expect(EntetesLus::defautsDuRecensement())->toBe([], 'recense l’en-tête dans EntetesLus::recenses(), ou lis-le dans un middleware, un contrôleur, une requête de validation ou la fermeture d’une route (.ai/rules/controllers.md)')
        ->and(count(EntetesLus::lecturesDeLApplication()))->toBeGreaterThanOrEqual(8)
        ->and($routes['Idempotency-Key'] ?? [])->toEqualCanonicalizing(['api.v1.sets.store', 'api.v1.workout-lines.store', 'api.v1.workouts.store', 'workouts.store', 'templates.execute'])
        ->and($routes['X-Heure-De-L-Appareil'] ?? [])->toEqualCanonicalizing(['api.v1.workouts.store', 'api.v1.workouts.finish'])
        ->and($routes['X-Compte-De-L-Ecriture'] ?? [])->toContain('api.v1.sets.store', 'workouts.store', 'dashboard', 'profile.update')
        ->and($routes['X-Copies-Hors-Ligne'] ?? [])->toContain('dashboard', 'workouts.show')
        ->and($routes['X-Copies-Hors-Ligne'] ?? [])->not->toContain('api.v1.sets.store');
});

it('lit app/, routes/, bootstrap/ hors de son cache et les vues, et refuse une lecture dans bootstrap/ ou une vue', function (): void {
    $racine = sys_get_temp_dir().'/entetes-lus-'.Str::uuid()->toString();
    $fichiers = [
        'app/Http/Middleware/LitUnEnTete.php' => "<?php\nnamespace App\\Http\\Middleware;\nfinal class LitUnEnTete\n{\n    public function handle(\$request)\n    {\n        return \$request->header('X-App');\n    }\n}\n",
        'routes/web.php' => "<?php\nRoute::get('/', fn (\$request) => \$request->header('X-Routes'));\n",
        'bootstrap/app.php' => "<?php\nreturn Application::configure()->withExceptions(function (\$exceptions): void {\n    \$exceptions->render(fn (\\Throwable \$e, \\Illuminate\\Http\\Request \$request) => \$request->header('X-Bootstrap'));\n});\n",
        'bootstrap/cache/services.php' => "<?php\nreturn ['x' => request()->header('X-Cache')];\n",
        'resources/views/partiels/page.blade.php' => "{{-- Un commentaire\n     sur deux lignes --}}\n<main>\n    <p>{{ request()->header('X-Vue') }}</p>\n</main>\n",
    ];

    try {
        foreach ($fichiers as $chemin => $contenu) {
            File::ensureDirectoryExists(dirname("{$racine}/{$chemin}"));
            File::put("{$racine}/{$chemin}", $contenu);
        }

        $lues = array_map(static fn (array $lecture): string => "{$lecture['fichier']}:{$lecture['ligne']}:{$lecture['entete']}", EntetesLus::lecturesDeLApplication($racine));
    } finally {
        File::deleteDirectory($racine);
    }

    $ailleurs = [
        ['fichier' => 'bootstrap/app.php', 'ligne' => 305, 'entete' => 'idempotency-key', 'classe' => null, 'methode' => null],
        ['fichier' => 'resources/views/app.blade.php', 'ligne' => 2, 'entete' => 'idempotency-key', 'classe' => null, 'methode' => null],
    ];
    $horsPlace = array_values(array_filter(
        EntetesLus::defautsDuRecensement([...EntetesLus::lecturesDeLApplication(), ...$ailleurs]),
        static fn (string $defaut): bool => str_starts_with($defaut, 'bootstrap/') || str_starts_with($defaut, 'resources/'),
    ));

    expect($lues)->toEqualCanonicalizing([
        'app/Http/Middleware/LitUnEnTete.php:7:x-app',
        'routes/web.php:2:x-routes',
        'bootstrap/app.php:3:x-bootstrap',
        'resources/views/partiels/page.blade.php:4:x-vue',
    ])
        ->and($horsPlace)->toHaveCount(2)
        ->and(array_filter($horsPlace, static fn (string $defaut): bool => str_contains($defaut, ' : lu hors ')))->toHaveCount(2);
});

it('déduit d’une lecture dans un middleware de la pile globale chaque route, lue avant ses middlewares', function (): void {
    $dansLaPile = ['fichier' => 'app/Http/Middleware/TempsDuServeur.php', 'ligne' => 80, 'entete' => strtolower(OuvreLaSession::ENTETE), 'classe' => TempsDuServeur::class, 'methode' => 'handle'];
    $lectures = [...EntetesLus::lecturesDeLApplication(), $dansLaPile];
    $lisent = EntetesLus::routesQuiLisent($lectures)[OuvreLaSession::ENTETE] ?? [];

    expect(EntetesLus::defautsDuRecensement($lectures))->toBe([])
        ->and(array_map(static fn (array $lit): string => (string) $lit['route']->getName(), $lisent))->toContain('api.v1.sets.store', 'sonde', 'dashboard')
        ->and($lisent)->toHaveCount(count(RequetesMalFormees::routes(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])))
        ->and(array_filter($lisent, static fn (array $lit): bool => $lit['apresLesMiddlewares']))->toBe([]);
});

it('lit chaque forme d’une lecture d’en-tête, son nom par une chaîne ou une constante, et pas celle d’une réponse ni d’un receveur d’un autre type', function (): void {
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
        namespace App\Http\Controllers;
        use Illuminate\Http\Response;
        use Illuminate\Support\Facades\Request as Requete;
        final class LitAutrement
        {
            public function store($request, Response $retour)
            {
                Requete::header('X-Douze');
                \Illuminate\Support\Facades\Request::header('X-Treize');
                $entetes = $request->headers;
                $entetes->get('X-Quatorze');
                $request->headers->keys();
                foreach ($request->headers as $nom => $valeur) {
                }
                $request->Header('X-Quinze');
                $request->headers->GET('X-Seize');
                $serveur = $request->server;
                $request->headers->remove('X-Dix-Sept');
                $retour->header('X-Dix-Huit');
                $retour->headers->get('X-Dix-Neuf');
            }
        }
        namespace App\Models;
        final class ExceptionEnregistree
        {
            protected static function booted(): void
            {
                self::saving(function (self $exception): void {
                    $exception->headers = self::masquer($exception->headers);
                });
            }
        }
        PHP;

    $classe = VerifieLeCompteDeLEcriture::class;
    $autre = 'App\\Http\\Controllers\\LitAutrement';

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
        ['ligne' => 49, 'entete' => 'x-douze', 'classe' => $autre, 'methode' => 'store'],
        ['ligne' => 50, 'entete' => 'x-treize', 'classe' => $autre, 'methode' => 'store'],
        ['ligne' => 51, 'entete' => null, 'classe' => $autre, 'methode' => 'store'],
        ['ligne' => 53, 'entete' => '*', 'classe' => $autre, 'methode' => 'store'],
        ['ligne' => 54, 'entete' => null, 'classe' => $autre, 'methode' => 'store'],
        ['ligne' => 56, 'entete' => 'x-quinze', 'classe' => $autre, 'methode' => 'store'],
        ['ligne' => 57, 'entete' => 'x-seize', 'classe' => $autre, 'methode' => 'store'],
        ['ligne' => 58, 'entete' => null, 'classe' => $autre, 'methode' => 'store'],
    ]);
});
