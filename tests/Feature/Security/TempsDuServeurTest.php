<?php

declare(strict_types=1);

use App\Http\Middleware\TempsDuServeur;
use App\Models\User;
use App\Models\Workout;
use App\Providers\TempsDuServeurServiceProvider;
use App\Support\TempsDuServeur\MesureDuTempsServeur;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\CurrentApplication;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FilamentAdminPanel;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/**
 * Un en-tête `Server-Timing` qui dit ce qu'une réponse a coûté au serveur (#1315).
 *
 * L'issue cherche d'où vient « faire une action, puis attendre », et rien dans
 * l'application ne comptait de durée : la seule mesure possible était un `curl`
 * de l'extérieur, réseau compris. L'en-tête donne la durée de l'application, ses
 * étapes, le nombre et la durée cumulée des requêtes SQL, et, depuis l'étape 1
 * de #2148, ceux des lectures du cache, lisibles dans l'onglet réseau du
 * navigateur ou par `curl -I`.
 *
 * Trois décisions du propriétaire du dépôt le bornent :
 * - coupé par défaut, allumé par `SERVER_TIMING_ENABLED` le temps d'une mesure ;
 * - jamais pour un invité, ni sur une réponse d'authentification : une durée et
 *   un nombre de requêtes diraient si un compte existe ;
 * - rien qui vive d'une requête à l'autre sous Octane.
 */

/**
 * Allume la mesure comme un démarrage avec `SERVER_TIMING_ENABLED=true` : la
 * configuration, puis le fournisseur, qui pose ses écouteurs à son démarrage.
 */
function tempsServeurAllumer(): void
{
    config(['app.temps_serveur' => true]);
    app()->register(TempsDuServeurServiceProvider::class, force: true);
}

/**
 * Le nombre d'écouteurs des requêtes SQL d'un répartiteur d'événements.
 */
function tempsServeurEcouteursSql(Dispatcher $evenements): int
{
    return count($evenements->getListeners(QueryExecuted::class));
}

/**
 * Le nombre d'écouteurs d'un événement, pour voir qu'aucun ne s'empile.
 *
 * @param  class-string  $evenement
 */
function tempsServeurEcouteurs(Dispatcher $evenements, string $evenement): int
{
    return count($evenements->getListeners($evenement));
}

/**
 * Le nombre de lectures du cache qu'annonce l'en-tête, ou null sans en-tête.
 */
function tempsServeurLecturesAnnoncees(Response $reponse): ?int
{
    $enTete = $reponse->headers->get('Server-Timing');

    if ($enTete === null) {
        return null;
    }

    expect($enTete)->toMatch('/\bcache;dur=\d+\.\d;desc="\d+ lectures?"/');
    preg_match('/\bcache;dur=\d+\.\d;desc="(\d+) lectures?"/', $enTete, $trouve);

    return (int) ($trouve[1] ?? -1);
}

/**
 * Une mesure dont l'horloge avance à la main, en millisecondes : ce que le
 * test fait se lit sur la durée annoncée, sans dépendre de la machine.
 *
 * @return array{0: MesureDuTempsServeur, 1: Closure(float): void}
 */
function tempsServeurMesureAHorloge(): array
{
    $instant = 0;
    $mesure = new MesureDuTempsServeur(function () use (&$instant): int {
        return $instant;
    });
    $avancerA = function (float $millisecondes) use (&$instant): void {
        $instant = (int) round($millisecondes * 1_000_000);
    };

    return [$mesure, $avancerA];
}

/**
 * La durée cumulée du cache qu'annonce une valeur d'en-tête.
 */
function tempsServeurDureeDuCache(string $enTete): float
{
    preg_match('/\bcache;dur=(\d+\.\d);desc="\d+ lectures?"/', $enTete, $trouve);

    return (float) ($trouve[1] ?? -1);
}

/**
 * Le nombre de requêtes SQL qu'annonce l'en-tête, ou null sans en-tête.
 */
