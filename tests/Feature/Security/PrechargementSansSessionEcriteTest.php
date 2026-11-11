<?php

declare(strict_types=1);

use App\Http\Middleware\CleDesCopiesHorsLigne;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\EcheanceDuCookie;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Appareil;
use Tests\Support\SessionsEspionnees;

/*
 * Un préchargement d'Inertia lit la session sans l'enregistrer, et ne prend
 * pas le flash (#2148, décision D2 du propriétaire : oui, flash vide).
 *
 * Inertia marque chaque préchargement `Purpose: prefetch` ; un navigateur
 * marque les siens `Sec-Purpose: prefetch`. `Request::prefetch()` reconnaît
 * l'un et l'autre. Laravel ne sautait pour eux que l'adresse précédente : un
 * préchargement, que la personne ne voit pas, enregistrait et prolongeait la
 * session, reposait ses cookies et vieillissait le flash, et sa réponse
 * recopiait le message flash en attente dans la page préchargée, montrée avec
 * lui au toucher. Comme le préchauffage des copies
 * (`PrechauffageSansSessionEcriteTest`), un GET ou un HEAD préchargé ouvre et
 * lit la session sans l'enregistrer, et sa réponse ne pose aucun cookie, sauf
 * pour une session neuve, que le magasin ne tenait pas (un invité sans
 * cookie, une session expirée) : elle s'enregistre comme chez Laravel ; sa
 * page ne porte ni le flash de l'application (`flash.success`, `flash.error`),
 * ni celui d'Inertia (`Inertia::flash()`), ni les erreurs de validation qu'une
 * écriture a laissées : tout cela reste à la page qui l'attend.
 *
 * Le magasin des sessions est un espion : chaque enregistrement s'y compte.
 * Chaque cas a sa contre-épreuve, la même requête sans la marque.
 */

/**
 * Branche l'espion comme magasin des sessions de l'application, pour tout le
 * test.
 */
function prechargementEspionner(): SessionsEspionnees
{
    $espion = new SessionsEspionnees(config()->integer('session.lifetime'));

    app('session')->extend('espion-du-prechargement', fn (): SessionsEspionnees => $espion);
    config(['session.driver' => 'espion-du-prechargement']);
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    return $espion;
}

/**
 * Les en-têtes d'une visite d'Inertia, préchargée ou non.
 *
 * @param  array<string, string>  $marque  `['Purpose' => 'prefetch']`, comme Inertia, ou rien.
 * @return array<string, string>
 */
function prechargementEntetes(array $marque = ['Purpose' => 'prefetch']): array
{
    return [
        'Accept' => 'text/html, application/xhtml+xml',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ...$marque,
    ];
}

/**
 * Les cookies auxquels la réponse donne une valeur, par nom ; un effacement
 * n'en est pas un.
 *
 * @param  TestResponse<Response>  $reponse
 * @return list<string>
 */
