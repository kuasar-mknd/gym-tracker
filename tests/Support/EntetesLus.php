<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\OuvreLaSession;
use App\Http\Middleware\VerifieLeCompteDeLEcriture;
use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Support\HeureDeLAppareil;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Request as RequeteDeLaFacade;
use Illuminate\Support\Str;
use PhpToken;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request as RequeteDeSymfony;

/**
 * Les en-têtes que le code de l'application (app/, routes/, bootstrap/, les
 * vues) lit lui-même (#2094), pour la garde des en-têtes mal formés
 * (`UnEnTeteMalFormeNeRepondPas500Test`) : leur recensement, les valeurs inattendues essayées, la requête valide de
 * chaque route qui en lit un après ses middlewares, et la lecture du code
 * qui trouve chaque lecture et en déduit les routes.
 *
 * Une lecture est un appel qui rend la valeur d'un en-tête de la requête :
 * `header()`, `hasHeader()`, `headers->get()` (`has`, `all`, `contains`,
 * `getDate`), `server('HTTP_…')`, `server->get('HTTP_…')`,
 * `$_SERVER['HTTP_…']`, `getenv('HTTP_…')`, `bearerToken()`
 * (`Authorization`), `userAgent()` (`User-Agent`), `getallheaders()`, la
 * façade `Request` comprise, sous un alias ou son nom complet. Un nom de
 * méthode se lit sans tenir compte de la casse, comme PHP. Tout autre usage
 * du sac des en-têtes ou des variables de serveur (`keys()`, le sac rangé
 * dans une variable, parcouru par `foreach`, passé à une fonction) en est
 * une aussi, dont le nom ne se lit pas ; écrire dans le sac (`set()`,
 * `remove()`) n'en est pas. Ce que lit le framework ou un paquet
 * (`expectsJson()`, `ip()`, `getHost()`, les en-têtes d'Inertia et de
 * Sanctum) n'en est pas. Un appel sur une réponse (une variable `$reponse`
 * ou `$response`, `response()`, `redirect()`, `back()`, `Response::`,
 * `Redirect::`, `Http::`) n'en est pas non plus, ni un appel sur une
 * variable que la fonction reçoit sous un type qui n'est pas une requête
 * (`Response $retour`, `self $exception` dans un modèle) ; tout autre
 * receveur compte pour une requête.
 */
final class EntetesLus
{
    /**
     * Les méthodes de la requête qui lisent l'en-tête que nomme leur premier
     * argument, en minuscules : PHP ne tient pas compte de la casse d'un nom
     * de méthode.
     */
    private const array LECTURES_PAR_NOM = ['header', 'hasheader'];

    /**
     * Les méthodes de la requête qui lisent un en-tête fixe, en minuscules.
     */
    private const array LECTURES_FIXES = ['bearertoken' => 'authorization', 'useragent' => 'user-agent'];

    /**
     * Pour le sac des en-têtes (`->headers`) et celui des variables de
     * serveur (`->server`), en minuscules : les méthodes qui lisent ce que
     * nomme leur premier argument (`all()` sans argument les lit tous), celles
     * qui les lisent tous, et celles qui écrivent. Tout autre usage du sac (le
     * ranger dans une variable, le parcourir, le passer, une autre méthode)
     * est une lecture dont le nom ne se lit pas.
     *
     * @var array<string, array{parNom: list<string>, tous: list<string>, ecritures: list<string>}>
     */
    private const array SACS = [
        'headers' => [
            'parNom' => ['get', 'has', 'all', 'contains', 'getdate'],
            'tous' => ['keys', 'getiterator', 'count'],
            'ecritures' => ['set', 'remove', 'add', 'replace'],
        ],
        'server' => [
            'parNom' => ['get', 'has', 'all', 'getstring', 'getint', 'getboolean', 'getalpha', 'getalnum', 'getdigits', 'getenum', 'filter'],
            'tous' => ['keys', 'getheaders', 'getiterator', 'count'],
            'ecritures' => ['set', 'remove', 'add', 'replace'],
        ],
    ];

    /**
     * Les jetons d'un type déclaré devant un paramètre.
     */
    private const array JETONS_DE_TYPE = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    /**
     * Les fonctions qui rendent tous les en-têtes.
     */
    private const array FONCTIONS_DE_TOUS_LES_ENTETES = ['getallheaders', 'apache_request_headers'];

    /**
     * Les receveurs qui sont une réponse, pas la requête : fonctions et
     * classes en tête de chaîne.
     */
    private const array TETES_DE_REPONSE = ['response()', 'redirect()', 'back()', 'Response::', 'Redirect::', 'Http::'];

