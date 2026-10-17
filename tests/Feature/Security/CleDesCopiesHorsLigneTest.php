<?php

declare(strict_types=1);

use App\Http\Middleware\CleDesCopiesHorsLigne;
use App\Models\Admin;
use App\Models\DailyJournal;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Support\CleDesCopiesEnSession;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Appareil;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withServerVariables;
use function Pest\Laravel\withSession;
use function Pest\Laravel\withUnencryptedCookie;

/*
 * Le témoin des copies hors ligne (#2048, partie C) : un cookie que la page
 * lit, porteur de la clé qui chiffrera les copies des pages gardées pour la
 * salle, posé sur toute réponse faite à un compte et effacé par celle qui dit
 * la fin de la session. Quand la session finit, les copies deviennent
 * illisibles sans qu'aucun code de la page ait besoin de tourner : le cookie
 * part avec la réponse, ou à son échéance, celle du cookie de session.
 *
 * Chaque fin de session se vérifie sur la réponse qui la dit, sans suivre le
 * renvoi : c'est elle que le navigateur reçoit d'abord, et la page qui suit
 * peut ne jamais venir (réseau coupé juste après).
 */

/**
 * L'instant où chaque test arrête l'horloge : les échéances s'y comparent.
 *
 * Loin dans le passé, à dessein : plus loin que la durée du cookie « se
 * souvenir de moi » (400 jours). Chaque échéance que l'application pose,
 * celle du rappel comprise, tombe alors avant la vraie heure, et le fichier
 * échoue dès qu'un maillon la juge par `Cookie::isCleared()`, qui lit
 * `time()`, et non à l'horloge du test : l'appareil des tests, ou la lecture
 * du rappel en file (`RappelDeLAppareil`). Proche de la vraie heure, une telle
 * erreur ne se verrait qu'à partir du lendemain de l'écriture du test.
 */
function temoinInstant(): Carbon
{
    return Carbon::parse('2025-06-14 18:04:00');
}

/**
 * Le témoin que la réponse pose ou efface, sous l'un ou l'autre de ses noms.
 *
 * @param  TestResponse<Response>  $reponse
 */
function temoinDe(TestResponse $reponse): ?Cookie
{
    foreach ($reponse->baseResponse->headers->getCookies() as $cookie) {
        if (in_array($cookie->getName(), [CleDesCopiesHorsLigne::TEMOIN, CleDesCopiesHorsLigne::TEMOIN_EN_HTTPS], true)) {
            return $cookie;
        }
    }

    return null;
}

/**
 * La ligne `Set-Cookie` du témoin, telle que le navigateur la lit.
 *
 * @param  TestResponse<Response>  $reponse
 */
function temoinLigneSetCookie(TestResponse $reponse): string
{
    foreach ($reponse->baseResponse->headers->all('set-cookie') as $ligne) {
        if (preg_match('/^(__Host-)?gt_copies=/', (string) $ligne) === 1) {
            return (string) $ligne;
        }
    }

    return '';
}

/**
 * La ligne `Set-Cookie` qui efface le témoin doit être une que le navigateur
 * accepte sous le nom `__Host-gt_copies` : `Secure`, posée sur `/`, sans
 * domaine. Il ignore sinon la ligne entière, et le témoin survit à la fin de
 * la session jusqu'à son échéance.
 *
 * @param  TestResponse<Response>  $reponse
 */
function temoinEffaceSousSonNomDeProduction(TestResponse $reponse): void
{
    $ligne = temoinLigneSetCookie($reponse);
    $attributs = strtolower($ligne);

    expect($ligne)->toStartWith(CleDesCopiesHorsLigne::TEMOIN_EN_HTTPS.'=deleted;')
        ->and($attributs)->toContain('; max-age=0')
        ->and($attributs)->toContain('; secure')
        ->and($attributs)->toMatch('#; path=/(;|$)#')
        ->and($attributs)->not->toContain('domain=');
}

/**
 * Les réglages de production : le cookie de session réservé à HTTPS, et
 * l'adresse de l'application, d'où Sanctum ouvre la session de l'API. Rend
 * l'origine sous laquelle l'appareil s'adresse à l'application.
 */
