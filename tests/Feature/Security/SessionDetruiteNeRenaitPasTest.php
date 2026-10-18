<?php

declare(strict_types=1);

use App\Http\Middleware\CleDesCopiesHorsLigne;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\OuvreLaSession;
use App\Models\Admin;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Support\EcheanceDuCookie;
use App\Support\Sessions\GestionnaireDesSessions;
use App\Support\Sessions\PierresTombales;
use App\Support\Sessions\SessionChiffreeGardee;
use App\Support\Sessions\SessionGardee;
use Filament\Facades\Filament;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Pulse\Recorders\CacheInteractions;
use Laravel\Telescope\Watchers\CacheWatcher;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Appareil;
use Tests\Support\SessionsEspionnees;
use Tests\Support\UneAutreRequete;

/*
 * Une session détruite pendant une requête ne renaît pas de l'enregistrement
 * de fin de celle-ci, et la réponse de cette requête ne pose aucun cookie
 * (#2088). Laravel enregistre la session à la fin de chaque requête, telle
 * qu'il l'a lue en entrant, et en renvoie le cookie : une requête
 * d'arrière-plan (props différées, écritures de la file hors ligne,
 * abonnement push) qui l'avait lue avant une déconnexion la faisait renaître
 * dans un magasin de cache, connexion comprise, et rendait au navigateur
 * l'ancien cookie, par-dessus celui du compte connecté entre-temps.
 *
 * Chaque cas de destruction fait détruire la session par une autre requête
 * pendant celle du test (`UneAutreRequete`) : juste après que la garde y a lu
 * le compte (évènement `Authenticated`), depuis la route, ou juste avant
 * l'écriture de fin. Ils se jouent sous trois pilotes : le magasin en mémoire
 * des tests, un cache (le gestionnaire du pilote Redis de production, dont
 * l'écriture n'a pas de condition) et la base, dont la ligne effacée ne
 * renaît pas mais dont le cookie revenait. Sans le correctif, ces cas
 * échouent : session renée ou cookies reposés, et le cookie de la session
 * finie rouvert sous le même identifiant. Les autres tiennent ce que le
 * correctif ne doit pas changer (la requête ordinaire, la connexion, « se
 * souvenir de moi ») et passent avec ou sans lui, ou la façon dont il est
 * branché : classes, pierre tombale, ordre de la destruction, lectures du
 * cache, outils qui les notent.
 */

/**
 * Les pilotes de session sous lesquels chaque cas se joue.
 *
 * @return array<string, array{string}>
 */
function detruitePilotes(): array
{
    return [
        'en mémoire' => ['array'],
        'dans un cache, comme Redis en production' => ['cache'],
        'en base' => ['database'],
    ];
}

/**
 * Pose le pilote des sessions pour tout le test. Le cache prend le
 * gestionnaire du pilote Redis (`CacheBasedSessionHandler`), sur un cache en
 * mémoire.
 */