    /**
     * Chaque en-tête que le code de app/ lit, sous le nom qu'il lui donne :
     * sa valeur attendue pour un compte (celle qu'écrit le client), et le
     * statut de son refus quand une valeur inattendue est refusée plutôt
     * qu'ignorée (`null` : la requête répond comme sans l'en-tête).
     *
     * @return array<string, array{attendue: Closure(User): string, refus: int|null}>
     */
    public static function recenses(): array
    {
        return [
            // CreateSetAction::idempotencyKey() : de 1 à 64 caractères ASCII imprimables, ignorée sinon.
            'Idempotency-Key' => [
                'attendue' => static fn (User $compte): string => Str::uuid()->toString(),
                'refus' => null,
            ],
            // HeureDeLAppareil::ageDe() : la forme de toISOString(), ignorée sinon.
            HeureDeLAppareil::ENTETE => [
                'attendue' => static fn (User $compte): string => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'refus' => null,
            ],
            // OuvreLaSession : la seule marque du préchauffage, ignorée sinon.
            OuvreLaSession::ENTETE => [
                'attendue' => static fn (User $compte): string => OuvreLaSession::PRECHAUFFAGE,
                'refus' => null,
            ],
            // VerifieLeCompteDeLEcriture : un autre compte que celui de la session répond 409.
            VerifieLeCompteDeLEcriture::ENTETE => [
                'attendue' => static fn (User $compte): string => (string) $compte->id,
                'refus' => 409,
            ],
        ];
    }

    /**
     * Les valeurs inattendues d'un en-tête, d'après sa valeur attendue :
     * vide ; très longue ; répétée, en deux lignes (ce que Symfony garde en
     * liste quand le serveur les lui passe ainsi) ou en une (ce que PHP fait
     * de deux lignes) ; hors UTF-8 ; hors ASCII ; la valeur attendue suivie
     * de mots ; une date suivie de mots (Carbon en applique le sens) ; les
     * deux bouts du calendrier, dans la forme d'un instant ; des nombres
     * démesurés.
     *
     * @return array<string, string|list<string>>
     */
    public static function formes(string $attendue): array
    {
        return [
            'vide' => '',
            'très longue' => str_repeat('a', 8192),
            'répétée' => [$attendue, $attendue],
            'répétée sur une ligne' => $attendue.', '.$attendue,
            'pas de l’UTF-8' => "\xFF\xFE",
            'hors ASCII' => 'é',
            'suivie de mots' => $attendue.' next monday',
            'date suivie de mots' => '2026-10-21T16:00:00.000Z +290000000000 years',
            'premier instant du calendrier' => '0001-01-01T00:00:00.000Z',
            'dernier instant du calendrier' => '9999-12-31T23:59:59.999Z',
            'nombre démesuré' => PHP_INT_MAX.'0',
            'flottant démesuré' => '1e999',
        ];
    }

    /**
     * Le corps d'une requête valide, pour un compte neuf, de chaque écriture
     * qui lit un en-tête après ses middlewares (dans son contrôleur ou sa
     * requête de validation) : sans lui, la validation refuserait la requête
     * avant la lecture. Une écriture nouvelle qui en lit un fait échouer la
     * garde jusqu'à ce que son corps s'y ajoute.
     *
     * @return array<string, Closure(User): array<string, mixed>>
     */
    public static function requetesValides(): array
    {
        $instant = static fn (): string => now()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $seance = static fn (User $compte): Workout => Workout::withoutEvents(
            static fn (): Workout => Workout::factory()->create(['user_id' => $compte->id, 'started_at' => now()->subHour(), 'ended_at' => null]),
        );

        return [
            'api.v1.sets.store' => static fn (User $compte): array => [
                'workout_line_id' => WorkoutLine::factory()->create(['workout_id' => $seance($compte)->id])->id,
                'weight' => 80,
                'reps' => 8,
            ],
            'api.v1.workout-lines.store' => static fn (User $compte): array => [
                'workout_id' => $seance($compte)->id,
                'exercise_id' => Exercise::factory()->create(['user_id' => $compte->id])->id,
            ],
            'api.v1.workouts.store' => static fn (User $compte): array => ['started_at' => $instant()],
            'api.v1.workouts.finish' => static fn (User $compte): array => ['workout_id' => $seance($compte)->id, 'ended_at' => $instant()],
            'workouts.store' => static fn (User $compte): array => [],
            'templates.execute' => static fn (User $compte): array => [],
        ];
    }