function temoinReglagesDeProduction(): string
{
    config([
        'session.secure' => true,
        'sanctum.stateful' => ['gym.example.org'],
    ]);

    return 'https://gym.example.org';
}

/**
 * Un appareil connecté au compte par le formulaire de connexion.
 */
function temoinAppareilConnecte(User $compte, bool $seSouvenirDeMoi = false, string $origine = ''): Appareil
{
    $appareil = new Appareil();
    $formulaire = ['email' => $compte->email, 'password' => 'password'];

    if ($seSouvenirDeMoi) {
        $formulaire['remember'] = '1';
    }

    $appareil->envoyer('POST', $origine.'/login', $formulaire)->assertRedirect(route('dashboard'));

    return $appareil;
}

/**
 * La clé que la session de la dernière requête garde pour le compte.
 */
function temoinCleDeLaSession(): mixed
{
    return session(CleDesCopiesEnSession::CLE);
}

/**
 * Une série d'une séance ouverte du compte, avec sa ligne en mémoire : la
 * requête qui la supprime se forme encore après la suppression du compte.
 */
function temoinSerieDe(User $compte): Set
{
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => null]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id]);

    return Set::factory()->create(['workout_line_id' => $ligne->id])->setRelation('workoutLine', $ligne);
}

/**
 * Une écriture de la page de séance par l'API, comme `SyncService` l'envoie :
 * en JSON, depuis la page de la séance, que Sanctum authentifie par la
 * session.
 *
 * @return TestResponse<Response>
 */
function temoinSupprimerLaSerieParLApi(Appareil $appareil, Set $serie, string $origine = ''): TestResponse
{
    $page = '/workouts/'.$serie->workoutLine->workout_id;

    return $appareil->envoyer('DELETE', $origine.route('api.v1.sets.destroy', $serie, absolute: false), [], [
        'Accept' => 'application/json',
        'Referer' => $origine === '' ? url($page) : $origine.$page,
    ]);
}

/**
 * Remplace la vérification CSRF par une sous-classe qui ne s'efface pas sous
 * les tests, le seul moyen d'en observer le refus par HTTP.
 */
function temoinProtegerCommeEnProduction(): void
{
    app()->bind(
        PreventRequestForgery::class,
        fn (Application $application): PreventRequestForgery => new class($application, $application->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        },
    );
}

/**
 * La garde de session nommée.
 */
function temoinGarde(string $nom): SessionGuard
{
    $garde = Auth::guard($nom);

    if (! $garde instanceof SessionGuard) {
        throw new LogicException("La garde {$nom} tient sa connexion en session.");
    }

    return $garde;
}

/**
 * Une session du navigateur qui tient à la fois un compte de l'application et
 * un super administrateur du panneau, avec le témoin de ce compte, servie en
 * production depuis une adresse admise au panneau.
 */
function temoinSessionDuCompteEtDuPanneau(bool $avecLeCompte = true): void
{
    config(['app.admin_allowed_ips' => ['203.0.113.5']]);
    app()->detectEnvironment(static fn (): string => 'production');
    withServerVariables(['REMOTE_ADDR' => '203.0.113.5']);
    test()->mock(MasterSupervisorRepository::class)->allows('all')->andReturn([]);
    test()->mock(SupervisorRepository::class)->allows('all')->andReturn([]);

    $administrateur = Admin::factory()->create();
    $administrateur->assignRole(Role::findOrCreate('super_admin', 'admin'));
    $compte = User::factory()->create();
    $gardeDuPanneau = temoinGarde('admin');
    $gardeDesComptes = temoinGarde('web');

    $session = [
        $gardeDuPanneau->getName() => $administrateur->getAuthIdentifier(),
        'password_hash_admin' => $gardeDuPanneau->hashPasswordForCookie($administrateur->getAuthPassword()),
        CleDesCopiesEnSession::ENTREE => ['cle' => 'cle-du-compte', 'compte' => (string) $compte->id],
    ];

    if ($avecLeCompte) {
        $session[$gardeDesComptes->getName()] = $compte->getAuthIdentifier();
        $session['password_hash_web'] = $gardeDesComptes->hashPasswordForCookie($compte->getAuthPassword());
    }

    withSession($session);
    withUnencryptedCookie(CleDesCopiesHorsLigne::nom(), 'cle-du-compte');
}

