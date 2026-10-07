<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DailyJournal;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Appareil;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Ce que le navigateur garde des pages d'un compte, une fois parti (#1965).
 *
 * Inertia range les props de chaque page visitée dans l'historique du
 * navigateur, et le bouton Retour les réaffiche sans rien demander au serveur.
 * Sur un appareil partagé, la personne qui le prenait après une déconnexion
 * revoyait le journal, les mesures et l'adresse du compte parti. Deux en-têtes
 * de page y répondent, que le client d'Inertia applique : `encryptHistory` sur
 * chaque page d'un compte, `clearHistory` sur la première page que la session
 * sert après la déconnexion, la suppression du compte, toute autre fin de
 * session et toute connexion. Le document lui-même sort en `no-store`, pour
 * que le cache HTTP ne le resserve pas à une navigation arrière qui quitte la
 * page courante.
 *
 * `clearHistory` ne part que vers l'onglet qui reçoit cette page. Les autres
 * onglets du navigateur jettent leur clé d'après le titulaire que chaque page
 * déclare dans `auth.user`, ce que le dernier cas de ce fichier tient côté
 * serveur.
 *
 * Ce que le navigateur fait de ces en-têtes est tenu par
 * tests/js/utils/historiqueDuCompte.test.js et
 * tests/js/utils/historiqueDuCompteParOnglet.test.js, avec le vrai client
 * d'Inertia, et de bout en bout par les parcours
 * `tests/Browser/HistoriqueApresDeconnexionTest.php` et
 * `tests/Browser/HistoriqueDeDeuxOngletsTest.php`.
 */

/**
 * La page Inertia d'une réponse : du JSON pour une visite, le `data-page` du
 * document pour un chargement complet.
 *
 * @param  TestResponse<Response>  $reponse
 * @return array<string, mixed>
 */
function historiquePageDe(TestResponse $reponse): array
{
    /** @var array<string, mixed> $page */
    $page = $reponse->headers->get('X-Inertia') === 'true' ? $reponse->json() : $reponse->inertiaPage();

    return $page;
}

/**
 * Les en-têtes d'une visite Inertia, à la version des actifs du serveur ou à
 * celle qu'annonce une page périmée (#1967).
 *
 * @return array<string, string>
 */
function historiqueEntetesDeVisite(?string $version = null): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version ?? (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ];
}

/**
 * Suit les redirections comme le client d'Inertia : chaque étape est une
 * visite GET, jusqu'à la première page rendue.
 *
 * @param  TestResponse<Response>  $reponse
 * @return TestResponse<Response>
 */
function historiqueSuivreJusquALaPage(Appareil $appareil, TestResponse $reponse): TestResponse
{
    for ($etape = 0; $reponse->isRedirect() && $etape < 5; $etape++) {
        $reponse = $appareil->envoyer('GET', (string) $reponse->headers->get('Location'), [], historiqueEntetesDeVisite());
    }

    return $reponse;
}

/**
 * Un appareil connecté au compte, par le formulaire de connexion.
 */
function historiqueAppareilConnecte(User $compte, string $origine = 'https://gym.example.org'): Appareil
{
    $appareil = new Appareil();

    $appareil->envoyer('POST', $origine.'/login', [
        'email' => $compte->email,
        'password' => 'password',
    ])->assertRedirect($origine.'/dashboard');

    return $appareil;
}

/**
 * Le compte qui va partir, et la note de journal qu'il ne faut plus revoir.
 */
function historiqueCompteParti(): User
{
    $compte = User::factory()->create(['email' => 'compte-parti@example.org']);
    DailyJournal::factory()->for($compte)->create(['content' => 'Note privée du compte parti']);

    return $compte;
}

it('chiffre l’historique de chaque page d’un compte, là où le navigateur sait chiffrer', function (string $origine): void {
    $compte = historiqueCompteParti();
    $appareil = historiqueAppareilConnecte($compte, $origine);

    $complete = $appareil->envoyer('GET', $origine.'/daily-journals');
    $visite = $appareil->envoyer('GET', $origine.'/daily-journals', [], historiqueEntetesDeVisite());

    foreach ([$complete, $visite] as $reponse) {
        $reponse->assertOk();
        $page = historiquePageDe($reponse);

        expect($page['component'])->toBe('Journal/Index')
            ->and(json_encode($page['props'], JSON_UNESCAPED_UNICODE))->toContain('Note privée du compte parti')
            ->and($page['encryptHistory'] ?? false)->toBeTrue();
    }
})->with([
    'en HTTPS, derrière le proxy inverse' => ['https://gym.example.org'],
    'sur localhost' => ['http://localhost'],
    'sur un sous-domaine de localhost' => ['http://gym.localhost'],
    'sur 127.0.0.1, comme les parcours de la CI' => ['http://127.0.0.1:8000'],
    'sur ::1' => ['http://[::1]:8000'],
]);