function prechargementCookiesPoses(TestResponse $reponse): array
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
function prechargementAppareilConnecte(User $compte, SessionsEspionnees $espion): Appareil
{
    $appareil = new Appareil();

    $appareil->envoyer('POST', '/login', ['email' => $compte->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $espion->ecritures = [];

    return $appareil;
}

/**
 * Quatre écritures de test, chacune renvoyant avec ce qu'une vraie écriture
 * laisse à la page suivante : un message flash de l'application, un flash
 * d'Inertia, le `status` que le profil lit en prop, des erreurs de
 * validation.
 */
function prechargementRoutesQuiLaissentUnFlash(): void
{
    Route::middleware('web')->post('/_prechargement-flash', static fn (): Illuminate\Http\RedirectResponse => redirect('/dashboard')->with('success', 'Séance enregistrée.'));
    Route::middleware('web')->post('/_prechargement-flash-inertia', static function (): Illuminate\Http\RedirectResponse {
        Inertia::flash('proposerLesNotifications', true);

        return redirect('/dashboard');
    });
    Route::middleware('web')->post('/_prechargement-statut', static fn (): Illuminate\Http\RedirectResponse => redirect('/profile/edit')->with('status', 'notification-preferences-updated'));
    Route::middleware('web')->post('/_prechargement-erreurs', static fn (): Illuminate\Http\RedirectResponse => redirect('/dashboard')->withErrors(['name' => 'Le nom est obligatoire.']));
}

/**
 * Le jeton que la session enregistrée sous `identifiant` tient, ou rien.
 */
function prechargementJetonEnregistre(SessionsEspionnees $espion, ?string $identifiant): ?string
{
    $brut = $identifiant === null ? false : $espion->read($identifiant);

    if (! is_string($brut) || $brut === '') {
        return null;
    }

    $donnees = unserialize($brut, ['allowed_classes' => false]);

    return is_array($donnees) && is_string($donnees['_token'] ?? null) ? $donnees['_token'] : null;
}

/**
 * Le jeton que porte la page HTML rendue (`<meta name="csrf-token">`).
 *
 * @param  TestResponse<Response>  $reponse
 */
function prechargementJetonDeLaPage(TestResponse $reponse): ?string
{
    return preg_match('/<meta name="csrf-token" content="([^"]+)"/', (string) $reponse->getContent(), $trouve) === 1
        ? $trouve[1]
        : null;
}

dataset('marques d’un préchargement', [
    'Purpose, comme Inertia' => ['Purpose'],
    'Sec-Purpose, comme un navigateur' => ['Sec-Purpose'],
]);

it('lit la session d’un GET préchargé sans l’enregistrer ni la prolonger, et sa réponse ne pose aucun cookie', function (string $entete): void {
    $espion = prechargementEspionner();
    $compte = User::factory()->create();
    $appareil = prechargementAppareilConnecte($compte, $espion);

    $reponse = $appareil->envoyer('GET', '/dashboard', [], prechargementEntetes([$entete => 'prefetch']));

    $reponse->assertOk()->assertHeader('X-Inertia', 'true');
    expect($reponse->json('component'))->toBe('Dashboard')
        ->and($reponse->json('props.auth.user.id'))->toBe($compte->id)
        ->and($espion->ecritures)->toBe([])
        ->and(prechargementCookiesPoses($reponse))->toBe([]);
})->with('marques d’un préchargement');

/*
 * Un HEAD part du navigateur, pas d'Inertia : sans `X-Inertia`. Avec lui,
 * Inertia prend le corps vide d'un HEAD pour une réponse vide et renvoie
 * en arrière.
 */
it('n’enregistre pas non plus la session d’un HEAD préchargé', function (string $entete): void {
    $espion = prechargementEspionner();
    $compte = User::factory()->create();
    $appareil = prechargementAppareilConnecte($compte, $espion);

    $reponse = $appareil->envoyer('HEAD', '/dashboard', [], [$entete => 'prefetch']);

    $reponse->assertOk();
    expect($espion->ecritures)->toBe([])
        ->and(prechargementCookiesPoses($reponse))->toBe([]);
})->with('marques d’un préchargement');

it('enregistre la session et pose ses cookies sur le même GET sans la marque (contre-épreuve)', function (): void {
    $espion = prechargementEspionner();
    $compte = User::factory()->create();
    $appareil = prechargementAppareilConnecte($compte, $espion);

    $reponse = $appareil->envoyer('GET', '/dashboard', [], prechargementEntetes([]));

    $reponse->assertOk();
    expect($espion->ecritures)->not->toBe([])
        ->and(prechargementCookiesPoses($reponse))->toEqualCanonicalizing(['XSRF-TOKEN', CleDesCopiesHorsLigne::nom(), config()->string('session.cookie')]);
});

it('enregistre la session et pose ses cookies sur le même HEAD sans la marque (contre-épreuve)', function (): void {
    $espion = prechargementEspionner();
    $compte = User::factory()->create();
    $appareil = prechargementAppareilConnecte($compte, $espion);

    $reponse = $appareil->envoyer('HEAD', '/dashboard');

    $reponse->assertOk();
    expect($espion->ecritures)->not->toBe([])
        ->and(prechargementCookiesPoses($reponse))->toContain(config()->string('session.cookie'));
});

/*
 * Une session neuve, elle, s'enregistre et pose ses cookies : la page
 * préchargée porte son jeton, qu'aucune session ne tiendrait sinon, et le
 * premier envoi de la page (la connexion) échouerait en 419. Le cas d'un
 * document que le navigateur précharge pour un invité sans cookie.
 */
it('enregistre la session neuve d’un invité sans cookie, dont la page préchargée porte le jeton, et pose ses cookies', function (string $entete): void {
    $espion = prechargementEspionner();
    $appareil = new Appareil();

    $reponse = $appareil->envoyer('GET', '/login', [], ['Accept' => 'text/html', $entete => 'prefetch']);

    $reponse->assertOk();
    $jeton = prechargementJetonDeLaPage($reponse);
    $identifiant = $appareil->cookie(config()->string('session.cookie'));

    expect($jeton)->not->toBeNull()
        ->and(prechargementCookiesPoses($reponse))->toEqualCanonicalizing(['XSRF-TOKEN', config()->string('session.cookie')])
        ->and($espion->ecritures)->toBe([$identifiant])
        ->and(prechargementJetonEnregistre($espion, $identifiant))->toBe($jeton)
        ->and($appareil->cookie('XSRF-TOKEN'))->toBe($jeton);
})->with('marques d’un préchargement');

/*
 * Une session expirée : le cookie revient, le magasin ne la tient plus. Le
 * préchargement d'une page du compte renvoie vers la connexion, sous une
 * session neuve qu'il enregistre, avec ses cookies : la page de connexion
 * qu'Inertia en garde se montre au toucher, et son envoi part avec le jeton
 * de cette session. Laravel garde l'identifiant reçu, même que le magasin
 * ne tient plus.
 */
it('enregistre la session neuve qui remplace une session expirée, et pose ses cookies', function (): void {
    $espion = prechargementEspionner();
    $appareil = prechargementAppareilConnecte(User::factory()->create(), $espion);
    $expiree = $appareil->cookie(config()->string('session.cookie'));
    $espion->destroy((string) $expiree);

    $reponse = $appareil->envoyer('GET', '/dashboard', [], prechargementEntetes());

    $reponse->assertRedirect(route('login'));
    $identifiant = $appareil->cookie(config()->string('session.cookie'));

    expect($identifiant)->not->toBeNull()
        ->and($espion->ecritures)->toBe([$identifiant])
        ->and(prechargementCookiesPoses($reponse))->toContain('XSRF-TOKEN', config()->string('session.cookie'))
        ->and(prechargementJetonEnregistre($espion, $identifiant))->toBe($appareil->cookie('XSRF-TOKEN'));
});

/*
 * Deux préchargements de suite : le flash survit à plusieurs. Un seul
 * suffirait pourtant à le perdre s'il enregistrait la session : chaque
 * enregistrement oublie les clés flashées pour la requête (`_flash.old`),
 * et la page qui le lit est la dernière à le voir (contre-épreuve suivante).
 */
it('ne montre pas le message flash en attente dans la page préchargée, et le laisse à la page suivante', function (): void {
    prechargementRoutesQuiLaissentUnFlash();
    $espion = prechargementEspionner();
    $appareil = prechargementAppareilConnecte(User::factory()->create(), $espion);

    $appareil->envoyer('POST', '/_prechargement-flash', [], prechargementEntetes([]))->assertRedirect('/dashboard');

    foreach (range(1, 2) as $prechargement) {
        $prechargee = $appareil->envoyer('GET', '/dashboard', [], prechargementEntetes());

        $prechargee->assertOk()
            ->assertJsonPath('props.flash.success', null)
            ->assertJsonPath('props.flash.error', null);
    }

    $appareil->envoyer('GET', '/dashboard', [], prechargementEntetes([]))
        ->assertOk()
        ->assertJsonPath('props.flash.success', 'Séance enregistrée.');
});

it('montre le message flash dans la page demandée sans la marque, une fois (contre-épreuve)', function (): void {
    prechargementRoutesQuiLaissentUnFlash();
    $espion = prechargementEspionner();
    $appareil = prechargementAppareilConnecte(User::factory()->create(), $espion);

    $appareil->envoyer('POST', '/_prechargement-flash', [], prechargementEntetes([]))->assertRedirect('/dashboard');

    $appareil->envoyer('GET', '/dashboard', [], prechargementEntetes([]))->assertJsonPath('props.flash.success', 'Séance enregistrée.');
    $appareil->envoyer('GET', '/dashboard', [], prechargementEntetes([]))->assertJsonPath('props.flash.success', null);
});

it('ne prend pas le flash d’Inertia dans la page préchargée, et le laisse à la page suivante', function (): void {
    prechargementRoutesQuiLaissentUnFlash();
    $espion = prechargementEspionner();
    $appareil = prechargementAppareilConnecte(User::factory()->create(), $espion);

    $appareil->envoyer('POST', '/_prechargement-flash-inertia', [], prechargementEntetes([]))->assertRedirect('/dashboard');

    foreach (range(1, 2) as $prechargement) {
        $appareil->envoyer('GET', '/dashboard', [], prechargementEntetes())
            ->assertOk()
            ->assertJsonMissingPath('flash.proposerLesNotifications');
    }

    $appareil->envoyer('GET', '/dashboard', [], prechargementEntetes([]))
        ->assertOk()
        ->assertJsonPath('flash.proposerLesNotifications', true);
});

it('ne recopie pas dans la page préchargée les erreurs qu’une écriture a laissées, et les laisse à la page suivante', function (): void {
    prechargementRoutesQuiLaissentUnFlash();
    $espion = prechargementEspionner();
    $appareil = prechargementAppareilConnecte(User::factory()->create(), $espion);

    $appareil->envoyer('POST', '/_prechargement-erreurs', [], prechargementEntetes([]))->assertRedirect('/dashboard');

    foreach (range(1, 2) as $prechargement) {
        $appareil->envoyer('GET', '/dashboard', [], prechargementEntetes())
            ->assertOk()
            ->assertJsonPath('props.errors', []);
    }

    $appareil->envoyer('GET', '/dashboard', [], prechargementEntetes([]))
        ->assertOk()
        ->assertJsonPath('props.errors.name', 'Le nom est obligatoire.');
});

it('ne passe pas à la page préchargée le statut qu’un contrôleur lit dans la session, et le laisse à la page suivante', function (): void {
    prechargementRoutesQuiLaissentUnFlash();
    $espion = prechargementEspionner();
    $appareil = prechargementAppareilConnecte(User::factory()->create(), $espion);

    $appareil->envoyer('POST', '/_prechargement-statut', [], prechargementEntetes([]))->assertRedirect('/profile/edit');

    foreach (range(1, 2) as $prechargement) {
        $appareil->envoyer('GET', '/profile/edit', [], prechargementEntetes())
            ->assertOk()
            ->assertJsonPath('props.status', null);
    }

    $appareil->envoyer('GET', '/profile/edit', [], prechargementEntetes([]))
        ->assertOk()
        ->assertJsonPath('props.status', 'notification-preferences-updated');
});

/*
 * La marque ne vaut que pour une lecture : une écriture qui la porterait
 * enregistre sa session et laisse son flash, comme les autres.
 */
it('garde à une écriture qui porte la marque le comportement de toute écriture', function (): void {
    prechargementRoutesQuiLaissentUnFlash();
    $espion = prechargementEspionner();
    $compte = User::factory()->create();
    $appareil = new Appareil();

    $connexion = $appareil->envoyer('POST', '/login', ['email' => $compte->email, 'password' => 'password'], ['Purpose' => 'prefetch']);

    $connexion->assertRedirect(route('dashboard'));
    expect($espion->ecritures)->not->toBe([])
        ->and(prechargementCookiesPoses($connexion))->toContain(config()->string('session.cookie'));

    $espion->ecritures = [];
    $ecriture = $appareil->envoyer('POST', '/_prechargement-flash', [], prechargementEntetes());

    $ecriture->assertRedirect('/dashboard');
    expect($espion->ecritures)->not->toBe([]);
    $appareil->envoyer('GET', '/dashboard', [], prechargementEntetes([]))
        ->assertJsonPath('props.flash.success', 'Séance enregistrée.');
});