beforeEach(function (): void {
    Carbon::setTestNow(temoinInstant());
});

it('pose le témoin sur la réponse de la connexion : la clé de la session, lisible par la page, jamais chiffrée', function (): void {
    $compte = User::factory()->create();
    $appareil = new Appareil();

    $connexion = $appareil->envoyer('POST', '/login', ['email' => $compte->email, 'password' => 'password']);

    $connexion->assertRedirect(route('dashboard'));
    $temoin = temoinDe($connexion);
    $ligne = temoinLigneSetCookie($connexion);

    expect($temoin)->not->toBeNull()
        ->and($temoin?->getName())->toBe(CleDesCopiesHorsLigne::TEMOIN)
        ->and((string) $temoin?->getValue())->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($temoin?->getValue())->toBe(temoinCleDeLaSession())
        ->and(session(CleDesCopiesEnSession::COMPTE))->toBe((string) $compte->id)
        ->and($temoin?->isHttpOnly())->toBeFalse()
        ->and($temoin?->isSecure())->toBeFalse()
        ->and($temoin?->getSameSite())->toBe(Cookie::SAMESITE_LAX)
        ->and($temoin?->getPath())->toBe('/')
        ->and($temoin?->getDomain())->toBeNull()
        ->and($ligne)->toContain('samesite=lax')
        ->and($ligne)->toContain('path=/')
        ->and(strtolower($ligne))->not->toContain('httponly')
        ->and(strtolower($ligne))->not->toContain('domain=')
        ->and($appareil->cookie(CleDesCopiesHorsLigne::TEMOIN))->toBe($temoin?->getValue());

    // Le navigateur le renvoie tel quel : la page suivante reconduit la même clé.
    $accueil = $appareil->envoyer('GET', '/dashboard');

    $accueil->assertOk();

    expect(temoinDe($accueil)?->getValue())->toBe($temoin?->getValue());
});

it('prend le préfixe __Host- et l’attribut Secure quand le cookie de session est réservé à HTTPS', function (): void {
    config(['session.secure' => true]);
    $compte = User::factory()->create();

    $connexion = new Appareil()->envoyer('POST', 'https://gym.example.org/login', [
        'email' => $compte->email,
        'password' => 'password',
    ]);

    $temoin = temoinDe($connexion);

    expect($temoin?->getName())->toBe(CleDesCopiesHorsLigne::TEMOIN_EN_HTTPS)
        ->and($temoin?->isSecure())->toBeTrue()
        ->and($temoin?->getPath())->toBe('/')
        ->and($temoin?->getDomain())->toBeNull()
        ->and($temoin?->isHttpOnly())->toBeFalse()
        ->and(strtolower(temoinLigneSetCookie($connexion)))->toContain('; secure');
});

it('expire avec la session : sa durée de vie, repoussée à chaque réponse', function (): void {
    config(['session.lifetime' => 120]);
    $compte = User::factory()->create();
    $appareil = temoinAppareilConnecte($compte);

    Carbon::setTestNow(temoinInstant()->addMinutes(30));
    $accueil = $appareil->envoyer('GET', '/dashboard');

    expect(temoinDe($accueil)?->getExpiresTime())->toBe(temoinInstant()->addMinutes(30 + 120)->getTimestamp());
});

it('reste un cookie de session, sans échéance, quand la session finit avec le navigateur', function (): void {
    config(['session.expire_on_close' => true]);
    $compte = User::factory()->create();

    $connexion = new Appareil()->envoyer('POST', '/login', ['email' => $compte->email, 'password' => 'password']);

    expect(temoinDe($connexion)?->getExpiresTime())->toBe(0)
        ->and(strtolower(temoinLigneSetCookie($connexion)))->not->toContain('expires=');
});