function tempsServeurRequetesAnnoncees(Response $reponse): ?int
{
    $enTete = $reponse->headers->get('Server-Timing');

    if ($enTete === null) {
        return null;
    }

    expect($enTete)->toMatch('/\bsql;dur=\d+\.\d;desc="\d+ requetes?"/');
    preg_match('/\bsql;dur=\d+\.\d;desc="(\d+) requetes?"/', $enTete, $trouve);

    return (int) ($trouve[1] ?? -1);
}

/**
 * Une route qui fait autant de requêtes SQL qu'on le lui demande, autant de
 * lectures simples du cache (`cache`) et une lecture groupée de `plusieurs`
 * clés, et qui pose un utilisateur sur la garde web quand `connecte` est
 * vrai. Hors du groupe `web` : ni session ni rien d'autre n'y ajoute de
 * requête ni de lecture.
 */
function tempsServeurRouteDeMesure(Router $routeur): void
{
    $routeur->get('/_temps-serveur/{nombre}', function (Request $requete, int $nombre): Response {
        if ($requete->boolean('connecte')) {
            Auth::guard('web')->setUser(new User());
        }

        for ($rang = 0; $rang < $nombre; $rang++) {
            DB::select('select 1');
        }

        for ($rang = 0; $rang < $requete->integer('cache'); $rang++) {
            Cache::get('temps-serveur.'.$rang);
        }

        if ($requete->integer('plusieurs') > 0) {
            Cache::many(array_map(fn (int $rang): string => 'temps-serveur.groupe.'.$rang, range(1, $requete->integer('plusieurs'))));
        }

        return response('mesuré');
    });
}

/**
 * Pose `SERVER_TIMING_ENABLED` comme la pile la transmettrait, ou la retire
 * quand `$valeur` est null, et rend ce qu'il y avait avant.
 *
 * @return array{serveur: mixed, env: mixed, putenv: string|false}
 */
function tempsServeurPoserLaVariable(?string $valeur): array
{
    $precedentes = [
        'serveur' => $_SERVER['SERVER_TIMING_ENABLED'] ?? null,
        'env' => $_ENV['SERVER_TIMING_ENABLED'] ?? null,
        'putenv' => getenv('SERVER_TIMING_ENABLED'),
    ];

    if ($valeur === null) {
        unset($_SERVER['SERVER_TIMING_ENABLED'], $_ENV['SERVER_TIMING_ENABLED']);
        putenv('SERVER_TIMING_ENABLED');
    } else {
        $_SERVER['SERVER_TIMING_ENABLED'] = $_ENV['SERVER_TIMING_ENABLED'] = $valeur;
        putenv('SERVER_TIMING_ENABLED='.$valeur);
    }

    return $precedentes;
}

/**
 * Rend à l'environnement ce que `tempsServeurPoserLaVariable()` a changé.
 *
 * @param  array{serveur: mixed, env: mixed, putenv: string|false}  $precedentes
 */
function tempsServeurRendreLaVariable(array $precedentes): void
{
    unset($_SERVER['SERVER_TIMING_ENABLED'], $_ENV['SERVER_TIMING_ENABLED']);

    if ($precedentes['serveur'] !== null) {
        $_SERVER['SERVER_TIMING_ENABLED'] = $precedentes['serveur'];
    }

    if ($precedentes['env'] !== null) {
        $_ENV['SERVER_TIMING_ENABLED'] = $precedentes['env'];
    }

    putenv($precedentes['putenv'] === false ? 'SERVER_TIMING_ENABLED' : 'SERVER_TIMING_ENABLED='.$precedentes['putenv']);
}