function detruitePoserLePilote(string $pilote): void
{
    config(match ($pilote) {
        'cache' => ['session.driver' => 'memcached', 'session.store' => 'array'],
        'database' => ['session.driver' => 'database'],
        default => ['session.driver' => 'array'],
    });

    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

/**
 * Branche l'espion comme magasin des sessions, pour tout le test.
 */
function detruiteEspionner(): SessionsEspionnees
{
    $espion = new SessionsEspionnees(config()->integer('session.lifetime'));

    app('session')->extend('espion-des-sessions-detruites', fn (): SessionsEspionnees => $espion);
    config(['session.driver' => 'espion-des-sessions-detruites']);
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    return $espion;
}

/**
 * Un appareil connecté au compte par le formulaire de connexion.
 */
function detruiteAppareilConnecte(User $compte, bool $seSouvenirDeMoi = false): Appareil
{
    $appareil = new Appareil();

    $appareil->envoyer('POST', '/login', ['email' => $compte->email, 'password' => 'password', 'remember' => $seSouvenirDeMoi])
        ->assertRedirect(route('dashboard'));

    return $appareil;
}

/**
 * L'identifiant de la session que porte le cookie de l'appareil.
 */
function detruiteIdentifiant(Appareil $appareil): string
{
    return (string) $appareil->cookie(config()->string('session.cookie'));
}

/**
 * Les cookies auxquels la réponse donne une valeur, par nom ; un effacement
 * n'en est pas un.
 *
 * @param  TestResponse<Response>  $reponse
 * @return list<string>
 */
function detruiteCookiesPoses(TestResponse $reponse): array
{
    $poses = [];

    foreach ($reponse->baseResponse->headers->getCookies() as $cookie) {
        if (! EcheanceDuCookie::estPassee($cookie, Carbon::now()) && $cookie->getValue() !== '') {
            $poses[] = $cookie->getName();
        }
    }

    sort($poses);

    return $poses;
}

/**
 * Les cookies que pose la réponse d'une page faite à un compte, quand rien
 * n'a détruit sa session.
 *
 * @return list<string>
 */
function detruiteCookiesDUnePage(): array
{
    return ['XSRF-TOKEN', CleDesCopiesHorsLigne::nom(), config()->string('session.cookie')];
}

/**
 * Les en-têtes d'une visite Inertia, comme la page en envoie en arrière-plan
 * (props différées) : sa réponse est la page seule, sans le document.
 *
 * @return array<string, string>
 */
function detruiteEntetesInertia(): array
{
    return [
        'Accept' => 'text/html, application/xhtml+xml',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
    ];
}

/**
 * La garde de session de ce nom.
 */
function detruiteGarde(string $nom): SessionGuard
{
    $garde = Auth::guard($nom);

    if (! $garde instanceof SessionGuard) {
        throw new LogicException("La garde {$nom} tient sa connexion en session.");
    }

    return $garde;
}

/**
 * Connecte un appareil, puis le déconnecte, et rend l'identifiant de la
 * session que la déconnexion détruit (`invalidate()`).
 */
function detruiteParUneDeconnexion(): string
{
    $appareil = detruiteAppareilConnecte(User::factory()->create());
    $connectee = detruiteIdentifiant($appareil);

    $appareil->envoyer('POST', '/logout')->assertRedirect('/');

    return $connectee;
}

/**
 * Ouvre la page de connexion sur un appareil, puis s'y connecte, et rend
 * l'identifiant de la session d'invité que la connexion détruit
 * (`SessionGuard::updateSession()`, `migrate(true)`).
 */
function detruiteParUneConnexion(): string
{
    $compte = User::factory()->create();
    $appareil = new Appareil();
    $appareil->envoyer('GET', '/login', [], detruiteEntetesInertia())->assertOk();
    $invitee = detruiteIdentifiant($appareil);

    $appareil->envoyer('POST', '/login', ['email' => $compte->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    return $invitee;
}

/**
 * Le nombre de pierres tombales que lisent les requêtes de cette fonction, à
 * leurs évènements de lecture du cache (ceux que Pulse et Telescope notent).
 * Chaque appel compte dans son propre compteur : l'écouteur d'un appel
 * précédent n'y ajoute rien.
 *
 * @param  Closure(): mixed  $requetes
 */
function detruiteLecturesDesPierresTombales(Closure $requetes): int
{
    $lectures = 0;

    Event::listen([CacheHit::class, CacheMissed::class], static function (CacheHit|CacheMissed $lecture) use (&$lectures): void {
        if (str_starts_with($lecture->key, PierresTombales::PREFIXE)) {
            $lectures++;
        }
    });

    $requetes();

    return $lectures;
}

it('ne fait pas renaître une session qu’une autre requête détruit pendant une page, et la réponse n’en pose aucun cookie', function (string $pilote, bool $parUneConnexion): void {
    detruitePoserLePilote($pilote);
    $appareil = detruiteAppareilConnecte(User::factory()->create());
    $ancienne = detruiteIdentifiant($appareil);

    expect(UneAutreRequete::litLaSession($ancienne))->not->toBe('');

    UneAutreRequete::detruiraLaSessionPendantLaProchaine($ancienne, $parUneConnexion);

    $reponse = $appareil->envoyer('GET', '/dashboard', [], detruiteEntetesInertia());

    $reponse->assertOk();
    expect(UneAutreRequete::litLaSession($ancienne))->toBe('')
        ->and(detruiteCookiesPoses($reponse))->toBe([]);

    $appareil->envoyer('GET', '/dashboard', [], detruiteEntetesInertia())->assertRedirect(route('login'));
    expect(detruiteIdentifiant($appareil))->not->toBe($ancienne);
})->with(detruitePilotes())->with([
    'par une déconnexion' => [false],
    'par une connexion' => [true],
]);

it('ne fait pas renaître une session détruite pendant une écriture de l’API, et la réponse n’en pose aucun cookie', function (string $pilote): void {
    detruitePoserLePilote($pilote);
    $compte = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => null]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id]);
    $appareil = detruiteAppareilConnecte($compte);
    $ancienne = detruiteIdentifiant($appareil);

    UneAutreRequete::detruiraLaSessionPendantLaProchaine($ancienne);

    $reponse = $appareil->envoyer('POST', route('api.v1.sets.store', absolute: false), [
        'workout_line_id' => $ligne->id,
        'weight' => 40,
        'reps' => 8,
    ], [
        'Accept' => 'application/json',
        'Referer' => url('/workouts/'.$seance->id),
    ]);

    $reponse->assertCreated();
    expect(UneAutreRequete::litLaSession($ancienne))->toBe('')
        ->and(detruiteCookiesPoses($reponse))->toBe([]);
})->with(detruitePilotes());