it('vit douze heures quand l’appareil se souvient du compte, dès la réponse de la connexion', function (bool $finAvecLeNavigateur): void {
    config(['session.lifetime' => 120, 'session.expire_on_close' => $finAvecLeNavigateur]);
    $compte = User::factory()->create();
    $appareil = new Appareil();

    $connexion = $appareil->envoyer('POST', '/login', [
        'email' => $compte->email,
        'password' => 'password',
        'remember' => '1',
    ]);
    $accueil = $appareil->envoyer('GET', '/dashboard');

    expect(temoinDe($connexion)?->getExpiresTime())->toBe(temoinInstant()->addHours(12)->getTimestamp())
        ->and(temoinDe($accueil)?->getExpiresTime())->toBe(temoinInstant()->addHours(12)->getTimestamp());
})->with([
    'session de deux heures' => [false],
    'session qui finit avec le navigateur' => [true],
]);

/*
 * La garde ignore sans l'effacer un cookie « se souvenir de moi » que le
 * compte a révoqué en se déconnectant ailleurs : il ne reconnectera personne,
 * et l'appareil ne se souvient plus du compte au-delà de sa session.
 */
it('reprend l’échéance de la session quand le cookie « se souvenir de moi » ne vaut plus', function (): void {
    config(['session.lifetime' => 120]);
    $compte = User::factory()->create();
    $cetAppareil = temoinAppareilConnecte($compte, seSouvenirDeMoi: true);
    $unAutre = temoinAppareilConnecte($compte, seSouvenirDeMoi: true);

    $unAutre->envoyer('POST', '/logout')->assertRedirect();

    $accueil = $cetAppareil->envoyer('GET', '/dashboard');

    $accueil->assertOk();

    expect($cetAppareil->cookie(temoinGarde('web')->getRecallerName()))->not->toBeNull()
        ->and(temoinDe($accueil)?->getExpiresTime())->toBe(temoinInstant()->addMinutes(120)->getTimestamp());
});

it('tire une clé neuve à chaque session, et une par appareil', function (): void {
    $compte = User::factory()->create();
    $premier = temoinAppareilConnecte($compte);
    $cleDuPremier = $premier->cookie(CleDesCopiesHorsLigne::TEMOIN);
    $second = temoinAppareilConnecte($compte);

    $premier->envoyer('POST', '/logout')->assertRedirect();
    $premier->envoyer('POST', '/login', ['email' => $compte->email, 'password' => 'password'])->assertRedirect(route('dashboard'));

    expect($cleDuPremier)->toBeString()
        ->and($second->cookie(CleDesCopiesHorsLigne::TEMOIN))->toBeString()
        ->and($second->cookie(CleDesCopiesHorsLigne::TEMOIN))->not->toBe($cleDuPremier)
        ->and($premier->cookie(CleDesCopiesHorsLigne::TEMOIN))->toBeString()
        ->and($premier->cookie(CleDesCopiesHorsLigne::TEMOIN))->not->toBe($cleDuPremier);
});

it('ne reprend pas la clé d’un autre compte que la session gardait', function (): void {
    $compteA = User::factory()->create();
    $compteB = User::factory()->create();
    $appareil = new Appareil();

    $appareil->envoyer('GET', '/_dusk/login/'.$compteA->id)->assertOk();
    $cleDeA = $appareil->cookie(CleDesCopiesHorsLigne::TEMOIN);

    $appareil->envoyer('GET', '/_dusk/login/'.$compteB->id)->assertOk();

    expect($cleDeA)->toBeString()
        ->and($appareil->cookie(CleDesCopiesHorsLigne::TEMOIN))->toBeString()
        ->and($appareil->cookie(CleDesCopiesHorsLigne::TEMOIN))->not->toBe($cleDeA)
        ->and(session(CleDesCopiesEnSession::COMPTE))->toBe((string) $compteB->id);
});

/*
 * Les trois fins de session de HistoriqueDuCompteApresDeconnexionTest, faites
 * ailleurs qu'ici : la réponse qui renvoie vers la connexion efface le témoin,
 * sans attendre la page de connexion.
 */