    /**
     * Chaque lecture d'en-tête du code de l'application, avec son fichier,
     * relatif à `$racine` (celle de l'application par défaut) : app/,
     * routes/, bootstrap/ hors de bootstrap/cache (que Laravel écrit), et
     * les vues de resources/views, une vue Blade lue une fois compilée
     * (`vueCompilee()`).
     *
     * @return list<array{fichier: string, ligne: int, entete: string|null, classe: string|null, methode: string|null}>
     */
    public static function lecturesDeLApplication(?string $racine = null): array
    {
        $racine = (string) realpath($racine ?? base_path());
        $dossiers = array_values(array_filter(
            array_map(static fn (string $dossier): string => "{$racine}/{$dossier}", ['app', 'routes', 'bootstrap', 'resources/views']),
            is_dir(...),
        ));
        $lectures = [];

        foreach (Finder::create()->files()->in($dossiers)->name('*.php')->sortByName() as $fichier) {
            $chemin = str_replace('\\', '/', mb_substr((string) $fichier->getRealPath(), mb_strlen($racine) + 1));

            if (str_starts_with($chemin, 'bootstrap/cache/')) {
                continue;
            }

            $code = str_ends_with($chemin, '.blade.php') ? self::vueCompilee($fichier->getContents()) : $fichier->getContents();

            foreach (self::lecturesDuCode($code) as $lecture) {
                $lectures[] = ['fichier' => $chemin, ...$lecture];
            }
        }

        return $lectures;
    }

    /**
     * Le code PHP d'une vue Blade (`Blade::compileString()`). Un commentaire
     * de Blade, que la compilation efface, laisse ses sauts de ligne : la
     * ligne d'une lecture reste celle de la vue, sauf après une directive
     * que Blade écrit sur plusieurs lignes (un composant).
     */
    private static function vueCompilee(string $vue): string
    {
        $sansCommentaires = (string) preg_replace_callback('/\{\{--.*?--\}\}/s', static fn (array $commentaire): string => str_repeat("\n", substr_count($commentaire[0], "\n")), $vue);

        return Blade::compileString($sansCommentaires);
    }

    /**
     * Chaque lecture d'en-tête d'un code PHP : sa ligne, le nom lu (en
     * minuscules, `-` pour `_` ; `*` pour tous les en-têtes ; `null` quand le
     * texte ne le dit pas : une variable, une expression), la classe et la
     * méthode qui la portent. Un nom écrit par une constante de classe
     * (`self::ENTETE`, `HeureDeLAppareil::ENTETE`) se lit par sa valeur,
     * la classe résolue par l'espace de noms et les `use` du fichier.
     *
     * @return list<array{ligne: int, entete: string|null, classe: string|null, methode: string|null}>
     */
    public static function lecturesDuCode(string $code): array
    {
        $jetons = array_values(array_filter(PhpToken::tokenize($code, TOKEN_PARSE), static fn (PhpToken $jeton): bool => ! $jeton->isIgnorable()));
        $contexte = ['espace' => '', 'imports' => [], 'classe' => null];
        $pile = [];
        $enAttente = null;
        $lectures = [];

        foreach ($jetons as $i => $jeton) {
            if ($jeton->is(T_NAMESPACE) && ($jetons[$i + 1] ?? null)?->is([T_STRING, T_NAME_QUALIFIED]) === true) {
                $contexte = ['espace' => $jetons[$i + 1]->text, 'imports' => [], 'classe' => null];
            } elseif ($jeton->is(T_USE) && $pile === []) {
                $contexte['imports'] = [...$contexte['imports'], ...self::importsDe($jetons, $i)];
            } elseif ($jeton->is([T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM]) && ! self::estPrecedeDe($jetons, $i, ['::']) && ($jetons[$i + 1] ?? null)?->is(T_STRING) === true) {
                $enAttente = ['classe', ltrim($contexte['espace'].'\\'.$jetons[$i + 1]->text, '\\')];
            } elseif ($jeton->is(T_FUNCTION)) {
                $nom = ($jetons[$i + 1] ?? null)?->text === '&' ? ($jetons[$i + 2] ?? null) : ($jetons[$i + 1] ?? null);
                $enAttente = $nom?->is(T_STRING) === true ? ['methode', $nom->text] : $enAttente;
            } elseif ($jeton->text === ';' && $enAttente !== null && $enAttente[0] === 'methode') {
                $enAttente = null;
            } elseif ($jeton->text === '{' || $jeton->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $pile[] = $jeton->text === '{' ? $enAttente : null;
                $enAttente = $jeton->text === '{' ? null : $enAttente;
            } elseif ($jeton->text === '}') {
                array_pop($pile);
            }

            $classe = null;
            $methode = null;

            foreach ($pile as $etiquette) {
                if ($etiquette !== null && $etiquette[0] === 'classe') {
                    $classe = $etiquette[1];
                } elseif ($etiquette !== null) {
                    $methode = $etiquette[1];
                }
            }

            $contexte['classe'] = $classe;
            $entete = self::lectureEn($jetons, $i, $contexte);

            if ($entete !== false) {
                $lectures[] = ['ligne' => $jeton->line, 'entete' => $entete, 'classe' => $classe, 'methode' => $methode];
            }
        }

        return $lectures;
    }

