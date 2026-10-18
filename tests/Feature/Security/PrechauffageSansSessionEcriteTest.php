<?php

declare(strict_types=1);

use App\Http\Middleware\CleDesCopiesHorsLigne;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\OuvreLaSession;
use App\Models\User;
use App\Support\EcheanceDuCookie;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Appareil;
use Tests\Support\SessionsEspionnees;

/*
 * Les GET du préchauffage des copies hors ligne (#2048, partie D) lisent la
 * session sans l'enregistrer, et leur réponse ne pose aucun cookie
 * (`OuvreLaSession`). Ils partent en arrière-plan : ni prolonger la session,
 * ni réécrire une session qui a changé pendant qu'ils tournaient, ni rendre
 * au navigateur un cookie qu'une autre réponse a remplacé.
 *
 * Le magasin des sessions est un espion, sur celui des tests (en mémoire) :
 * chaque enregistrement s'y compte. Chaque cas a sa contre-épreuve, la même
 * requête sans la marque, qui enregistre la session et pose ses cookies.
 */

/**
 * Branche l'espion comme magasin des sessions de l'application, pour tout le
 * test.
 */
function prechauffageEspionner(): SessionsEspionnees
{
    $espion = new SessionsEspionnees(config()->integer('session.lifetime'));

    app('session')->extend('espion-du-prechauffage', fn (): SessionsEspionnees => $espion);
    config(['session.driver' => 'espion-du-prechauffage']);
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    return $espion;
}

/**
 * Les en-têtes d'une demande du préchauffage, comme `demanderLaPage` les
 * envoie, avec ou sans sa marque.
 *
 * @return array<string, string>
 */
function prechauffageEntetes(bool $marque = true): array
{
    return [
        'Accept' => 'text/html, application/xhtml+xml',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ...($marque ? [OuvreLaSession::ENTETE => OuvreLaSession::PRECHAUFFAGE] : []),
    ];
}

/**
 * Les cookies auxquels la réponse donne une valeur, par nom ; un effacement
 * n'en est pas un.
 *
 * @param  TestResponse<Response>  $reponse
 * @return list<string>
 */
function prechauffageCookiesPoses(TestResponse $reponse): array
{
    $poses = [];

    foreach ($reponse->baseResponse->headers->getCookies() as $cookie) {
        if (! EcheanceDuCookie::estPassee($cookie, Carbon::now())) {
            $poses[] = $cookie->getName();
        }
    }

    sort($poses);

    return $poses;
}

/**
 * Un appareil connecté au compte par le formulaire de connexion, l'espion
 * remis à zéro ensuite.
 */