it('efface le témoin sur le renvoi même qui suit une fin de session faite ailleurs', function (Closure $finirLaSession): void {
    $compte = User::factory()->create();
    DailyJournal::factory()->for($compte)->create();
    $appareil = temoinAppareilConnecte($compte);

    $appareil->envoyer('GET', '/daily-journals')->assertOk();

    expect($appareil->cookie(CleDesCopiesHorsLigne::TEMOIN))->toBeString();

    $finirLaSession($compte, $appareil);

    $renvoi = $appareil->envoyer('GET', '/daily-journals');

    $renvoi->assertRedirect(route('login'));
    $renvoi->assertCookieExpired(CleDesCopiesHorsLigne::TEMOIN);

    expect($appareil->cookie(CleDesCopiesHorsLigne::TEMOIN))->toBeNull()
        ->and(temoinCleDeLaSession())->toBeNull();
})->with([
    'un mot de passe changé depuis un autre appareil' => [function (User $compte): void {
        $compte->forceFill(['password' => Hash::make('un-autre-mot-de-passe')])->save();
    }],
    'un compte supprimé depuis le panneau' => [function (User $compte): void {
        $compte->delete();
    }],
    'une session expirée' => [function (User $compte, Appareil $appareil): void {
        $appareil->oublierLeCookie(config()->string('session.cookie'));
    }],
]);

it('efface le témoin à la déconnexion, par le formulaire comme par /_dusk/logout, et la connexion suivante en tire un neuf', function (string $methode, string $chemin): void {
    $compte = User::factory()->create();
    $appareil = temoinAppareilConnecte($compte);
    $cleDAvant = $appareil->cookie(CleDesCopiesHorsLigne::TEMOIN);

    $deconnexion = $appareil->envoyer($methode, $chemin);

    $deconnexion->assertCookieExpired(CleDesCopiesHorsLigne::TEMOIN);

    expect(temoinCleDeLaSession())->toBeNull();

    $appareil->envoyer('POST', '/login', ['email' => $compte->email, 'password' => 'password'])->assertRedirect(route('dashboard'));

    expect($appareil->cookie(CleDesCopiesHorsLigne::TEMOIN))->toBeString()
        ->and($appareil->cookie(CleDesCopiesHorsLigne::TEMOIN))->not->toBe($cleDAvant);
})->with([
    'POST /logout' => ['POST', '/logout'],
    '/_dusk/logout, qui déconnecte sans vider la session' => ['GET', '/_dusk/logout'],
]);

/*
 * Les trois fins de session de HistoriqueDuCompteApresDeconnexionTest, sous
 * l'API, que la séance saisie en ligne emprunte presque seule. Un compte
 * supprimé laisse son identifiant dans la session : seul le statut du refus
 * dit alors la fin de la session, la garde `web` n'ayant lu aucun compte.
 */
it('efface le témoin sur le 401 de /api/v1/sets', function (Closure $finirLaSession): void {
    $compte = User::factory()->create();
    $appareil = temoinAppareilConnecte($compte);
    $serie = temoinSerieDe($compte);

    $finirLaSession($compte, $appareil);

    $refus = temoinSupprimerLaSerieParLApi($appareil, $serie);

    $refus->assertUnauthorized();
    $refus->assertCookieExpired(CleDesCopiesHorsLigne::TEMOIN);

    expect($appareil->cookie(CleDesCopiesHorsLigne::TEMOIN))->toBeNull()
        ->and(temoinCleDeLaSession())->toBeNull();
})->with([
    'une session expirée' => [function (User $compte, Appareil $appareil): void {
        $appareil->oublierLeCookie(config()->string('session.cookie'));
    }],
    // Le refus vient du `AuthenticateSession` de Sanctum, que le groupe `api` pose après `auth:sanctum`.
    'un mot de passe changé depuis un autre appareil' => [function (User $compte): void {
        $compte->forceFill(['password' => Hash::make('un-autre-mot-de-passe')])->save();
    }],
    'un compte supprimé depuis le panneau' => [function (User $compte): void {
        $compte->delete();
    }],
]);