it('n’envoie aucun en-tête et ne pose aucun écouteur tant que la variable n’est pas allumée', function (): void {
    $ecouteurs = tempsServeurEcouteursSql(app('events'));
    $evenementsDuCache = [RetrievingKey::class, RetrievingManyKeys::class, CacheHit::class, CacheMissed::class];
    $ecouteursDuCache = array_map(fn (string $evenement): int => tempsServeurEcouteurs(app('events'), $evenement), $evenementsDuCache);

    app()->register(TempsDuServeurServiceProvider::class, force: true);

    expect(config('app.temps_serveur'))->toBeFalse()
        ->and(tempsServeurEcouteursSql(app('events')))->toBe($ecouteurs)
        ->and(array_map(fn (string $evenement): int => tempsServeurEcouteurs(app('events'), $evenement), $evenementsDuCache))->toBe($ecouteursDuCache);

    actingAs(User::factory()->create())->get('/dashboard')
        ->assertOk()
        ->assertHeaderMissing('Server-Timing');

    // Coupé, le middleware ne fait que lire la configuration : aucune mesure ouverte.
    expect(app()->resolved(MesureDuTempsServeur::class))->toBeFalse();

    tempsServeurAllumer();

    expect(tempsServeurEcouteursSql(app('events')))->toBe($ecouteurs + 1)
        ->and(array_map(fn (string $evenement): int => tempsServeurEcouteurs(app('events'), $evenement), $evenementsDuCache))
        ->toBe(array_map(fn (int $nombre): int => $nombre + 1, $ecouteursDuCache));
});

/**
 * Une variable que la pile transmet sans valeur arrive vide, pas absente : vide,
 * absente ou illisible, elle coupe la mesure.
 */
it('tient la variable absente, vide ou illisible pour coupée', function (?string $valeur, bool $attendu): void {
    $precedentes = tempsServeurPoserLaVariable($valeur);

    try {
        /** @var array{temps_serveur: mixed} $configuration */
        $configuration = require config_path('app.php');
    } finally {
        tempsServeurRendreLaVariable($precedentes);
    }

    expect($configuration['temps_serveur'])->toBe($attendu);
})->with([
    'absente' => [null, false],
    'vide' => ['', false],
    'false' => ['false', false],
    'zéro' => ['0', false],
    'illisible' => ['peut-être', false],
    'true' => ['true', true],
    'un' => ['1', true],
]);

it('mesure la page d’un utilisateur connecté : application, étapes et requêtes SQL', function (): void {
    tempsServeurAllumer();

    $reponse = actingAs(User::factory()->create())->get('/dashboard')->assertOk();
    $enTete = (string) $reponse->headers->get('Server-Timing');

    expect($enTete)->toMatch('/^app;dur=\d+\.\d, routage;dur=\d+\.\d, controleur;dur=\d+\.\d, rendu;dur=\d+\.\d, sql;dur=\d+\.\d;desc="\d+ requetes?", cache;dur=\d+\.\d;desc="\d+ lectures?"$/')
        ->and(tempsServeurRequetesAnnoncees($reponse->baseResponse))->toBeGreaterThan(0)
        // Les pierres tombales de la session et les props partagées lisent le cache à chaque page.
        ->and(tempsServeurLecturesAnnoncees($reponse->baseResponse))->toBeGreaterThan(0);

    preg_match_all('/(\w+);dur=(\d+\.\d)/', $enTete, $mesures);
    $durees = array_combine($mesures[1], array_map(floatval(...), $mesures[2]));

    // Les trois étapes se suivent sans trou ni chevauchement : leur somme est la
    // durée de l'application, aux arrondis près.
    expect(abs($durees['routage'] + $durees['controleur'] + $durees['rendu'] - $durees['app']))->toBeLessThanOrEqual(0.2)
        ->and($durees['sql'])->toBeLessThanOrEqual($durees['app'])
        ->and($durees['cache'])->toBeLessThanOrEqual($durees['app']);
});

/**
 * Une requête SQL hors de toute requête HTTP — une tâche de file, une commande,
 * le démarrage d'un worker Octane — ne doit pas créer de mesure : créée dans
 * l'application de base d'Octane, elle passerait à chaque clone, donc à toutes
 * les requêtes suivantes.
 */
