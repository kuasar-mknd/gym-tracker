<?php

declare(strict_types=1);

use App\Http\Middleware\CleDesCopiesHorsLigne;
use App\Models\User;
use App\Support\CleDesCopiesEnSession;
use App\Support\EcheanceDuCookie;
use App\Support\Sessions\SessionChiffreeGardee;
use App\Support\Sessions\SessionGardee;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Appareil;
use Tests\Support\UneAutreRequete;

/*
 * Les sessions s'écrivent chiffrées dans Redis (#2353). Elles portent la clé
 * AES des copies hors ligne (`CleDesCopiesEnSession`) : lisible, elle
 * ouvrirait les copies de toute personne qui lit Redis (réseau interne,
 * mot de passe). `session.encrypt` est vrai par défaut.
 *
 * La bascule est le cas qui compte : les sessions déjà écrites en clair ne se
 * déchiffrent pas, et Laravel les lit comme vides (`EncryptedStore`). Leur
 * titulaire devient un invité, une fois : une page du compte le renvoie vers
 * la connexion, sa session s'écrit chiffrée sous le même identifiant, et un
 * appareil qui se souvient du compte y rentre sans rien faire. La reprise en
 * arrière rend la même chose dans l'autre sens. Rien ne répond 500.
 *
 * Le pilote est celui de la production, un magasin de cache (le gestionnaire
 * du pilote Redis), sur un cache en mémoire.
 */

/**
 * Pose le pilote en cache et, ou non, le chiffrement, pour tout le test ; le
 * magasin des sessions se rebâtit, comme un conteneur redémarré sur une autre
 * valeur de `SESSION_ENCRYPT`.
 */