it('ne fait pas renaître une session détruite pendant une page du panneau, et la réponse n’en pose aucun cookie', function (string $pilote): void {
    detruitePoserLePilote($pilote);
    $administrateur = Admin::factory()->create();
    $administrateur->assignRole(Role::findOrCreate('super_admin', 'admin'));
    $appareil = detruiteAppareilConnecte(User::factory()->create());
    $ancienne = detruiteIdentifiant($appareil);
    $gardeDuPanneau = detruiteGarde('admin');

    UneAutreRequete::ajouteALaSession($ancienne, [
        $gardeDuPanneau->getName() => $administrateur->getAuthIdentifier(),
        'password_hash_admin' => $gardeDuPanneau->hashPasswordForCookie($administrateur->getAuthPassword()),
    ]);
    $appareil->envoyer('GET', '/backoffice')->assertOk();

    UneAutreRequete::detruiraLaSessionPendantLaProchaine($ancienne);

    $reponse = $appareil->envoyer('GET', '/backoffice');

    $reponse->assertOk();
    expect(UneAutreRequete::litLaSession($ancienne))->toBe('')
        ->and(detruiteCookiesPoses($reponse))->toBe([]);

    $appareil->envoyer('GET', '/backoffice')->assertRedirect('/backoffice/login');
})->with(detruitePilotes());

/*
 * L'enregistrement écrit, puis lit la pierre tombale : une destruction qui
 * tombe entre la dernière vérification qu'une requête pourrait faire avant
 * d'écrire et l'écriture elle-même est rattrapée. Une vérification faite
 * avant l'écriture seulement laisserait la session renaître ici.
 */
it('retire l’écriture de fin quand la session est détruite juste avant elle', function (): void {
    $espion = detruiteEspionner();
    $appareil = detruiteAppareilConnecte(User::factory()->create());
    $ancienne = detruiteIdentifiant($appareil);
    $detruite = false;

    $espion->avantChaqueEcriture = static function (string $identifiant) use ($ancienne, &$detruite): void {
        if ($identifiant === $ancienne && ! $detruite) {
            $detruite = true;
            UneAutreRequete::detruitLaSession($ancienne);
        }
    };

    $reponse = $appareil->envoyer('GET', '/dashboard', [], detruiteEntetesInertia());

    $reponse->assertOk();
    expect($detruite)->toBeTrue()
        ->and($espion->ecritures)->toContain($ancienne)
        ->and(UneAutreRequete::litLaSession($ancienne))->toBe('')
        ->and(detruiteCookiesPoses($reponse))->toBe([]);
});