/*
 * Sans `crypto.subtle`, qu'un navigateur réserve à HTTPS et à la boucle
 * locale, Inertia ne sait pas créer sa clé et lève à la première page :
 * l'écran resterait blanc. Les parcours navigateur sous Sail, servis en http
 * sur `laravel.test`, sont dans ce cas.
 */
it('ne demande pas le chiffrement à une origine en http, où le navigateur ne saurait pas chiffrer', function (string $origine): void {
    $compte = historiqueCompteParti();
    $appareil = historiqueAppareilConnecte($compte, $origine);

    $reponse = $appareil->envoyer('GET', $origine.'/daily-journals', [], historiqueEntetesDeVisite());

    $reponse->assertOk();

    expect(historiquePageDe($reponse))->not->toHaveKey('encryptHistory');
})->with([
    'le nom des parcours sous Sail' => ['http://laravel.test'],
    'une adresse du réseau local' => ['http://192.0.2.10'],
    'un nom qui finit seulement comme localhost' => ['http://pas-localhost'],
    'un nom qui commence par localhost sans en être un sous-domaine' => ['http://localhost.example.org'],
]);

it('laisse les pages publiques en clair et dans le cache, comme avant', function (): void {
    $reponse = get('https://gym.example.org/login');

    $reponse->assertOk();

    expect(historiquePageDe($reponse))->not->toHaveKey('encryptHistory')
        ->and((string) $reponse->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('demande à la première page après la déconnexion d’effacer l’historique, et à elle seule', function (): void {
    $compte = historiqueCompteParti();
    $appareil = historiqueAppareilConnecte($compte);

    $appareil->envoyer('GET', 'https://gym.example.org/daily-journals', [], historiqueEntetesDeVisite())->assertOk();

    $deconnexion = $appareil->envoyer('POST', 'https://gym.example.org/logout', [], historiqueEntetesDeVisite());

    $deconnexion->assertRedirect('https://gym.example.org');

    $premiere = historiqueSuivreJusquALaPage($appareil, $deconnexion);
    $suivante = $appareil->envoyer('GET', 'https://gym.example.org/login', [], historiqueEntetesDeVisite());

    $premiere->assertOk();

    expect(historiquePageDe($premiere)['component'])->toBe('Auth/Login')
        ->and(historiquePageDe($premiere)['clearHistory'] ?? false)->toBeTrue()
        ->and(historiquePageDe($premiere))->not->toHaveKey('encryptHistory')
        ->and(historiquePageDe($suivante))->not->toHaveKey('clearHistory');
});

it('demande à la première page après la suppression du compte d’effacer l’historique', function (): void {
    $compte = historiqueCompteParti();
    $appareil = historiqueAppareilConnecte($compte);

    $suppression = $appareil->envoyer('DELETE', 'https://gym.example.org/profile', ['password' => 'password'], historiqueEntetesDeVisite());

    $suppression->assertRedirect('https://gym.example.org');

    $premiere = historiqueSuivreJusquALaPage($appareil, $suppression);

    $premiere->assertOk();

    expect($compte->fresh())->toBeNull()
        ->and(historiquePageDe($premiere)['component'])->toBe('Auth/Login')
        ->and(historiquePageDe($premiere)['clearHistory'] ?? false)->toBeTrue();
});

/*
 * La déconnexion et la suppression du compte ne sont pas les seules fins d'une
 * session. Un mot de passe changé depuis un autre appareil (#1940), un compte
 * supprimé depuis le panneau, une session expirée renvoient vers la connexion
 * sans passer par elles : la clé restait dans l'onglet, et les pages chiffrées
 * du compte se relisaient au bouton Retour.
 */
it('demande à la page qui suit la fin de la session, faite ailleurs, d’effacer l’historique', function (Closure $finirLaSession): void {
    $compte = historiqueCompteParti();
    $appareil = historiqueAppareilConnecte($compte);

    expect(historiquePageDe($appareil->envoyer('GET', 'https://gym.example.org/daily-journals', [], historiqueEntetesDeVisite()))['encryptHistory'] ?? false)->toBeTrue();

    $finirLaSession($compte, $appareil);

    $visite = $appareil->envoyer('GET', 'https://gym.example.org/daily-journals', [], historiqueEntetesDeVisite());

    $visite->assertRedirect('https://gym.example.org/login');

    $premiere = historiqueSuivreJusquALaPage($appareil, $visite);
    $suivante = $appareil->envoyer('GET', 'https://gym.example.org/login', [], historiqueEntetesDeVisite());

    $premiere->assertOk();

    expect(historiquePageDe($premiere)['component'])->toBe('Auth/Login')
        ->and(historiquePageDe($premiere)['clearHistory'] ?? false)->toBeTrue()
        ->and(historiquePageDe($suivante))->not->toHaveKey('clearHistory');
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

/*
 * Une session expirée ne mène pas toujours à une page qui exige un compte :
 * l'onglet peut aller droit à une page publique, et le bouton Retour revenir
 * de là aux pages du compte.
 */
it('demande à la première page d’une session expirée d’effacer l’historique, même publique', function (): void {
    $compte = historiqueCompteParti();
    $appareil = historiqueAppareilConnecte($compte);

    $appareil->envoyer('GET', 'https://gym.example.org/daily-journals', [], historiqueEntetesDeVisite())->assertOk();
    $appareil->oublierLeCookie(config()->string('session.cookie'));

    $publique = $appareil->envoyer('GET', 'https://gym.example.org/register', [], historiqueEntetesDeVisite());

    $publique->assertOk();

    expect(historiquePageDe($publique)['component'])->toBe('Auth/Register')
        ->and(historiquePageDe($publique)['clearHistory'] ?? false)->toBeTrue();
});

/*
 * Un compte qui se connecte dans un onglet ne reprend jamais la clé d'un
 * autre : la page de connexion a pu être servie avant que la session de
 * l'autre ne se termine, ou dans un autre onglet.
 */
it('demande à la première page après une connexion d’effacer l’historique, et à elle seule', function (Closure $seConnecter): void {
    $appareil = new Appareil();

    $appareil->envoyer('GET', 'https://gym.example.org/login', [], historiqueEntetesDeVisite())->assertOk();

    /** @var TestResponse<Response> $connexion */
    $connexion = $seConnecter($appareil);

    $connexion->assertRedirect();

    $premiere = historiqueSuivreJusquALaPage($appareil, $connexion);
    $suivante = $appareil->envoyer('GET', 'https://gym.example.org/profile', [], historiqueEntetesDeVisite());

    $premiere->assertOk();
    $suivante->assertOk();

    expect((string) $connexion->headers->get('Location'))->toEndWith('/dashboard')
        ->and(historiquePageDe($premiere)['clearHistory'] ?? false)->toBeTrue()
        ->and(historiquePageDe($premiere)['encryptHistory'] ?? false)->toBeTrue()
        ->and(historiquePageDe($suivante))->not->toHaveKey('clearHistory');
})->with([
    'par le formulaire de connexion' => [function (Appareil $appareil): TestResponse {
        $compte = User::factory()->create(['email' => 'compte-suivant@example.org']);

        return $appareil->envoyer('POST', 'https://gym.example.org/login', [
            'email' => $compte->email,
            'password' => 'password',
        ], historiqueEntetesDeVisite());
    }],
    'par l’inscription' => [fn (Appareil $appareil): TestResponse => $appareil->envoyer('POST', 'https://gym.example.org/register', [
        'name' => 'Compte suivant',
        'email' => 'compte-suivant@example.org',
        'password' => 'un-mot-de-passe-solide-2026',
        'password_confirmation' => 'un-mot-de-passe-solide-2026',
    ], historiqueEntetesDeVisite())],
]);

it('ne demande pas d’effacer l’historique quand la suppression est refusée', function (): void {
    $compte = historiqueCompteParti();
    $appareil = historiqueAppareilConnecte($compte);

    // La première page après la connexion jette la clé d'un éventuel compte précédent.
    $appareil->envoyer('GET', 'https://gym.example.org/profile', [], historiqueEntetesDeVisite())->assertOk();

    $appareil->envoyer('DELETE', 'https://gym.example.org/profile', ['password' => 'pas-le-bon'], [
        ...historiqueEntetesDeVisite(),
        'Referer' => 'https://gym.example.org/profile',
    ])->assertRedirect('https://gym.example.org/profile');

    $profil = $appareil->envoyer('GET', 'https://gym.example.org/profile', [], historiqueEntetesDeVisite());

    $profil->assertOk();

    expect($compte->fresh())->not->toBeNull()
        ->and(historiquePageDe($profil))->not->toHaveKey('clearHistory')
        ->and(historiquePageDe($profil)['encryptHistory'] ?? false)->toBeTrue();
});

/*
 * `ResponseFactory` est un singleton : une valeur posée pour une requête
 * resterait pour la suivante du même processus, sous Octane comme ici. Chaque
 * requête doit donc redire ce qu'elle veut.
 */
it('ne transmet pas le chiffrement d’une page de compte à la requête suivante d’un invité', function (): void {
    $compte = historiqueCompteParti();
    $connecte = historiqueAppareilConnecte($compte);

    expect(historiquePageDe($connecte->envoyer('GET', 'https://gym.example.org/daily-journals'))['encryptHistory'] ?? false)->toBeTrue();

    $invite = new Appareil();

    expect(historiquePageDe($invite->envoyer('GET', 'https://gym.example.org/login')))->not->toHaveKey('encryptHistory');
});

it('interdit au navigateur de garder une réponse faite à un compte connecté', function (): void {
    $compte = historiqueCompteParti();
    $appareil = historiqueAppareilConnecte($compte);

    $complete = $appareil->envoyer('GET', 'https://gym.example.org/daily-journals');
    $visite = $appareil->envoyer('GET', 'https://gym.example.org/daily-journals', [], historiqueEntetesDeVisite());
    $absente = $appareil->envoyer('GET', 'https://gym.example.org/workouts/999999999');

    $complete->assertOk();
    $visite->assertOk();
    $absente->assertNotFound();

    foreach ([$complete, $visite, $absente] as $reponse) {
        expect($reponse->headers->get('Cache-Control'))->toBe('no-store, private');
    }
});

it('pose le même cache sur la séance d’un autre que sur une séance absente', function (): void {
    $compte = historiqueCompteParti();

    $autre = User::factory()->create();
    $seanceDAutrui = Workout::factory()->for($autre)->create();

    actingAs($compte);

    $refusee = get('https://gym.example.org/workouts/'.$seanceDAutrui->id);
    $absente = get('https://gym.example.org/workouts/999999999');

    $refusee->assertNotFound();
    $absente->assertNotFound();

    expect($refusee->headers->get('Cache-Control'))->toBe($absente->headers->get('Cache-Control'))
        ->and($refusee->headers->get('Cache-Control'))->toBe('no-store, private');
});

/*
 * La première visite après chaque mise à jour du worker annonce une version
 * d'actifs périmée (#1967) : le serveur répond 409, et Inertia charge la page
 * en entier. Inertia retirait la consigne de la session en construisant la
 * page que le 409 remplace, et la page complète ne la portait plus.
 */
it('rend la consigne à la page complète qu’Inertia charge après le 409 d’une version périmée', function (Closure $finirLaSession): void {
    $compte = historiqueCompteParti();
    $appareil = historiqueAppareilConnecte($compte);

    $appareil->envoyer('GET', 'https://gym.example.org/daily-journals', [], historiqueEntetesDeVisite())->assertOk();

    /** @var TestResponse<Response> $reponse */
    $reponse = $finirLaSession($compte, $appareil);

    for ($etape = 0; $reponse->isRedirect() && $etape < 5; $etape++) {
        $reponse = $appareil->envoyer('GET', (string) $reponse->headers->get('Location'), [], historiqueEntetesDeVisite('perimee'));
    }

    $reponse->assertStatus(409);

    // Inertia charge l'adresse du 409 en document complet, et le navigateur suit les redirections.
    $document = $appareil->envoyer('GET', (string) $reponse->headers->get('X-Inertia-Location'));

    for ($etape = 0; $document->isRedirect() && $etape < 5; $etape++) {
        $document = $appareil->envoyer('GET', (string) $document->headers->get('Location'));
    }

    $suivant = $appareil->envoyer('GET', 'https://gym.example.org/login');

    $document->assertOk();

    expect(historiquePageDe($document)['component'])->toBe('Auth/Login')
        ->and(historiquePageDe($document)['clearHistory'] ?? false)->toBeTrue()
        ->and(historiquePageDe($suivant))->not->toHaveKey('clearHistory');
})->with([
    'un mot de passe changé depuis un autre appareil' => [function (User $compte, Appareil $appareil): TestResponse {
        $compte->forceFill(['password' => Hash::make('un-autre-mot-de-passe')])->save();

        return $appareil->envoyer('GET', 'https://gym.example.org/dashboard', [], historiqueEntetesDeVisite('perimee'));
    }],
    'une session expirée' => [function (User $compte, Appareil $appareil): TestResponse {
        $appareil->oublierLeCookie(config()->string('session.cookie'));

        return $appareil->envoyer('GET', 'https://gym.example.org/daily-journals', [], historiqueEntetesDeVisite('perimee'));
    }],
    'la déconnexion' => [fn (User $compte, Appareil $appareil): TestResponse => $appareil->envoyer('POST', 'https://gym.example.org/logout', [], historiqueEntetesDeVisite('perimee'))],
]);

it('n’invente pas la consigne pour le 409 d’une page qui ne la portait pas', function (): void {
    $compte = historiqueCompteParti();
    $appareil = historiqueAppareilConnecte($compte);

    $appareil->envoyer('GET', 'https://gym.example.org/daily-journals', [], historiqueEntetesDeVisite())->assertOk();

    $appareil->envoyer('GET', 'https://gym.example.org/profile', [], historiqueEntetesDeVisite('perimee'))
        ->assertStatus(409);

    $document = $appareil->envoyer('GET', 'https://gym.example.org/profile');

    $document->assertOk();

    expect(historiquePageDe($document))->not->toHaveKey('clearHistory')
        ->and(historiquePageDe($document)['encryptHistory'] ?? false)->toBeTrue();
});

/*
 * La session sert tous les onglets du navigateur, et `clearHistory` ne part
 * qu'avec la première page qu'elle rend, dans l'onglet qui la demande. Les
 * autres onglets décident eux-mêmes, d'après le titulaire que chaque page
 * déclare (resources/js/Utils/historiqueDuCompte.js) : une page qui ne le
 * dirait pas les laisserait garder la clé du compte parti.
 */
it('déclare dans chaque page le titulaire qu’un autre onglet compare au sien', function (): void {
    $compteA = historiqueCompteParti();
    $compteB = User::factory()->create(['email' => 'compte-suivant@example.org']);
    $appareil = historiqueAppareilConnecte($compteA);

    $ongletDeux = $appareil->envoyer('GET', 'https://gym.example.org/daily-journals', [], historiqueEntetesDeVisite());

    expect(historiquePageDe($ongletDeux))->toHaveKey('props.auth.user.id', $compteA->id);

    // Dans le premier onglet, A se déconnecte : sa page de connexion emporte la consigne.
    historiqueSuivreJusquALaPage($appareil, $appareil->envoyer('POST', 'https://gym.example.org/logout', [], historiqueEntetesDeVisite()));

    $apresLeDepart = historiqueSuivreJusquALaPage(
        $appareil,
        $appareil->envoyer('GET', 'https://gym.example.org/daily-journals', [], historiqueEntetesDeVisite()),
    );

    $apresLeDepart->assertOk();

    expect(historiquePageDe($apresLeDepart)['component'])->toBe('Auth/Login')
        ->and(historiquePageDe($apresLeDepart))->toHaveKey('props.auth.user', null);

    // Dans le premier onglet encore, B se connecte ; le second onglet ouvre le profil.
    $appareil->envoyer('POST', 'https://gym.example.org/login', [
        'email' => $compteB->email,
        'password' => 'password',
    ], historiqueEntetesDeVisite())->assertRedirect();
    historiqueSuivreJusquALaPage($appareil, $appareil->envoyer('GET', 'https://gym.example.org/dashboard', [], historiqueEntetesDeVisite()));

    $profilDeB = $appareil->envoyer('GET', 'https://gym.example.org/profile', [], historiqueEntetesDeVisite());

    $profilDeB->assertOk();

    expect(historiquePageDe($profilDeB))->toHaveKey('props.auth.user.id', $compteB->id);
});