it('ne crée aucune mesure pour une requête SQL faite hors d’une requête HTTP', function (): void {
    tempsServeurAllumer();

    DB::select('select 1');

    expect(app()->resolved(MesureDuTempsServeur::class))->toBeFalse();
});

it('ne crée aucune mesure pour une lecture du cache faite hors d’une requête HTTP', function (): void {
    tempsServeurAllumer();

    Cache::get('temps-serveur.hors-requete');
    Cache::many(['temps-serveur.a', 'temps-serveur.b']);
    Cache::remember('temps-serveur.c', 60, fn (): string => 'valeur');

    expect(app()->resolved(MesureDuTempsServeur::class))->toBeFalse();
});

it('compte les requêtes SQL de chaque requête, et d’elle seule', function (): void {
    tempsServeurAllumer();
    tempsServeurRouteDeMesure(app('router'));

    foreach ([2, 5, 0, 1] as $nombre) {
        $reponse = get("/_temps-serveur/{$nombre}?connecte=1")->assertOk();

        expect(tempsServeurRequetesAnnoncees($reponse->baseResponse))->toBe($nombre);
    }
});

/*
 * Une page connectée lit le cache au moins six fois (session, pierres
 * tombales, props partagées, versions des statistiques : #2148, étape 1).
 * Chaque clé compte pour une lecture, qu'elle soit trouvée ou non, et une
 * lecture groupée (`many()`) compte autant de lectures que de clés.
 */
it('compte les lectures du cache de chaque requête, et d’elle seule, une par clé demandée', function (): void {
    tempsServeurAllumer();
    tempsServeurRouteDeMesure(app('router'));
    Cache::put('temps-serveur.0', 'trouvée');

    foreach ([[2, 0, 2], [0, 3, 3], [1, 4, 5], [0, 0, 0]] as [$lectures, $groupees, $attendues]) {
        $reponse = get("/_temps-serveur/1?connecte=1&cache={$lectures}&plusieurs={$groupees}")->assertOk();

        expect(tempsServeurLecturesAnnoncees($reponse->baseResponse))->toBe($attendues)
            ->and(tempsServeurRequetesAnnoncees($reponse->baseResponse))->toBe(1);
    }

    expect((string) get('/_temps-serveur/0?connecte=1&cache=1')->headers->get('Server-Timing'))
        ->toMatch('/, cache;dur=\d+\.\d;desc="1 lecture"$/');
});

/*
 * La durée se prend entre la demande au magasin (`RetrievingKey`,
 * `RetrievingManyKeys`) et sa réponse (`CacheHit`, `CacheMissed`) : un magasin
 * qui met vingt millisecondes à répondre, deux fois, en coûte au moins
 * quarante. Seule la borne basse est sûre sur une machine chargée.
 */
it('compte dans la durée du cache le temps que le magasin met à répondre', function (): void {
    tempsServeurAllumer();
    Cache::extend('temps-serveur-lent', fn (): Repository => Cache::repository(new class() extends ArrayStore
    {
        /**
         * @param  string  $key
         */
        #[\Override]
        public function get($key): mixed
        {
            usleep(20_000);

            return parent::get($key);
        }

        /**
         * @param  array<mixed>  $keys
         * @return array<mixed>
         */
        #[\Override]
        public function many(array $keys): array
        {
            usleep(20_000);

            return parent::many($keys);
        }
    }));
    config(['cache.stores.temps-serveur-lent' => ['driver' => 'temps-serveur-lent']]);
    Route::get('/_temps-serveur-lent', function (): Response {
        Auth::guard('web')->setUser(new User());
        Cache::store('temps-serveur-lent')->get('une');
        Cache::store('temps-serveur-lent')->many(['deux', 'trois', 'quatre']);

        return response('mesuré');
    });

    $reponse = get('/_temps-serveur-lent')->assertOk();
    $enTete = (string) $reponse->headers->get('Server-Timing');

    expect(tempsServeurLecturesAnnoncees($reponse->baseResponse))->toBe(4)
        ->and(tempsServeurDureeDuCache($enTete))->toBeGreaterThanOrEqual(40.0);
});