/*
 * L'autre moitié de cet ordre : la destruction pose la pierre tombale, puis
 * efface la session, si bien que le gestionnaire du pilote la trouve déjà
 * posée quand il efface. Posée après, une requête qui écrirait la session
 * entre l'effacement et la pierre, puis lirait la pierre pas encore posée, la
 * ferait renaître et en reposerait les cookies.
 */
it('pose la pierre tombale avant d’effacer la session détruite', function (bool $parUneConnexion): void {
    $espion = detruiteEspionner();
    $pierreDejaPosee = [];
    $espion->avantChaqueDestruction = static function (string $identifiant) use (&$pierreDejaPosee): void {
        $pierreDejaPosee[$identifiant] = Cache::store()->has(PierresTombales::PREFIXE.$identifiant);
    };

    $detruite = $parUneConnexion ? detruiteParUneConnexion() : detruiteParUneDeconnexion();

    expect($pierreDejaPosee)->toHaveKey($detruite)
        ->and($pierreDejaPosee)->each->toBeTrue();
})->with([
    'par une déconnexion' => [false],
    'par une connexion' => [true],
]);

it('ne rend pas son cookie à une page d’invité qui croise une connexion', function (string $pilote): void {
    detruitePoserLePilote($pilote);
    Route::middleware('web')->get('/_session-detruite-pendant-la-page', static function (): string {
        UneAutreRequete::detruitLaSession(session()->getId(), parUneConnexion: true);

        return 'ok';
    });
    $invite = new Appareil();
    $invite->envoyer('GET', '/login', [], detruiteEntetesInertia())->assertOk();
    $sessionDInvite = detruiteIdentifiant($invite);

    $reponse = $invite->envoyer('GET', '/_session-detruite-pendant-la-page');

    $reponse->assertOk();
    expect(UneAutreRequete::litLaSession($sessionDInvite))->toBe('')
        ->and(detruiteCookiesPoses($reponse))->toBe([]);
})->with(detruitePilotes());

it('ne fait pas renaître une session chiffrée détruite pendant une page', function (): void {
    config(['session.encrypt' => true]);
    detruitePoserLePilote('cache');
    $appareil = detruiteAppareilConnecte(User::factory()->create());
    $ancienne = detruiteIdentifiant($appareil);

    expect(app('session.store'))->toBeInstanceOf(SessionChiffreeGardee::class);

    UneAutreRequete::detruiraLaSessionPendantLaProchaine($ancienne);

    $reponse = $appareil->envoyer('GET', '/dashboard', [], detruiteEntetesInertia());

    $reponse->assertOk();
    expect(UneAutreRequete::litLaSession($ancienne))->toBe('')
        ->and(detruiteCookiesPoses($reponse))->toBe([]);
});

/*
 * Non-régression : rien ne change pour une requête qui n'a croisé aucune
 * destruction. Elle enregistre sa session, en repose le cookie, et la
 * prolonge : cent minutes après la dernière page, deux cents après la
 * connexion, la session de cent vingt minutes tient encore.
 */
it('enregistre et prolonge comme avant la session d’une requête qui n’a croisé aucune destruction', function (string $pilote): void {
    Carbon::setTestNow(Carbon::parse('2025-03-11 08:15:00'));
    detruitePoserLePilote($pilote);
    $appareil = detruiteAppareilConnecte(User::factory()->create());
    $identifiant = detruiteIdentifiant($appareil);

    Carbon::setTestNow(Carbon::now()->addMinutes(100));
    $reponse = $appareil->envoyer('GET', '/dashboard', [], detruiteEntetesInertia());

    $reponse->assertOk();
    expect(detruiteCookiesPoses($reponse))->toEqualCanonicalizing(detruiteCookiesDUnePage())
        ->and(detruiteIdentifiant($appareil))->toBe($identifiant);

    Carbon::setTestNow(Carbon::now()->addMinutes(100));
    $appareil->envoyer('GET', '/dashboard', [], detruiteEntetesInertia())->assertOk();
    expect(detruiteIdentifiant($appareil))->toBe($identifiant);
})->with(detruitePilotes());

