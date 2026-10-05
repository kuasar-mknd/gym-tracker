<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DailyJournal;
use App\Models\User;
use App\Models\Workout;
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
 * chaque page d'un compte, `clearHistory` sur la première page qui suit la
 * déconnexion ou la suppression du compte. Le document lui-même sort en
 * `no-store`, pour que le cache HTTP ne le resserve pas à une navigation
 * arrière qui quitte la page courante.
 *
 * Ce que le navigateur fait de ces en-têtes est tenu par
 * tests/js/utils/historiqueDuCompte.test.js, avec le vrai client
 * d'Inertia, et de bout en bout par le parcours
 * `tests/Browser/HistoriqueApresDeconnexionTest.php`.
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
 * Les en-têtes d'une visite Inertia, à la version des actifs du serveur.
 *
 * @return array<string, string>
 */
function historiqueEntetesDeVisite(): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
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

it('ne demande pas d’effacer l’historique quand la suppression est refusée', function (): void {
    $compte = historiqueCompteParti();
    $appareil = historiqueAppareilConnecte($compte);

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