it('reconduit le témoin sur une réponse de /api/v1, avec la même clé et une échéance repoussée', function (): void {
    config(['session.lifetime' => 120]);
    $compte = User::factory()->create();
    $appareil = temoinAppareilConnecte($compte);
    $cle = $appareil->cookie(CleDesCopiesHorsLigne::TEMOIN);

    Carbon::setTestNow(temoinInstant()->addMinutes(90));
    $ecriture = temoinSupprimerLaSerieParLApi($appareil, temoinSerieDe($compte));

    $ecriture->assertNoContent();

    expect(temoinDe($ecriture)?->getValue())->toBe($cle)
        ->and(temoinDe($ecriture)?->getExpiresTime())->toBe(temoinInstant()->addMinutes(90 + 120)->getTimestamp())
        ->and(temoinDe($ecriture)?->isHttpOnly())->toBeFalse();
});

it('n’envoie rien du témoin à un invité qui ne l’avait pas', function (Closure $requete): void {
    User::factory()->create(['email' => 'compte@example.org']);

    /** @var TestResponse<Response> $reponse */
    $reponse = $requete(new Appareil());

    expect(temoinDe($reponse))->toBeNull()
        ->and(temoinLigneSetCookie($reponse))->toBe('');
})->with([
    'la page de connexion' => [fn (Appareil $appareil): TestResponse => $appareil->envoyer('GET', '/login')->assertOk()],
    'l’inscription' => [fn (Appareil $appareil): TestResponse => $appareil->envoyer('GET', '/register')->assertOk()],
    'une connexion refusée' => [fn (Appareil $appareil): TestResponse => $appareil->envoyer('POST', '/login', ['email' => 'compte@example.org', 'password' => 'pas-le-bon'])->assertRedirect()],
    'le renvoi d’une page qui exige un compte' => [fn (Appareil $appareil): TestResponse => $appareil->envoyer('GET', '/dashboard')->assertRedirect(route('login'))],
    'le 401 de l’API' => [fn (Appareil $appareil): TestResponse => $appareil->envoyer('DELETE', '/api/v1/sets/1', [], ['Accept' => 'application/json'])->assertUnauthorized()],
]);

it('efface le témoin d’une session sans compte, et la réponse suivante n’en dit plus rien', function (): void {
    $compte = User::factory()->create();
    $appareil = temoinAppareilConnecte($compte);

    $appareil->oublierLeCookie(config()->string('session.cookie'));
    $publique = $appareil->envoyer('GET', '/register');
    $suivante = $appareil->envoyer('GET', '/login');

    $publique->assertOk()->assertCookieExpired(CleDesCopiesHorsLigne::TEMOIN);
    $suivante->assertOk()->assertCookieMissing(CleDesCopiesHorsLigne::TEMOIN);
});

/*
 * La vérification CSRF n'a pas de rang dans l'ordre de priorité : posé en tête
 * du groupe `web`, le témoin l'enveloppe, et voit son refus. La clé est
 * oubliée : la session, si elle tient encore, en tire une neuve.
 */
it('efface le témoin sur un 419, et la page suivante en pose un neuf', function (): void {
    $compte = User::factory()->create();
    $appareil = temoinAppareilConnecte($compte);
    $cleDAvant = $appareil->cookie(CleDesCopiesHorsLigne::TEMOIN);
    temoinProtegerCommeEnProduction();

    // Ni jeton, ni `Sec-Fetch-Site: same-origin` : la vérification refuse.
    $refus = $appareil->envoyer('POST', '/logout');

    $refus->assertStatus(419)->assertCookieExpired(CleDesCopiesHorsLigne::TEMOIN);

    $accueil = $appareil->envoyer('GET', '/dashboard');

    $accueil->assertOk();

    expect(temoinDe($accueil)?->getValue())->toBeString()
        ->and(temoinDe($accueil)?->getValue())->not->toBe($cleDAvant);
});

/*
 * En production, le témoin s'appelle `__Host-gt_copies`, et le navigateur
 * refuse toute ligne `Set-Cookie` de ce nom qui n'est pas `Secure`, posée sur
 * `/`, sans domaine : un effacement qui les oublierait n'effacerait rien, et
 * la clé survivrait à la session. Chaque fin de session se lit donc sur la
 * ligne même, sous ces réglages.
 */