function chiffreesPoser(bool $chiffrees): void
{
    // Un seul gestionnaire pour tout le test : `forgetDrivers()` rebâtit sinon
    // le magasin sur un clone vide, et la bascule ne verrait aucune session.
    if (! app()->bound('chiffrees.gestionnaire')) {
        app()->instance('chiffrees.gestionnaire', new ArraySessionHandler(config()->integer('session.lifetime')));
    }

    $gestionnaire = app('chiffrees.gestionnaire');
    app('session')->extend('chiffrees-partage', fn (): mixed => $gestionnaire);
    config(['session.driver' => 'chiffrees-partage', 'session.encrypt' => $chiffrees]);

    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

/**
 * Un appareil connecté au compte, avec ou sans « se souvenir de moi ».
 */
function chiffreesAppareilConnecte(User $compte, bool $seSouvenirDeMoi = false): Appareil
{
    $appareil = new Appareil();

    $appareil->envoyer('POST', '/login', ['email' => $compte->email, 'password' => 'password', 'remember' => $seSouvenirDeMoi])
        ->assertRedirect(route('dashboard'));

    return $appareil;
}

function chiffreesIdentifiant(Appareil $appareil): string
{
    return (string) $appareil->cookie(config()->string('session.cookie'));
}

it('est vrai par défaut, et la composition de production le transmet', function (): void {
    expect((string) file_get_contents(base_path('docker-compose.prod.yml')))->toContain('SESSION_ENCRYPT: ${SESSION_ENCRYPT:-true}')
        ->and((string) file_get_contents(base_path('.env.example')))->toContain("\nSESSION_ENCRYPT=true\n");
});

/**
 * Ce que `config/session.php` rend de `encrypt` pour une valeur de `SESSION_ENCRYPT`,
 * `null` pour une variable absente.
 */
function chiffreesConfigurationPour(?string $valeur): mixed
{
    $avantServeur = $_SERVER['SESSION_ENCRYPT'] ?? null;
    $avantEnvironnement = $_ENV['SESSION_ENCRYPT'] ?? null;

    unset($_SERVER['SESSION_ENCRYPT'], $_ENV['SESSION_ENCRYPT']);

    if ($valeur !== null) {
        $_SERVER['SESSION_ENCRYPT'] = $_ENV['SESSION_ENCRYPT'] = $valeur;
    }

    try {
        /** @var array<string, mixed> $configuration */
        $configuration = require base_path('config/session.php');

        return $configuration['encrypt'];
    } finally {
        unset($_SERVER['SESSION_ENCRYPT'], $_ENV['SESSION_ENCRYPT']);

        if ($avantServeur !== null) {
            $_SERVER['SESSION_ENCRYPT'] = $avantServeur;
        }

        if ($avantEnvironnement !== null) {
            $_ENV['SESSION_ENCRYPT'] = $avantEnvironnement;
        }
    }
}

it('ne s’éteint que sur un refus explicite : vide, absent ou faute de frappe laissent le chiffrement', function (?string $valeur, bool $attendu): void {
    expect(chiffreesConfigurationPour($valeur))->toBe($attendu);
})->with([
    'absente' => [null, true],
    'vide' => ['', true],
    'des espaces' => ['  ', true],
    'true' => ['true', true],
    '1' => ['1', true],
    'une faute de frappe' => ['flase', true],
    'un mot sans rapport' => ['peut-être', true],
    'false' => ['false', false],
    'FALSE' => ['FALSE', false],
    '0' => ['0', false],
    'off' => ['off', false],
    'no' => ['no', false],
]);

it('n’a aucun réglage de la suite ni de la pile qui éteigne le chiffrement', function (): void {
    $phpunit = simplexml_load_file(base_path('phpunit.xml'));
    expect($phpunit)->not->toBeFalse();
    assert($phpunit instanceof SimpleXMLElement);

    $valeurs = [];

    foreach ($phpunit->xpath('//php/env[@name="SESSION_ENCRYPT"]') ?? [] as $variable) {
        $valeurs[] = strtolower(trim((string) $variable['value']));
    }

    expect($valeurs)->not->toBe([])
        ->and(array_intersect($valeurs, ['false', '0', 'off', 'no', '']))->toBe([])
        ->and((string) file_get_contents(base_path('.env.example')))->not->toMatch('/^SESSION_ENCRYPT=(?:false|0|off|no)?\s*$/mi');
});

it('écrit la session chiffrée : ni la clé des copies ni le compte ne se lisent dans le magasin', function (): void {
    chiffreesPoser(true);
    $compte = User::factory()->create();
    $appareil = chiffreesAppareilConnecte($compte);
    $appareil->envoyer('GET', '/dashboard')->assertOk();

    expect(app('session.store'))->toBeInstanceOf(SessionChiffreeGardee::class);

    $brut = UneAutreRequete::litLaSession(chiffreesIdentifiant($appareil));

    expect($brut)->not->toBe('')
        ->and($brut)->not->toContain(CleDesCopiesEnSession::ENTREE)
        ->and($brut)->not->toContain('_token')
        ->and($brut)->not->toContain('login_web');
});

it('contre-épreuve : sans chiffrement, la clé des copies se lit dans le magasin', function (): void {
    chiffreesPoser(false);
    $appareil = chiffreesAppareilConnecte(User::factory()->create());
    $appareil->envoyer('GET', '/dashboard')->assertOk();

    expect(app('session.store'))->toBeInstanceOf(SessionGardee::class);

    $brut = UneAutreRequete::litLaSession(chiffreesIdentifiant($appareil));

    expect($brut)->toContain(CleDesCopiesEnSession::ENTREE)
        ->and($brut)->toContain('login_web');
});

it('ferme une fois la session écrite en clair à la bascule, sans erreur, et en écrit une chiffrée', function (): void {
    chiffreesPoser(false);
    $compte = User::factory()->create();
    $appareil = chiffreesAppareilConnecte($compte);
    $appareil->envoyer('GET', '/dashboard')->assertOk();
    $identifiant = chiffreesIdentifiant($appareil);
    $cleDesCopies = $appareil->cookie(CleDesCopiesHorsLigne::nom());

    expect($cleDesCopies)->not->toBeNull()
        ->and(UneAutreRequete::litLaSession($identifiant))->toContain('login_web');

    chiffreesPoser(true);

    expect(UneAutreRequete::litLaSession($identifiant))->toContain('login_web');

    $reponse = $appareil->envoyer('GET', '/dashboard');

    expect($reponse->getStatusCode())->toBe(302)
        ->and($reponse->headers->get('Location'))->toBe(route('login'))
        ->and($appareil->cookie(CleDesCopiesHorsLigne::nom()))->toBeNull();

    $ecrite = UneAutreRequete::litLaSession(chiffreesIdentifiant($appareil));

    expect($ecrite)->not->toBe('')
        ->and($ecrite)->not->toContain('login_web')
        ->and($ecrite)->not->toContain('_token');

    // Une seule fois : la connexion suivante tient, et la page d'après aussi.
    $appareil->envoyer('GET', route('login', absolute: false))->assertOk();
    $appareil->envoyer('POST', '/login', ['email' => $compte->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
    $appareil->envoyer('GET', '/dashboard')->assertOk();
    $appareil->envoyer('GET', '/dashboard')->assertOk();

    expect($appareil->cookie(CleDesCopiesHorsLigne::nom()))->not->toBeNull()
        ->and($appareil->cookie(CleDesCopiesHorsLigne::nom()))->not->toBe($cleDesCopies);
});

it('rend à la bascule un refus d’invité, jamais 500, pour l’API comme pour une page', function (): void {
    chiffreesPoser(false);
    $appareil = chiffreesAppareilConnecte(User::factory()->create());
    $appareil->envoyer('GET', '/dashboard')->assertOk();

    chiffreesPoser(true);

    $page = $appareil->envoyer('GET', '/dashboard', [], ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest']);
    $api = $appareil->envoyer('POST', '/api/v1/workouts', [], ['Accept' => 'application/json']);

    expect($page->getStatusCode())->toBeIn([302, 409])
        ->and($api->getStatusCode())->toBeIn([401, 419]);
});

it('garde connecté, par son cookie de rappel, l’appareil qui se souvient du compte, avec une clé des copies neuve', function (): void {
    chiffreesPoser(false);
    $compte = User::factory()->create();
    $appareil = chiffreesAppareilConnecte($compte, seSouvenirDeMoi: true);
    $appareil->envoyer('GET', '/dashboard')->assertOk();
    $cleDAvant = $appareil->cookie(CleDesCopiesHorsLigne::nom());

    expect($cleDAvant)->not->toBeNull();

    chiffreesPoser(true);

    $appareil->envoyer('GET', '/dashboard')->assertOk();

    expect($appareil->cookie(CleDesCopiesHorsLigne::nom()))->not->toBeNull()
        ->and($appareil->cookie(CleDesCopiesHorsLigne::nom()))->not->toBe($cleDAvant)
        ->and(UneAutreRequete::litLaSession(chiffreesIdentifiant($appareil)))->not->toContain('login_web');
});

it('ne referme pas une session que la bascule a déjà rouverte : un appareil qui se souvient ne se déconnecte pas d’une page à l’autre', function (): void {
    chiffreesPoser(false);
    $appareil = chiffreesAppareilConnecte(User::factory()->create(), seSouvenirDeMoi: true);
    $appareil->envoyer('GET', '/dashboard')->assertOk();

    chiffreesPoser(true);

    $appareil->envoyer('GET', '/dashboard')->assertOk();
    $cle = $appareil->cookie(CleDesCopiesHorsLigne::nom());
    $appareil->envoyer('GET', '/dashboard')->assertOk();
    $appareil->envoyer('GET', '/dashboard')->assertOk();

    expect($appareil->cookie(CleDesCopiesHorsLigne::nom()))->toBe($cle);
});

it('reprend en arrière de même : une session chiffrée se lit comme vide quand le chiffrement est coupé', function (): void {
    chiffreesPoser(true);
    $appareil = chiffreesAppareilConnecte(User::factory()->create());
    $appareil->envoyer('GET', '/dashboard')->assertOk();

    chiffreesPoser(false);

    $reponse = $appareil->envoyer('GET', '/dashboard');

    expect($reponse->getStatusCode())->toBe(302)
        ->and($reponse->headers->get('Location'))->toBe(route('login'))
        ->and($appareil->cookie(CleDesCopiesHorsLigne::nom()))->toBeNull()
        ->and(UneAutreRequete::litLaSession(chiffreesIdentifiant($appareil)))->not->toBe('');
});

it('lit la session illisible comme absente du magasin : un préchargement ne la tient pas pour une session retrouvée', function (): void {
    chiffreesPoser(false);
    $appareil = chiffreesAppareilConnecte(User::factory()->create());
    $identifiant = chiffreesIdentifiant($appareil);

    chiffreesPoser(true);

    $magasin = app('session.store');
    $magasin->setId($identifiant);
    $magasin->start();

    expect($magasin)->toBeInstanceOf(SessionChiffreeGardee::class)
        ->and($magasin->aEteLueAuMagasin())->toBeFalse()
        ->and($magasin->has(CleDesCopiesEnSession::ENTREE))->toBeFalse();
});

/**
 * Ce que le navigateur retient d'une réponse : son statut, où elle renvoie,
 * et les cookies qu'elle pose et qu'elle efface, par nom.
 *
 * @param  TestResponse<Response>  $reponse
 * @return array{statut: int, renvoi: string|null, poses: list<string>, effaces: list<string>}
 */
function chiffreesCeQueLeNavigateurRetient(TestResponse $reponse): array
{
    $poses = [];
    $effaces = [];

    foreach ($reponse->baseResponse->headers->getCookies() as $cookie) {
        if (EcheanceDuCookie::estPassee($cookie, Carbon::now()) || (string) $cookie->getValue() === '') {
            $effaces[] = $cookie->getName();
        } else {
            $poses[] = $cookie->getName();
        }
    }

    sort($poses);
    sort($effaces);

    return ['statut' => $reponse->getStatusCode(), 'renvoi' => $reponse->headers->get('Location'), 'poses' => $poses, 'effaces' => $effaces];
}

/**
 * Un appareil connecté avant la bascule : sa session est écrite en clair,
 * puis le chiffrement est posé.
 */
function chiffreesAppareilApresLaBascule(): Appareil
{
    chiffreesPoser(false);
    $appareil = chiffreesAppareilConnecte(User::factory()->create());
    $appareil->envoyer('GET', '/dashboard')->assertOk();
    chiffreesPoser(true);

    expect(UneAutreRequete::litLaSession(chiffreesIdentifiant($appareil)))->toContain('login_web');

    return $appareil;
}

/**
 * Un appareil connecté sous le chiffrement, dont la session disparaît du
 * magasin : une session expirée.
 */
function chiffreesAppareilDontLaSessionAExpire(): Appareil
{
    chiffreesPoser(true);
    $appareil = chiffreesAppareilConnecte(User::factory()->create());
    $appareil->envoyer('GET', '/dashboard')->assertOk();
    app('session')->driver()->getHandler()->destroy(chiffreesIdentifiant($appareil));

    return $appareil;
}

/*
 * Le contrat hors ligne tient de la bascule ce qu'il tient d'une session
 * expirée : une session illisible est une session absente. Chaque requête que
 * la file hors ligne et le préchauffage envoient reçoit la même réponse
 * (statut, renvoi, cookies posés et effacés) que sous une session expirée,
 * dont le README dit ce qu'elle fait des copies et des écritures en attente.
 */
it('répond à la bascule comme à une session expirée, pour chaque requête que l’appareil envoie hors du parcours de la page', function (string $methode, string $chemin, array $entetes): void {
    /** @var array<string, string> $entetes */
    $apresLaBascule = chiffreesAppareilApresLaBascule();
    $expiree = chiffreesAppareilDontLaSessionAExpire();

    $attendu = chiffreesCeQueLeNavigateurRetient($expiree->envoyer($methode, $chemin, [], $entetes));
    $obtenu = chiffreesCeQueLeNavigateurRetient($apresLaBascule->envoyer($methode, $chemin, [], $entetes));

    expect($attendu['statut'])->toBeLessThan(500)
        ->and($obtenu)->toBe($attendu);
})->with([
    'une page' => ['GET', '/dashboard', []],
    'une page, en Inertia' => ['GET', '/dashboard', ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest']],
    'le préchauffage d’une page' => ['GET', '/dashboard', ['X-Copies-Hors-Ligne' => 'prechauffage']],
    'un préchargement' => ['GET', '/dashboard', ['Purpose' => 'prefetch', 'X-Inertia' => 'true']],
    'une écriture de l’API, d’une page de l’application' => ['POST', '/api/v1/sets', ['Accept' => 'application/json', 'Referer' => 'http://localhost/workouts/1']],
]);