it('pose la session neuve de la déconnexion, et le cookie de la session finie ouvre une session d’invité neuve où l’on se reconnecte', function (string $pilote): void {
    detruitePoserLePilote($pilote);
    $compte = User::factory()->create();
    $appareil = detruiteAppareilConnecte($compte);
    $copie = $appareil->copie();
    $ancienne = detruiteIdentifiant($appareil);

    $deconnexion = $appareil->envoyer('POST', '/logout');

    $deconnexion->assertRedirect('/');
    expect(detruiteCookiesPoses($deconnexion))->toContain(config()->string('session.cookie'))
        ->and(detruiteIdentifiant($appareil))->not->toBe($ancienne)
        ->and(UneAutreRequete::litLaSession($ancienne))->toBe('');

    $copie->envoyer('GET', '/dashboard', [], detruiteEntetesInertia())->assertRedirect(route('login'));
    expect(detruiteIdentifiant($copie))->not->toBe($ancienne);

    $copie->envoyer('POST', '/login', ['email' => $compte->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));
    $copie->envoyer('GET', '/dashboard', [], detruiteEntetesInertia())->assertOk();
})->with(detruitePilotes());

it('rouvre par le cookie « se souvenir de moi » une session neuve, et la pose', function (string $pilote): void {
    detruitePoserLePilote($pilote);
    $appareil = detruiteAppareilConnecte(User::factory()->create(), seSouvenirDeMoi: true);
    $ancienne = detruiteIdentifiant($appareil);
    $appareil->oublierLeCookie(config()->string('session.cookie'));

    $reponse = $appareil->envoyer('GET', '/dashboard', [], detruiteEntetesInertia());

    $reponse->assertOk();
    expect(detruiteCookiesPoses($reponse))->toContain(config()->string('session.cookie'))
        ->and(detruiteIdentifiant($appareil))->not->toBe('')
        ->and(detruiteIdentifiant($appareil))->not->toBe($ancienne);
    $appareil->envoyer('GET', '/dashboard', [], detruiteEntetesInertia())->assertOk();
})->with(detruitePilotes());

/*
 * Le gestionnaire de chaque pilote reste celui de Laravel : Octane redonne à
 * celui de la base l'application de chaque requête
 * (`GiveNewApplicationInstanceToDatabaseSessionHandler`, qui le reconnaît à
 * sa classe), et le magasin reconnaît aux leurs le pilote `cookie` et celui
 * de la base (`ExistenceAwareInterface`). Seul le magasin change.
 */
it('bâtit chaque magasin de sessions en SessionGardee, sur le gestionnaire de Laravel', function (string $pilote, string $gestionnaire): void {
    detruitePoserLePilote($pilote);

    $magasin = app('session.store');

    expect(app('session'))->toBeInstanceOf(GestionnaireDesSessions::class)
        ->and($magasin)->toBeInstanceOf(SessionGardee::class)
        ->and($magasin->getHandler()::class)->toBe($gestionnaire);
})->with([
    'en mémoire' => ['array', ArraySessionHandler::class],
    'dans un cache' => ['cache', CacheBasedSessionHandler::class],
    'en base' => ['database', DatabaseSessionHandler::class],
]);

/*
 * La pierre tombale vit avec les sessions quand elles vivent dans un cache
 * (même magasin, même connexion, même préfixe, comme Redis en production), et
 * dans le cache de l'application sinon ; elle dure ce que dure une session.
 */
it('pose la pierre tombale avec les sessions, pour la durée d’une session', function (string $pilote): void {
    Carbon::setTestNow(Carbon::parse('2025-03-11 08:15:00'));
    detruitePoserLePilote($pilote);
    $appareil = detruiteAppareilConnecte(User::factory()->create());
    $ancienne = detruiteIdentifiant($appareil);
    $gestionnaire = app('session.store')->getHandler();
    $cacheDesSessions = $gestionnaire instanceof CacheBasedSessionHandler ? $gestionnaire->getCache() : Cache::store();
    $cle = PierresTombales::PREFIXE.$ancienne;

    $appareil->envoyer('POST', '/logout')->assertRedirect('/');

    expect($cacheDesSessions->has($cle))->toBeTrue();

    if ($gestionnaire instanceof CacheBasedSessionHandler) {
        expect(Cache::store()->has($cle))->toBeFalse();
    }

    Carbon::setTestNow(Carbon::now()->addMinutes(config()->integer('session.lifetime'))->subSecond());
    expect($cacheDesSessions->has($cle))->toBeTrue();

    Carbon::setTestNow(Carbon::now()->addSeconds(2));
    expect($cacheDesSessions->has($cle))->toBeFalse();
})->with(detruitePilotes());