it('compte une lecture du temps de sa demande à sa réponse, et un groupe jusqu’à la réponse de sa dernière clé', function (): void {
    [$mesure, $avancerA] = tempsServeurMesureAHorloge();
    $mesure->demarrer();

    $avancerA(10);
    $mesure->noterUneDemandeAuCache(1);
    $avancerA(13);
    $mesure->noterUneReponseDuCache();

    // Trois clés d'un coup : le magasin répond une fois, puis chaque clé suit.
    $avancerA(20);
    $mesure->noterUneDemandeAuCache(3);
    $avancerA(25);
    $mesure->noterUneReponseDuCache();
    $avancerA(26);
    $mesure->noterUneReponseDuCache();
    $avancerA(27);
    $mesure->noterUneReponseDuCache();

    // Entre deux lectures, le temps ne compte pas.
    $avancerA(100);

    expect($mesure->valeurDeLEnTete())->toEndWith(', cache;dur=10.0;desc="4 lectures"');
});

/*
 * `many()` calcule la valeur par défaut d'une clé absente entre deux réponses :
 * une lecture faite là s'ouvre pendant que le groupe attend encore ses autres
 * clés. Son temps compte une fois, et le calcul autour d'elle, qui n'est pas
 * du cache, pas du tout.
 */
it('compte une fois le temps d’une lecture faite pendant une autre', function (): void {
    [$mesure, $avancerA] = tempsServeurMesureAHorloge();
    $mesure->demarrer();

    $avancerA(0);
    $mesure->noterUneDemandeAuCache(2);
    $avancerA(5);
    $mesure->noterUneReponseDuCache();
    $avancerA(20);
    $mesure->noterUneDemandeAuCache(1);
    $avancerA(24);
    $mesure->noterUneReponseDuCache();
    $avancerA(24.5);
    $mesure->noterUneReponseDuCache();

    expect($mesure->valeurDeLEnTete())->toEndWith(', cache;dur=9.5;desc="3 lectures"');
});

/*
 * Un magasin qui lève (Redis injoignable) ne répond jamais : la demande reste
 * sans réponse. Elle ne compte pas, et n'empêche pas de compter les suivantes.
 */
it('ne laisse pas une lecture restée sans réponse fausser les suivantes', function (): void {
    [$mesure, $avancerA] = tempsServeurMesureAHorloge();
    $mesure->demarrer();

    $avancerA(0);
    $mesure->noterUneDemandeAuCache(1);
    $avancerA(30);
    $mesure->noterUneDemandeAuCache(1);
    $avancerA(32);
    $mesure->noterUneReponseDuCache();
    $avancerA(40);
    $mesure->noterUneDemandeAuCache(2);
    $avancerA(41);
    $mesure->noterUneReponseDuCache();
    // Un groupe vide, demandé pendant que la seconde clé attend, ne demande rien : la réponse qui suit revient au groupe.
    $avancerA(42);
    $mesure->noterUneDemandeAuCache(0);
    $avancerA(43);
    $mesure->noterUneReponseDuCache();
    $avancerA(50);

    expect($mesure->valeurDeLEnTete())->toEndWith(', cache;dur=5.0;desc="3 lectures"');
});