it('efface le témoin __Host- par une ligne que le navigateur accepte, sous les réglages de production', function (Closure $finirLaSession): void {
    $origine = temoinReglagesDeProduction();
    $compte = User::factory()->create();
    $appareil = temoinAppareilConnecte($compte, origine: $origine);

    expect($appareil->cookie(CleDesCopiesHorsLigne::TEMOIN_EN_HTTPS))->toBeString();

    /** @var TestResponse<Response> $reponse */
    $reponse = $finirLaSession($compte, $appareil, $origine);

    temoinEffaceSousSonNomDeProduction($reponse);

    expect($appareil->cookie(CleDesCopiesHorsLigne::TEMOIN_EN_HTTPS))->toBeNull()
        ->and(temoinCleDeLaSession())->toBeNull();
})->with([
    'la déconnexion' => [fn (User $compte, Appareil $appareil, string $origine): TestResponse => $appareil->envoyer('POST', $origine.'/logout')->assertRedirect()],
    'le renvoi qui suit la suppression du compte' => [function (User $compte, Appareil $appareil, string $origine): TestResponse {
        $compte->delete();

        return $appareil->envoyer('GET', $origine.'/dashboard')->assertRedirect($origine.'/login');
    }],
    'le 401 de /api/v1/sets qui suit la suppression du compte' => [function (User $compte, Appareil $appareil, string $origine): TestResponse {
        $serie = temoinSerieDe($compte);
        $compte->delete();

        return temoinSupprimerLaSerieParLApi($appareil, $serie, $origine)->assertUnauthorized();
    }],
    'un 419' => [function (User $compte, Appareil $appareil, string $origine): TestResponse {
        temoinProtegerCommeEnProduction();

        return $appareil->envoyer('POST', $origine.'/logout')->assertStatus(419);
    }],
]);

it('pose le témoin sur une réponse de fichier comme sur une page', function (Closure $reponseDeFichier, string $contenu): void {
    Route::middleware(['web', 'auth'])->get('/_temoin-fichier', $reponseDeFichier);
    $compte = User::factory()->create();
    $appareil = temoinAppareilConnecte($compte);
    $cleDeLaConnexion = $appareil->cookie(CleDesCopiesHorsLigne::TEMOIN);

    $reponse = $appareil->envoyer('GET', '/_temoin-fichier');

    $reponse->assertOk();
    $base = $reponse->baseResponse;
    $recu = $base instanceof Symfony\Component\HttpFoundation\BinaryFileResponse
        ? (string) file_get_contents($base->getFile()->getPathname())
        : $reponse->streamedContent();

    expect($recu)->toBe($contenu)
        ->and($cleDeLaConnexion)->toBeString()
        ->and(temoinDe($reponse)?->getValue())->toBe($cleDeLaConnexion);
})->with([
    'un fichier' => [fn (): Symfony\Component\HttpFoundation\BinaryFileResponse => response()->file(base_path('public/robots.txt')), (string) file_get_contents(dirname(__DIR__, 3).'/public/robots.txt')],
    'un flux' => [fn (): Symfony\Component\HttpFoundation\StreamedResponse => response()->stream(static function (): void {
        file_put_contents('php://output', 'contenu du flux');
    }), 'contenu du flux'],
]);

it('ne touche à aucun autre en-tête de la réponse', function (): void {
    $compte = User::factory()->create();
    $requete = Request::create('https://gym.example.org/dashboard');
    /** @var Illuminate\Session\Store $session */
    $session = app('session')->driver();
    $session->start();
    $requete->setLaravelSession($session);
    temoinGarde('web')->setUser($compte);

    $reponse = new Response('page', 200, [
        'Content-Security-Policy' => "default-src 'self'; script-src 'nonce-abc'",
        'Cache-Control' => 'no-store, private',
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Vary' => 'X-Inertia',
    ]);
    $avant = $reponse->headers->all();

    app(CleDesCopiesHorsLigne::class)->handle($requete, static fn (): Response => $reponse);

    $apres = $reponse->headers->all();
    unset($apres['set-cookie']);

    expect($apres)->toBe($avant)
        ->and($reponse->headers->getCookies())->toHaveCount(1)
        ->and($reponse->headers->getCookies()[0]->getName())->toBe(CleDesCopiesHorsLigne::TEMOIN);
});