function prechauffageAppareilConnecte(User $compte, SessionsEspionnees $espion): Appareil
{
    $appareil = new Appareil();

    $appareil->envoyer('POST', '/login', ['email' => $compte->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    expect($appareil->cookie(CleDesCopiesHorsLigne::nom()))->not->toBeNull();
    $espion->ecritures = [];

    return $appareil;
}

/**
 * Fait détruire la session, comme une déconnexion dans un autre onglet la
 * détruit, pendant la prochaine requête : juste après que celle-ci a lu le
 * compte dans la session.
 */
function prechauffageDetruireLaSessionPendantLaRequete(SessionsEspionnees $espion, string $identifiant): void
{
    $detruite = false;

    Event::listen(Authenticated::class, static function () use ($espion, $identifiant, &$detruite): void {
        if (! $detruite) {
            $detruite = true;
            $espion->destroy($identifiant);
        }
    });
}

it('lit la session sur un GET marqué sans l’enregistrer, et sa réponse ne pose aucun cookie', function (): void {
    $espion = prechauffageEspionner();
    $compte = User::factory()->create();
    $appareil = prechauffageAppareilConnecte($compte, $espion);

    $reponse = $appareil->envoyer('GET', '/dashboard', [], prechauffageEntetes());

    $reponse->assertOk()->assertHeader('X-Inertia', 'true');
    expect($reponse->json('component'))->toBe('Dashboard')
        ->and($reponse->json('props.auth.user.id'))->toBe($compte->id)
        ->and($espion->ecritures)->toBe([])
        ->and(prechauffageCookiesPoses($reponse))->toBe([]);
});

it('enregistre la session et pose ses cookies sur le même GET sans la marque (contre-épreuve)', function (): void {
    $espion = prechauffageEspionner();
    $compte = User::factory()->create();
    $appareil = prechauffageAppareilConnecte($compte, $espion);

    $reponse = $appareil->envoyer('GET', '/dashboard', [], prechauffageEntetes(marque: false));

    $reponse->assertOk();
    expect($espion->ecritures)->not->toBe([])
        ->and(prechauffageCookiesPoses($reponse))->toEqualCanonicalizing(['XSRF-TOKEN', CleDesCopiesHorsLigne::nom(), config()->string('session.cookie')]);
});

it('ne réécrit pas une session détruite pendant un GET marqué', function (): void {
    $espion = prechauffageEspionner();
    $compte = User::factory()->create();
    $appareil = prechauffageAppareilConnecte($compte, $espion);
    $identifiant = (string) $appareil->cookie(config()->string('session.cookie'));

    expect($espion->read($identifiant))->not->toBe('');
    prechauffageDetruireLaSessionPendantLaRequete($espion, $identifiant);

    $reponse = $appareil->envoyer('GET', '/dashboard', [], prechauffageEntetes());

    $reponse->assertOk();
    expect($espion->read($identifiant))->toBe('')
        ->and($espion->ecritures)->toBe([])
        ->and(prechauffageCookiesPoses($reponse))->toBe([]);
});

it('réécrit la session détruite pendant le même GET sans la marque, comme Laravel le fait (contre-épreuve)', function (): void {
    $espion = prechauffageEspionner();
    $compte = User::factory()->create();
    $appareil = prechauffageAppareilConnecte($compte, $espion);
    $identifiant = (string) $appareil->cookie(config()->string('session.cookie'));

    prechauffageDetruireLaSessionPendantLaRequete($espion, $identifiant);

    $appareil->envoyer('GET', '/dashboard', [], prechauffageEntetes(marque: false))->assertOk();

    expect($espion->read($identifiant))->not->toBe('')
        ->and($espion->ecritures)->toContain($identifiant);
});

it('renvoie un GET marqué d’une session finie vers la connexion, en effaçant le témoin, sans rien poser', function (): void {
    $espion = prechauffageEspionner();
    $compte = User::factory()->create();
    $appareil = prechauffageAppareilConnecte($compte, $espion);
    $cookiesDAvant = $appareil->copie();

    $appareil->envoyer('POST', '/logout')->assertRedirect();
    $espion->ecritures = [];

    $reponse = $cookiesDAvant->envoyer('GET', '/dashboard', [], prechauffageEntetes());

    $reponse->assertRedirect(route('login'));
    expect($cookiesDAvant->cookie(CleDesCopiesHorsLigne::nom()))->toBeNull()
        ->and(prechauffageCookiesPoses($reponse))->toBe([])
        ->and($espion->ecritures)->toBe([]);
});

it('n’écoute la marque que sur un GET : une connexion qui la porterait s’enregistre comme les autres', function (): void {
    $espion = prechauffageEspionner();
    $compte = User::factory()->create();
    $appareil = new Appareil();

    $reponse = $appareil->envoyer('POST', '/login', ['email' => $compte->email, 'password' => 'password'], [
        OuvreLaSession::ENTETE => OuvreLaSession::PRECHAUFFAGE,
    ]);

    $reponse->assertRedirect(route('dashboard'));
    expect($espion->ecritures)->not->toBe([])
        ->and(prechauffageCookiesPoses($reponse))->toContain(config()->string('session.cookie'));
    $appareil->envoyer('GET', '/dashboard', [], prechauffageEntetes())->assertOk();
});

it('ne laisse partir d’un GET marqué aucun cookie mis en file, sauf un effacement', function (): void {
    Route::middleware('web')->get('/_prechauffage-cookies-en-file', static function (): string {
        Cookie::queue('prechauffage_pose', 'valeur', 10);
        Cookie::queue(Cookie::forget('prechauffage_efface'));

        return 'ok';
    });

    $marquee = new Appareil()->envoyer('GET', '/_prechauffage-cookies-en-file', [], [OuvreLaSession::ENTETE => OuvreLaSession::PRECHAUFFAGE]);
    $nonMarquee = new Appareil()->envoyer('GET', '/_prechauffage-cookies-en-file');
    $noms = static fn (TestResponse $reponse): array => array_map(
        static fn (SymfonyCookie $cookie): string => $cookie->getName(),
        $reponse->baseResponse->headers->getCookies(),
    );

    expect($noms($marquee))->toBe(['prechauffage_efface'])
        ->and($noms($nonMarquee))->toContain('prechauffage_pose', 'prechauffage_efface');
});

it('écrit la marque comme la page l’envoie', function (): void {
    $module = (string) file_get_contents(base_path('resources/js/Utils/prechauffage.js'));

    expect($module)->toContain(sprintf("export const ENTETE_DU_PRECHAUFFAGE = '%s'", OuvreLaSession::ENTETE))
        ->toContain(sprintf("export const MARQUE_DU_PRECHAUFFAGE = '%s'", OuvreLaSession::PRECHAUFFAGE));
});