it('ne compte aucune lecture avant d’être démarrée, et repart de zéro à chaque démarrage', function (): void {
    [$mesure, $avancerA] = tempsServeurMesureAHorloge();

    $mesure->noterUneDemandeAuCache(1);
    $avancerA(5);
    $mesure->noterUneReponseDuCache();

    expect($mesure->valeurDeLEnTete())->toEndWith(', cache;dur=0.0;desc="0 lectures"');

    $mesure->demarrer();
    $mesure->noterUneDemandeAuCache(1);
    $avancerA(8);
    $mesure->noterUneReponseDuCache();
    // Une demande restée ouverte au moment d'un nouveau démarrage ne passe pas à la requête suivante.
    $mesure->noterUneDemandeAuCache(1);
    $mesure->demarrer();
    $avancerA(9);
    $mesure->noterUneReponseDuCache();

    expect($mesure->valeurDeLEnTete())->toEndWith(', cache;dur=0.0;desc="0 lectures"');
});

/*
 * Laravel 13.38 type enfin `QueryExecuted::$time` comme `Connection::logQuery()`
 * le reçoit, `float|null` : une requête que la connexion consigne sans l'avoir
 * chronométrée compte, et n'ajoute rien à la durée SQL, au lieu de lever une
 * erreur de type dans l'écouteur.
 */
it('compte une requête SQL consignée sans durée, sans rien ajouter à la durée SQL', function (): void {
    tempsServeurAllumer();
    app('router')->get('/_temps-serveur-sans-duree', function (): Response {
        Auth::guard('web')->setUser(new User());
        DB::connection()->logQuery('select 1', []);

        return response('mesuré');
    });

    $reponse = get('/_temps-serveur-sans-duree')->assertOk();

    expect(tempsServeurRequetesAnnoncees($reponse->baseResponse))->toBe(1)
        ->and((string) $reponse->headers->get('Server-Timing'))->toContain('sql;dur=0.0;desc="1 requete"');
});

it('mesure le panneau pour un administrateur', function (): void {
    tempsServeurAllumer();
    $administrateur = FilamentAdminPanel::admin(['ViewAny:User']);

    $reponse = actingAs($administrateur, 'admin')->get('/backoffice')->assertOk();

    expect(tempsServeurRequetesAnnoncees($reponse->baseResponse))->toBeGreaterThan(0);
});

it('ne mesure jamais un invité', function (): void {
    tempsServeurAllumer();
    tempsServeurRouteDeMesure(app('router'));

    get('/login')->assertOk()->assertHeaderMissing('Server-Timing');
    get('/')->assertRedirect()->assertHeaderMissing('Server-Timing');
    postJson('/api/v1/sets')->assertUnauthorized()->assertHeaderMissing('Server-Timing');
    get('/_temps-serveur/3?cache=2&plusieurs=2')->assertOk()->assertHeaderMissing('Server-Timing');
    get('/backoffice/login')->assertOk()->assertHeaderMissing('Server-Timing');
});

/**
 * La connexion réussie est le piège : l'utilisateur est connecté quand la
 * réponse part. Mesurée, elle donnerait la durée d'une vérification de mot de
 * passe, et la connexion manquée celle d'une adresse inconnue.
 */
it('ne mesure ni la connexion réussie ni la connexion manquée', function (): void {
    tempsServeurAllumer();
    $utilisateur = User::factory()->create();

    post('/login', ['email' => $utilisateur->email, 'password' => 'password'])
        ->assertRedirect()
        ->assertHeaderMissing('Server-Timing');

    expect(Auth::guard('web')->hasUser())->toBeTrue();

    Auth::guard('web')->logout();

    post('/login', ['email' => $utilisateur->email, 'password' => 'pas-le-bon'])->assertHeaderMissing('Server-Timing');
    post('/login', ['email' => 'personne@example.org', 'password' => 'pas-le-bon'])->assertHeaderMissing('Server-Timing');
});