    /**
     * Les routes de l'application qui lisent chaque en-tête recensé : celles
     * qui nomment le middleware qui le lit, celles du contrôleur qui le lit
     * (de sa méthode quand c'est une action), celles dont l'action reçoit la
     * requête de validation qui le lit (ou une requête qui utilise le trait
     * qui le lit), celle dont la fermeture le lit. Pour chacune, la lecture
     * se fait-elle après les middlewares, dans le contrôleur, la requête ou
     * la fermeture ? Les lectures sont celles de l'application, ou
     * `$lectures`.
     *
     * @param  list<array{fichier: string, ligne: int, entete: string|null, classe: string|null, methode: string|null}>|null  $lectures
     * @return array<string, list<array{route: Route, apresLesMiddlewares: bool}>>
     */
    public static function routesQuiLisent(?array $lectures = null): array
    {
        $routes = RequetesMalFormees::routes(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);
        $parNom = array_change_key_case(array_combine(array_keys(self::recenses()), array_keys(self::recenses())));
        $lisent = [];

        foreach ($lectures ?? self::lecturesDeLApplication() as $lecture) {
            $entete = $parNom[$lecture['entete'] ?? ''] ?? null;
            $deduites = self::routesDeLaLecture($lecture, $routes);

            if ($entete === null || $deduites === null) {
                continue;
            }

            foreach ($deduites['routes'] as $route) {
                $cle = spl_object_id($route);
                $apres = $deduites['apresLesMiddlewares'] || ($lisent[$entete][$cle]['apresLesMiddlewares'] ?? false);
                $lisent[$entete][$cle] = ['route' => $route, 'apresLesMiddlewares' => $apres];
            }
        }

        return array_map(array_values(...), $lisent);
    }

    /**
     * Ce qui ne va pas dans le recensement : une lecture dont le texte ne
     * dit pas le nom, un en-tête lu que `recenses()` ne connaît pas, un
     * en-tête recensé que plus rien ne lit, une lecture dont les routes ne se
     * déduisent pas, une écriture qui lit un en-tête après ses middlewares
     * sans requête valide dans `requetesValides()`, une requête valide qui ne
     * sert plus. Les lectures sont celles de l'application, ou `$lectures`.
     *
     * @param  list<array{fichier: string, ligne: int, entete: string|null, classe: string|null, methode: string|null}>|null  $lectures
     * @return list<string>
     */
    public static function defautsDuRecensement(?array $lectures = null): array
    {
        $routes = RequetesMalFormees::routes(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);
        $recenses = array_map(strtolower(...), array_keys(self::recenses()));
        $lus = [];
        $avecRequeteValide = [];
        $defauts = [];

        foreach ($lectures ?? self::lecturesDeLApplication() as $lecture) {
            $ou = "{$lecture['fichier']}:{$lecture['ligne']}";
            $entete = $lecture['entete'];

            if ($entete === null || $entete === '*') {
                $defauts[] = "{$ou} : le nom de l'en-tête lu ne se lit pas dans le texte (une chaîne ou une constante de classe)";

                continue;
            }

            $lus[] = $entete;

            if (! in_array($entete, $recenses, true)) {
                $defauts[] = "{$ou} : {$entete} n'est pas recensé (EntetesLus::recenses())";

                continue;
            }

            $deduites = self::routesDeLaLecture($lecture, $routes);

            if ($deduites === null || $deduites['routes'] === []) {
                $defauts[] = "{$ou} : lu hors d'un middleware, d'un contrôleur ou d'une requête de validation d'une route, ses routes ne se déduisent pas";

                continue;
            }

            foreach ($deduites['apresLesMiddlewares'] ? $deduites['routes'] : [] as $route) {
                if ($route->methods() === ['GET', 'HEAD']) {
                    continue;
                }

                $avecRequeteValide[] = (string) $route->getName();

                if (! array_key_exists((string) $route->getName(), self::requetesValides())) {
                    $defauts[] = "{$ou} : {$route->getName()} lit {$entete} après ses middlewares, sans requête valide (EntetesLus::requetesValides())";
                }
            }
        }

        foreach (array_diff($recenses, $lus) as $perime) {
            $defauts[] = "{$perime} est recensé, mais plus rien ne le lit";
        }

        foreach (array_diff(array_keys(self::requetesValides()), $avecRequeteValide) as $perimee) {
            $defauts[] = "La requête valide de {$perimee} ne sert plus : aucune lecture d'en-tête après ses middlewares";
        }

        return array_values(array_unique($defauts));
    }