/*
 * `OuvreLaSession` ouvre la session partout où une requête en ouvre une : le
 * groupe `web` la nomme à la place de `StartSession`, le panneau dans sa
 * pile, et la pile que Sanctum ouvre pour une requête de la page demande
 * `StartSession` au conteneur, qui la rend. Une liaison simple : sous Octane,
 * chaque requête prend la sienne.
 */
it('ouvre la session par OuvreLaSession dans le groupe web, la pile de Sanctum et le panneau', function (): void {
    expect(app(StartSession::class))->toBeInstanceOf(OuvreLaSession::class)
        ->and(app(StartSession::class))->not->toBe(app(StartSession::class))
        ->and(app('router')->getMiddlewareGroups()['web'])->toContain(OuvreLaSession::class)
        ->and(app('router')->getMiddlewareGroups()['web'])->not->toContain(StartSession::class)
        ->and(Filament::getPanel('admin')->getMiddleware())->toContain(OuvreLaSession::class)
        ->and(Filament::getPanel('admin')->getMiddleware())->not->toContain(StartSession::class);
});

/*
 * Seul un identifiant reçu du cookie peut avoir été détruit par une autre
 * requête : une page qui porte le cookie de sa session lit deux pierres
 * tombales (à l'ouverture, après l'écriture), le cookie d'une session finie
 * une seule (celle qui la fait remplacer par une neuve), et une première
 * visite aucune, puisque le magasin tire son identifiant, que personne
 * d'autre ne connaît.
 */
it('ne lit de pierre tombale que pour un identifiant reçu du cookie', function (string $pilote): void {
    detruitePoserLePilote($pilote);
    $appareil = detruiteAppareilConnecte(User::factory()->create());
    $copie = $appareil->copie();

    expect(detruiteLecturesDesPierresTombales(static fn (): TestResponse => new Appareil()->envoyer('GET', '/login', [], detruiteEntetesInertia())->assertOk()))->toBe(0)
        ->and(detruiteLecturesDesPierresTombales(static fn (): TestResponse => $appareil->envoyer('GET', '/dashboard', [], detruiteEntetesInertia())->assertOk()))->toBe(2);

    $appareil->envoyer('POST', '/logout')->assertRedirect('/');

    expect(detruiteLecturesDesPierresTombales(static fn (): TestResponse => $copie->envoyer('GET', '/dashboard', [], detruiteEntetesInertia())->assertRedirect(route('login'))))->toBe(1);
})->with(detruitePilotes());

/*
 * Pulse et Telescope notent chaque lecture du cache, sous sa clé. Celle d'une
 * pierre tombale change avec chaque session, comme l'identifiant de session
 * que Pulse ignore déjà : ils l'ignorent aussi, et rien d'autre.
 */
it('fait ignorer les pierres tombales par Pulse et Telescope, et rien d’autre', function (): void {
    $cle = PierresTombales::PREFIXE.app('session.store')->getId();
    $pulse = app(CacheInteractions::class);
    $telescope = new CacheWatcher(config()->array('telescope.watchers.'.CacheWatcher::class));
    $pulseIgnore = static fn (string $cle): mixed => new ReflectionMethod($pulse, 'shouldIgnore')->invoke($pulse, $cle);
    $telescopeIgnore = static fn (string $cle): mixed => new ReflectionMethod($telescope, 'shouldIgnore')->invoke($telescope, new CacheMissed('array', $cle));

    expect($pulseIgnore($cle))->toBeTrue()
        ->and($telescopeIgnore($cle))->toBeTrue()
        ->and($pulseIgnore('achievements_all'))->toBeFalse()
        ->and($telescopeIgnore('achievements_all'))->toBeFalse();
});