it('ne mesure aucune route d’authentification, même pour un utilisateur connecté', function (): void {
    tempsServeurAllumer();
    $utilisateur = User::factory()->create();

    actingAs($utilisateur)->get('/login')->assertRedirect()->assertHeaderMissing('Server-Timing');
    actingAs($utilisateur)->get('/confirm-password')->assertOk()->assertHeaderMissing('Server-Timing');
    actingAs($utilisateur)->post('/confirm-password', ['password' => 'pas-le-bon'])->assertHeaderMissing('Server-Timing');
    actingAs($utilisateur)->post('/logout')->assertRedirect()->assertHeaderMissing('Server-Timing');
});

/**
 * Les pages d'authentification du panneau ne sont pas sous `guest` : c'est leur
 * espace de noms, `Filament\Auth`, qui les désigne.
 */
it('ne mesure aucune page d’authentification du panneau, même pour un administrateur connecté', function (): void {
    tempsServeurAllumer();
    $administrateur = FilamentAdminPanel::admin(['ViewAny:User']);

    actingAs($administrateur, 'admin')->get('/backoffice/login')
        ->assertRedirect()
        ->assertHeaderMissing('Server-Timing');
});

/**
 * Une route protégée par `guest` est une porte d'authentification, où qu'elle
 * soit déclarée.
 */
it('ne mesure aucune route réservée aux invités', function (): void {
    tempsServeurAllumer();
    Route::get('/_temps-serveur-invites', fn (): string => 'invité')->middleware(['web', 'guest']);

    actingAs(User::factory()->create())->get('/_temps-serveur-invites')
        ->assertRedirect()
        ->assertHeaderMissing('Server-Timing');
});

/**
 * Le formulaire de connexion du panneau passe par une mise à jour Livewire,
 * route qui n'a rien d'une authentification : c'est l'essai d'identifiants
 * lui-même qui doit couper la mesure, y compris pour qui est déjà connecté
 * à l'application.
 */
it('ne mesure aucune requête qui essaie des identifiants, quelle que soit sa route', function (): void {
    tempsServeurAllumer();
    Route::get('/_temps-serveur-essai', function (): string {
        Auth::guard('web')->setUser(new User());
        Auth::guard('admin')->attempt(['email' => 'personne@example.org', 'password' => 'pas-le-bon']);

        return 'refusé';
    });

    get('/_temps-serveur-essai')->assertOk()->assertHeaderMissing('Server-Timing');
});

/**
 * #1418 et #1432 rendent indiscernables « pas à vous » et « n'existe pas » :
 * même statut, même corps, mêmes en-têtes. Un nombre de requêtes SQL rouvrirait
 * l'oracle, la ligne d'autrui coûtant la vérification d'accès que l'absente ne
 * coûte pas.
 */
it('ne dit pas par l’en-tête ce que le 404 tait', function (): void {
    tempsServeurAllumer();
    $utilisateur = User::factory()->create();
    $seanceDAutrui = Workout::factory()->for(User::factory())->create();

    actingAs($utilisateur)->get(route('workouts.show', $seanceDAutrui, absolute: false))
        ->assertNotFound()
        ->assertHeaderMissing('Server-Timing');
    actingAs($utilisateur)->get('/workouts/999999999')
        ->assertNotFound()
        ->assertHeaderMissing('Server-Timing');
    actingAs($utilisateur)->patchJson("/api/v1/workouts/{$seanceDAutrui->id}/line-order")
        ->assertNotFound()
        ->assertHeaderMissing('Server-Timing');
    actingAs($utilisateur)->patchJson('/api/v1/workouts/999999999/line-order')
        ->assertNotFound()
        ->assertHeaderMissing('Server-Timing');
});

/**
 * En tête de pile pour tout mesurer, nonce compris. Seuls passent devant les
 * deux maillons que des paquets ajoutent depuis leur fournisseur, au
 * démarrage : celui d'Inertia, qui ne fait que marquer les rappels différés
 * d'une réponse 409, et celui des notifications de Filament (depuis 5.10.2),
 * qui au retour range dans le cache les notifications du panneau restées en
 * attente et, sur une mise à jour de Livewire, demande au cache s'il en garde.
 * Ce passage au cache échappe à la mesure. La liste reste exacte : tout autre
 * maillon placé devant fait tomber ce test.
 */