    /**
     * Les routes d'une lecture, et si elle se fait après les middlewares ;
     * `null` quand elle n'est ni dans un middleware qu'une route nomme, ni
     * dans un contrôleur, ni dans une requête de validation (ou l'un de ses
     * traits), ni dans la fermeture d'une route.
     *
     * @param  array{fichier: string, ligne: int, classe: string|null, methode: string|null}  $lecture
     * @param  list<Route>  $routes
     * @return array{routes: list<Route>, apresLesMiddlewares: bool}|null
     */
    private static function routesDeLaLecture(array $lecture, array $routes): ?array
    {
        $classe = $lecture['classe'];

        if ($classe === null) {
            $deLaFermeture = array_values(array_filter($routes, static fn (Route $route): bool => self::fermeturePorte($route, $lecture['fichier'], $lecture['ligne'])));

            return $deLaFermeture === [] ? null : ['routes' => $deLaFermeture, 'apresLesMiddlewares' => true];
        }

        $routeur = app(Router::class);
        $parLeMiddleware = array_values(array_filter($routes, static fn (Route $route): bool => in_array(
            $classe,
            array_map(static fn (mixed $maillon): string => is_string($maillon) ? Str::before($maillon, ':') : '', $routeur->gatherRouteMiddleware($route)),
            true,
        )));

        if ($parLeMiddleware !== []) {
            return ['routes' => $parLeMiddleware, 'apresLesMiddlewares' => false];
        }

        $duControleur = array_values(array_filter($routes, static fn (Route $route): bool => RequetesMalFormees::classeDeLAction($route) === $classe));

        if ($duControleur !== []) {
            $deLaMethode = array_values(array_filter($duControleur, static fn (Route $route): bool => self::methodeDeLAction($route) === $lecture['methode']));

            return ['routes' => $deLaMethode === [] ? $duControleur : $deLaMethode, 'apresLesMiddlewares' => true];
        }

        $requetes = match (true) {
            is_subclass_of($classe, FormRequest::class) => [$classe],
            trait_exists($classe) => array_values(array_filter(
                array_keys(ReglesDesRequetes::toutes()),
                static fn (string $requete): bool => in_array($classe, class_uses_recursive($requete), true),
            )),
            default => [],
        };

        if ($requetes === []) {
            return null;
        }

        return [
            'routes' => array_values(array_filter($routes, static fn (Route $route): bool => array_intersect(self::typesRecusParLAction($route), $requetes) !== [])),
            'apresLesMiddlewares' => true,
        ];
    }

    /**
     * L'action d'une route est-elle une fermeture écrite dans ce fichier
     * (relatif à la racine), autour de cette ligne ?
     */
    private static function fermeturePorte(Route $route, string $fichier, int $ligne): bool
    {
        $action = $route->getAction('uses');

        if (! $action instanceof Closure) {
            return false;
        }

        $fermeture = new ReflectionFunction($action);

        return $fermeture->getFileName() === realpath(base_path($fichier))
            && $ligne >= (int) $fermeture->getStartLine()
            && $ligne <= (int) $fermeture->getEndLine();
    }

    /**
     * La méthode du contrôleur qu'une route appelle (`__invoke` pour un
     * contrôleur invocable), ou une chaîne vide pour une fermeture.
     */
    private static function methodeDeLAction(Route $route): string
    {
        $action = $route->getAction('uses');

        if (! is_string($action)) {
            return '';
        }

        return str_contains($action, '@') ? Str::after($action, '@') : '__invoke';
    }

    /**
     * Les classes que l'action d'une route reçoit en paramètre.
     *
     * @return list<string>
     */
    private static function typesRecusParLAction(Route $route): array
    {
        $action = $route->getAction('uses');
        $classe = RequetesMalFormees::classeDeLAction($route);

        $parametres = match (true) {
            $action instanceof Closure => new ReflectionFunction($action)->getParameters(),
            class_exists($classe) && method_exists($classe, self::methodeDeLAction($route)) => new ReflectionMethod($classe, self::methodeDeLAction($route))->getParameters(),
            default => [],
        };

        $types = [];

        foreach ($parametres as $parametre) {
            $type = $parametre->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $types[] = $type->getName();
            }
        }