/*
 * Pulse et le lecteur de journaux sont servis par `web`, mais le `Authenticate`
 * de Filament y fait de la garde du panneau la garde par défaut avant que
 * personne ne lise la garde `web` : la requête ne sait rien du compte de
 * l'application que la session tient, et le témoin reste tel quel. Horizon,
 * servi par `web` lui aussi, lit la garde `web`, et le reconduit comme une
 * page. Le panneau a sa propre pile, qui reprend le témoin : ses pages ne
 * lisent pas non plus le compte de l'application, et le laissent tel quel.
 */
it('laisse le témoin tel quel sous Pulse et le lecteur de journaux, qui ne lisent que l’administrateur', function (string $chemin): void {
    temoinSessionDuCompteEtDuPanneau();

    $reponse = get($chemin);

    $reponse->assertOk();

    expect(temoinDe($reponse))->toBeNull()
        ->and(session(CleDesCopiesEnSession::CLE))->toBe('cle-du-compte');
})->with([
    'Pulse' => ['/backoffice/pulse'],
    'le lecteur de journaux' => ['/backoffice/journaux'],
    'une page du panneau, hors des groupes' => ['/backoffice'],
]);

/*
 * La session du panneau est celle de l'application : sa déconnexion la vide,
 * compte de l'application et clé des copies compris. Sa réponse efface donc
 * le témoin, sous l'un et l'autre de ses noms.
 */
it('efface le témoin à la déconnexion du panneau, qui ferme aussi la session du compte', function (bool $reglagesDeProduction): void {
    $origine = $reglagesDeProduction ? temoinReglagesDeProduction() : '';
    temoinSessionDuCompteEtDuPanneau();

    $reponse = post($origine.'/backoffice/logout', [], ['Sec-Fetch-Site' => 'same-origin']);

    $reponse->assertRedirect($origine.'/backoffice/login')
        ->assertCookieExpired(CleDesCopiesHorsLigne::nom());

    expect(session()->has(temoinGarde('web')->getName()))->toBeFalse()
        ->and(temoinCleDeLaSession())->toBeNull();

    if ($reglagesDeProduction) {
        temoinEffaceSousSonNomDeProduction($reponse);
    }
})->with([
    'gt_copies' => [false],
    '__Host-gt_copies, sous les réglages de production' => [true],
]);

it('efface sous Pulse le témoin d’une session qui ne tient plus de compte', function (): void {
    temoinSessionDuCompteEtDuPanneau(avecLeCompte: false);

    get('/backoffice/pulse')->assertOk()->assertCookieExpired(CleDesCopiesHorsLigne::TEMOIN);
});

it('reconduit le témoin sous Horizon, qui lit le compte de l’application comme une page', function (): void {
    temoinSessionDuCompteEtDuPanneau();

    $reponse = get('/horizon');

    $reponse->assertOk();

    expect(temoinDe($reponse)?->getValue())->toBe('cle-du-compte');
});

/*
 * La page lit le témoin sans Ziggy ni le serveur : ses deux noms sont écrits
 * en dur dans le magasin des copies (resources/js/Utils/copiesHorsLigne.js,
 * partie D de #2048). Un nom changé ici laisserait la page sans clé, donc sans
 * copie, sans que rien ne le dise.
 */
it('est lu par la page sous les deux noms que le serveur pose', function (string $constante, string $nom): void {
    $source = (string) file_get_contents(resource_path('js/Utils/copiesHorsLigne.js'));

    expect(preg_match("/export const {$constante} = '([^']+)'/", $source, $correspondance))
        ->toBe(1, "`{$constante}` est introuvable dans le magasin des copies : si sa forme a changé, ce contrôle doit suivre.");

    expect($correspondance[1] ?? null)->toBe($nom);
})->with([
    'hors HTTPS' => ['TEMOIN', CleDesCopiesHorsLigne::TEMOIN],
    'réservé à HTTPS' => ['TEMOIN_EN_HTTPS', CleDesCopiesHorsLigne::TEMOIN_EN_HTTPS],
]);