it('ouvre la pile globale pour mesurer tout ce qui suit', function (): void {
    $noyau = app(\Illuminate\Contracts\Http\Kernel::class);

    expect($noyau)->toBeInstanceOf(\Illuminate\Foundation\Http\Kernel::class);
    /** @var \Illuminate\Foundation\Http\Kernel $noyau */
    $pileGlobale = $noyau->getGlobalMiddleware();
    $rang = array_search(TempsDuServeur::class, $pileGlobale, true);

    expect($rang)->toBeInt()
        ->and(array_slice($pileGlobale, 0, (int) $rang))->toBe([
            \Inertia\Middleware\EnsureDeferredCallbacksRun::class,
            \Filament\Notifications\Http\Middleware\PublishNotifications::class,
        ]);
});

/**
 * Le cas de production, rejoué : un worker Octane démarré une fois avec la
 * variable allumée, puis trois requêtes clonées depuis lui. Chacune annonce ses
 * requêtes et ses lectures du cache, et aucune de celles d'avant ; la
 * troisième, sans utilisateur, ne porte rien de la connexion des deux
 * premières. L'application de base, d'où chaque requête est clonée, ne tient
 * jamais de mesure, même quand le worker y fait une requête SQL ou lit le
 * cache hors de toute requête HTTP : les écouteurs posés au démarrage ne
 * notent que dans la mesure de la requête en cours, et ne sont posés qu'une
 * fois.
 */
it('ne garde rien d’une requête à l’autre dans un vrai cycle de worker Octane, ni n’empile d’écouteur', function (): void {
    $applicationDuTest = app();
    $client = new FakeClient([
        Request::create('/_temps-serveur/2?connecte=1&cache=1'),
        Request::create('/_temps-serveur/3?connecte=1&cache=2&plusieurs=2'),
        Request::create('/_temps-serveur/1?cache=3'),
    ]);
    $worker = new FakeWorker(new ApplicationFactory(base_path()), $client);
    $precedentes = tempsServeurPoserLaVariable('true');

    try {
        $worker->boot();
        $base = $worker->application();
        tempsServeurRouteDeMesure($base->make('router'));
        $ecouteursAuDemarrage = tempsServeurEcouteursSql($base->make('events'));
        $ecouteursDuCacheAuDemarrage = tempsServeurEcouteurs($base->make('events'), CacheHit::class);

        // Une requête SQL et une lecture du cache du worker entre deux requêtes, sur l'application de base.
        $base->make('db')->select('select 1');
        $base->make('cache')->get('temps-serveur.worker');

        $worker->run();

        $ecouteursApres = tempsServeurEcouteursSql($base->make('events'));
        $ecouteursDuCacheApres = tempsServeurEcouteurs($base->make('events'), CacheHit::class);
        $laBaseTientUneMesure = $base->resolved(MesureDuTempsServeur::class);
    } finally {
        tempsServeurRendreLaVariable($precedentes);
        CurrentApplication::set($applicationDuTest);
    }

    $reponses = [];

    foreach (is_array($client->responses) ? $client->responses : [] as $reponse) {
        if ($reponse instanceof Response) {
            $reponses[] = $reponse;
        }
    }

    expect($client->errors)->toBe([])
        ->and($reponses)->toHaveCount(3)
        ->and(array_map(tempsServeurRequetesAnnoncees(...), $reponses))->toBe([2, 3, null])
        ->and(array_map(tempsServeurLecturesAnnoncees(...), $reponses))->toBe([1, 4, null])
        ->and($ecouteursApres)->toBe($ecouteursAuDemarrage)
        ->and($ecouteursDuCacheApres)->toBe($ecouteursDuCacheAuDemarrage)
        ->and($laBaseTientUneMesure)->toBeFalse();
});