        return $types;
    }

    /**
     * L'en-tête que lit l'appel qui commence au jeton `$i` (voir
     * `lecturesDuCode()`), ou faux s'il n'en lit pas.
     *
     * @param  list<PhpToken>  $jetons
     * @param  array{espace: string, imports: array<string, string>, classe: string|null}  $contexte
     */
    private static function lectureEn(array $jetons, int $i, array $contexte): string|null|false
    {
        $jeton = $jetons[$i];
        $suivant = $jetons[$i + 1] ?? null;
        $ouvrante = ($jetons[$i + 2] ?? null)?->text === '(';

        if ($jeton->text === '$_SERVER') {
            return ($suivant?->text === '[') ? self::nomDuServeur(self::nomEnArgument($jetons, $i + 2, $contexte)) : null;
        }

        $fonction = $jeton->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && $suivant?->text === '(' && ! self::estPrecedeDe($jetons, $i, ['->', '?->', '::', 'function', 'new'])
            ? strtolower(ltrim($jeton->text, '\\'))
            : '';

        if (in_array($fonction, self::FONCTIONS_DE_TOUS_LES_ENTETES, true)) {
            return '*';
        }

        if ($fonction === 'getenv') {
            return self::nomDuServeur(self::nomEnArgument($jetons, $i + 2, $contexte));
        }

        $acces = $jeton->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]);

        if ($acces && $suivant?->is(T_STRING) === true && isset(self::SACS[$suivant->text]) && ! $ouvrante) {
            $lue = self::lectureDuSac($jetons, $i, $contexte);

            return $lue === false || self::nEstPasLaRequete($jetons, $i - 1, $contexte) ? false : $lue;
        }

        $classe = $jeton->is(T_DOUBLE_COLON) && ($jetons[$i - 1] ?? null)?->is(self::JETONS_DE_TYPE) === true
            ? self::classeNommee($jetons[$i - 1]->text, $contexte)
            : null;
        $statique = $classe !== null && self::estLaRequete($classe);

        if ((! $acces && ! $statique) || $suivant?->is(T_STRING) !== true || ! $ouvrante) {
            return false;
        }

        if ($acces && isset(self::SACS[$jetons[$i - 1]->text]) && self::estPrecedeDe($jetons, $i - 1, ['->', '?->'])) {
            return false;
        }

        $methode = strtolower($suivant->text);

        $lue = match (true) {
            in_array($methode, self::LECTURES_PAR_NOM, true) => self::nomEnArgument($jetons, $i + 3, $contexte),
            array_key_exists($methode, self::LECTURES_FIXES) => self::LECTURES_FIXES[$methode],
            $methode === 'server' => self::nomDuServeur(self::nomEnArgument($jetons, $i + 3, $contexte)),
            default => false,
        };

        if ($lue === false || ($statique ? false : self::nEstPasLaRequete($jetons, $i - 1, $contexte))) {
            return false;
        }

        return $lue;
    }

    /**
     * Ce que lit l'accès au sac du jeton `$i` (`->headers` ou `->server`,
     * sans parenthèse) : le nom que reçoit une méthode qui lit par nom
     * (`SACS`), `*` pour une méthode qui les lit tous, faux pour une écriture
     * (une méthode qui écrit, une affectation de la propriété), `null` pour
     * tout autre usage (le sac rangé dans une variable, parcouru, passé, ou
     * une autre méthode).
     *
     * @param  list<PhpToken>  $jetons
     * @param  array{espace: string, imports: array<string, string>, classe: string|null}  $contexte
     */
    private static function lectureDuSac(array $jetons, int $i, array $contexte): string|null|false
    {
        $sac = self::SACS[$jetons[$i + 1]->text];
        $apres = $jetons[$i + 2] ?? null;
        $methode = $jetons[$i + 3] ?? null;

        if ($apres?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) !== true || $methode?->is(T_STRING) !== true || ($jetons[$i + 4] ?? null)?->text !== '(') {
            return $apres?->text === '=' ? false : null;
        }

        $nom = strtolower($methode->text);
        $argument = self::nomEnArgument($jetons, $i + 5, $contexte);

        return match (true) {
            in_array($nom, $sac['parNom'], true) => $jetons[$i + 1]->text === 'server' ? self::nomDuServeur($argument) : $argument,
            in_array($nom, $sac['tous'], true) => '*',
            in_array($nom, $sac['ecritures'], true) => false,
            default => null,
        };
    }

    /**
     * Une classe désigne-t-elle la requête : celle de Laravel ou de Symfony
     * (ou une fille), ou la façade `Request`, sous un alias compris ? Une
     * classe que l'autochargement ne trouve pas compte pour la requête si son
     * nom court est `Request` : dans le doute, la garde tombe.
     */
    private static function estLaRequete(string $classe): bool
    {
        if (! class_exists($classe) && ! interface_exists($classe)) {
            return class_basename($classe) === 'Request';
        }

        $nom = new ReflectionClass($classe)->getName();

        return $nom === RequeteDeLaFacade::class || is_a($nom, RequeteDeSymfony::class, true);
    }

    /**
     * La classe que déclare le type du paramètre qu'est la variable du jeton
     * `$j` (`Response $retour`, `self $exception`), cherchée en remontant
     * jusqu'au mot `function` ou `fn` le plus proche ; `null` pour une
     * variable sans type déclaré dans la fonction qui la reçoit.
     *
     * @param  list<PhpToken>  $jetons
     * @param  array{espace: string, imports: array<string, string>, classe: string|null}  $contexte
     */
    private static function typeDuParametre(array $jetons, int $j, array $contexte): ?string
    {
        for ($k = $j - 1; $k > 1; $k--) {
            if ($jetons[$k]->is([T_FUNCTION, T_FN])) {
                return null;
            }

            if ($jetons[$k]->is(T_VARIABLE) && $jetons[$k]->text === $jetons[$j]->text && $jetons[$k - 1]->is(self::JETONS_DE_TYPE) && in_array($jetons[$k - 2]->text, ['(', ',', '?', ']'], true)) {
                return self::classeNommee($jetons[$k - 1]->text, $contexte);
            }
        }

        return null;
    }

    /**
     * Le nom d'un en-tête lu parmi les variables de serveur : `HTTP_X_FOO`
     * donne `x-foo` ; faux pour une autre variable (`REMOTE_ADDR`), qui n'est
     * pas un en-tête ; `*` ou `null` passent tels quels.
     */
    private static function nomDuServeur(?string $variable): string|null|false
    {
        if ($variable === null || $variable === '*') {
            return $variable;
        }

        return str_starts_with($variable, 'http-') ? substr($variable, 5) : false;
    }

    /**
     * Le nom que donne l'argument qui commence au jeton `$k`, normalisé
     * (`normaliser()`) : `*` sans argument, `null` quand le texte ne le dit
     * pas.
     *
     * @param  list<PhpToken>  $jetons
     * @param  array{espace: string, imports: array<string, string>, classe: string|null}  $contexte
     */
    private static function nomEnArgument(array $jetons, int $k, array $contexte): ?string
    {
        $premier = $jetons[$k] ?? null;
        $fin = static fn (int $indice): bool => in_array(($jetons[$indice] ?? null)?->text, [')', ']', ','], true);

        if ($premier === null || in_array($premier->text, [')', ']'], true)) {
            return '*';
        }

        if ($premier->is(T_CONSTANT_ENCAPSED_STRING) && $fin($k + 1)) {
            return self::normaliser(stripcslashes(substr($premier->text, 1, -1)));
        }

        $constante = $jetons[$k + 2] ?? null;

        if ($premier->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC]) && ($jetons[$k + 1] ?? null)?->text === '::' && $constante?->is(T_STRING) === true && $fin($k + 3)) {
            $classe = self::classeNommee($premier->text, $contexte);
            $valeur = $classe !== null && defined("{$classe}::{$constante->text}") ? constant("{$classe}::{$constante->text}") : null;

            return is_string($valeur) ? self::normaliser($valeur) : null;
        }

        return null;
    }

    /**
     * Un nom d'en-tête comme Symfony le range : en minuscules, `-` pour `_`.
     */
    private static function normaliser(string $nom): string
    {
        return strtr(strtolower($nom), '_', '-');
    }

    /**
     * La classe qu'un nom désigne dans le fichier : `self` et `static` la
     * classe courante, un nom importé par son `use`, un autre dans l'espace de
     * noms du fichier.
     *
     * @param  array{espace: string, imports: array<string, string>, classe: string|null}  $contexte
     */
    private static function classeNommee(string $nom, array $contexte): ?string
    {
        if (in_array(strtolower($nom), ['self', 'static'], true)) {
            return $contexte['classe'];
        }

        if (str_starts_with($nom, '\\')) {
            return ltrim($nom, '\\');
        }

        $tete = Str::before($nom, '\\');
        $reste = str_contains($nom, '\\') ? '\\'.Str::after($nom, '\\') : '';

        return array_key_exists($tete, $contexte['imports'])
            ? $contexte['imports'][$tete].$reste
            : ltrim($contexte['espace'].'\\'.$nom, '\\');
    }

    /**
     * Les classes qu'importe le `use` du jeton `$i`, sous leur nom court
     * (`use A\B;`, `use A\B as C;`, `use A\{B, C as D};`) ; rien pour un
     * `use function` ou `use const`.
     *
     * @param  list<PhpToken>  $jetons
     * @return array<string, string>
     */
    private static function importsDe(array $jetons, int $i): array
    {
        if (in_array(($jetons[$i + 1] ?? null)?->text, ['function', 'const'], true)) {
            return [];
        }

        $texte = '';

        for ($j = $i + 1; isset($jetons[$j]) && $jetons[$j]->text !== ';'; $j++) {
            $texte .= $jetons[$j]->text.($jetons[$j]->is(T_AS) ? ' ' : '');
            $texte .= ($jetons[$j + 1] ?? null)?->is(T_AS) === true ? ' ' : '';
        }

        $prefixe = str_contains($texte, '{') ? Str::before($texte, '{') : '';
        $noms = str_contains($texte, '{') ? explode(',', Str::between($texte, '{', '}')) : [$texte];
        $imports = [];

        foreach ($noms as $nom) {
            [$complet, $alias] = array_pad(array_map(trim(...), explode(' as ', trim($nom))), 2, null);
            $complet = ltrim($prefixe.$complet, '\\');

            if ($complet !== '') {
                $imports[$alias ?? class_basename($complet)] = $complet;
            }
        }

        return $imports;
    }

    /**
     * Le jeton qui précède `$i` est-il l'un de ces textes ?
     *
     * @param  list<PhpToken>  $jetons
     * @param  list<string>  $textes
     */
    private static function estPrecedeDe(array $jetons, int $i, array $textes): bool
    {
        return in_array(($jetons[$i - 1] ?? null)?->text, $textes, true);
    }

    /**
     * Le receveur qui finit au jeton `$j` est-il autre chose que la requête ?
     * On remonte la chaîne (`$a->b()->c`) jusqu'à un nom qui tranche : une
     * variable ou une propriété dit son nom (`$reponse`, `->response`), un
     * appel de fonction ou de classe en tête aussi (`response()`, `Http::`) ;
     * le résultat d'une méthode ne dit rien, et l'on remonte. Une variable
     * que la fonction reçoit sous un type qui n'est pas une requête
     * (`Response $retour`, `self $exception` dans un modèle) n'en est pas
     * une. Rien ne tranche : une requête.
     *
     * @param  list<PhpToken>  $jetons
     * @param  array{espace: string, imports: array<string, string>, classe: string|null}  $contexte
     */
    private static function nEstPasLaRequete(array $jetons, int $j, array $contexte): bool
    {
        while ($j >= 0 && isset($jetons[$j])) {
            $jeton = $jetons[$j];

            if ($jeton->text === ')' || $jeton->text === ']') {
                $j = self::ouvranteDe($jetons, $j) - 1;
                $appel = $jetons[$j] ?? null;

                if ($jeton->text === ']') {
                    continue;
                }

                if ($appel === null || ! $appel->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    return false;
                }

                if (self::estPrecedeDe($jetons, $j, ['->', '?->'])) {
                    $j -= 2;

                    continue;
                }

                $tete = self::estPrecedeDe($jetons, $j, ['::']) ? class_basename(ltrim(($jetons[$j - 2] ?? $appel)->text, '\\')).'::' : $appel->text.'()';

                return in_array($tete, self::TETES_DE_REPONSE, true);
            }

            if ($jeton->is([T_VARIABLE, T_STRING])) {
                if (preg_match('/^\$?(reponse|response)/i', $jeton->text) === 1) {
                    return true;
                }

                if ($jeton->is(T_STRING) && self::estPrecedeDe($jetons, $j, ['->', '?->'])) {
                    return false;
                }

                if ($jeton->is(T_STRING) && self::estPrecedeDe($jetons, $j, ['::'])) {
                    return in_array(class_basename(ltrim(($jetons[$j - 2] ?? $jeton)->text, '\\')).'::', self::TETES_DE_REPONSE, true);
                }

                $type = $jeton->is(T_VARIABLE) ? self::typeDuParametre($jetons, $j, $contexte) : null;

                return $type !== null && (class_exists($type) || interface_exists($type)) && ! self::estLaRequete($type);
            }

            return false;
        }

        return false;
    }

    /**
     * L'indice de la parenthèse ou du crochet qui ouvre celui qui ferme en
     * `$j`.
     *
     * @param  list<PhpToken>  $jetons
     */
    private static function ouvranteDe(array $jetons, int $j): int
    {
        $fermante = $jetons[$j]->text;
        $ouvrante = $fermante === ')' ? '(' : '[';
        $profondeur = 0;

        for (; $j >= 0; $j--) {
            $profondeur += match ($jetons[$j]->text) {
                $fermante => 1,
                $ouvrante => -1,
                default => 0,
            };

            if ($profondeur === 0) {
                return $j;
            }
        }

        return -1;
    }
}
