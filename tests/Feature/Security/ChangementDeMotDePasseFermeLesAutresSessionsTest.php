<?php

declare(strict_types=1);

use App\Filament\Resources\Users\Pages\EditUser;
use App\Http\Middleware\AuthentifieLaSessionDuCompte;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as UtilisateurSocial;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Appareil;
use Tests\Support\FilamentAdminPanel;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutMiddleware;

/**
 * Changer son mot de passe ferme les autres sessions du compte (#1940).
 *
 * Le panneau le faisait déjà, par Filament. Le reste de l'application non : le
 * groupe `web` ne comparait pas l'empreinte du mot de passe que la session
 * porte au mot de passe actuel, et une session volée (un cookie copié sur un
 * appareil partagé ou perdu) survivait au changement du mot de passe, qui est
 * justement le geste de la personne qui s'en inquiète. Si le compte figurait
 * dans `HORIZON_ALLOWED_EMAILS`, elle ouvrait aussi Horizon.
 *
 * Ce sont de vraies sessions, ouvertes par le formulaire de connexion : chaque
 * appareil garde ses cookies, et chaque requête part d'un état remis à zéro
 * comme sous Octane (`Tests\Support\Appareil`).
 */

/**
 * Le mot de passe que le compte reçoit, du profil ou par le courriel.
 */
function sessionsNouveauMotDePasse(): string
{
    return 'Un-nouveau-mot-de-passe-2026!';
}

/**
 * La garde des comptes de l'application, celle que la session tient.
 */
function sessionsGardeDesComptes(): SessionGuard
{
    $garde = Auth::guard(AuthentifieLaSessionDuCompte::GARDE);

    if (! $garde instanceof SessionGuard) {
        throw new LogicException('La garde des comptes tient sa connexion en session.');
    }

    return $garde;
}

/**
 * Un appareil connecté au compte par le formulaire de connexion, « se souvenir
 * de moi » coché : la session porte l'empreinte du mot de passe, que la
 * connexion y pose, et le cookie de rappel une copie.
 */
function sessionsAppareilConnecte(User $utilisateur, string $motDePasse = 'password'): Appareil
{
    $appareil = new Appareil();

    $appareil->envoyer('POST', '/login', [
        'email' => $utilisateur->email,
        'password' => $motDePasse,
        'remember' => '1',
    ])->assertRedirect(route('dashboard'));

    return $appareil;
}

/**
 * @return TestResponse<Response>
 */
function sessionsChangerLeMotDePasseDepuis(Appareil $appareil): TestResponse
{
    return $appareil->envoyer('PUT', '/password', [
        'current_password' => 'password',
        'password' => sessionsNouveauMotDePasse(),
        'password_confirmation' => sessionsNouveauMotDePasse(),
    ], ['Referer' => url('/profile')])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');
}

/**
 * La réinitialisation par courriel, depuis un appareil où personne n'est
 * connecté, avec le lien que le courriel porte.
 */
function sessionsReinitialiserLeMotDePasse(User $utilisateur): void
{
    new Appareil()->envoyer('POST', '/reset-password', [
        'token' => Password::createToken($utilisateur),
        'email' => $utilisateur->email,
        'password' => sessionsNouveauMotDePasse(),
        'password_confirmation' => sessionsNouveauMotDePasse(),
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));
}

/**
 * @return TestResponse<Response>
 */
function sessionsOuvrirLAccueil(Appareil $appareil): TestResponse
{
    return $appareil->envoyer('GET', '/dashboard');
}

/**
 * Une écriture de la page de séance par l'API, comme `SyncService` l'envoie :
 * en JSON, depuis une page de l'application. Le `Referer` en fait une requête
 * de l'interface, que Sanctum authentifie par la session.
 *
 * @return TestResponse<Response>
 */
function sessionsSupprimerUneSerieParLApi(Appareil $appareil, User $utilisateur): TestResponse
{
    $seance = Workout::factory()->create(['user_id' => $utilisateur->id]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id]);
    $serie = Set::factory()->create(['workout_line_id' => $ligne->id]);

    return $appareil->envoyer('DELETE', route('api.v1.sets.destroy', $serie, absolute: false), [], [
        'Accept' => 'application/json',
        'Referer' => url('/workouts/'.$seance->id),
    ]);
}

/**
 * Les en-têtes d'une visite Inertia, à la version d'actifs que le serveur sert.
 *
 * @return array<string, string>
 */
function sessionsEntetesInertia(string $version): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ];
}

it('ferme les autres sessions quand le mot de passe change depuis le profil, et garde celle qui l’a changé', function (): void {
    $utilisateur = User::factory()->create();
    $cetAppareil = sessionsAppareilConnecte($utilisateur);
    $unAutre = sessionsAppareilConnecte($utilisateur);
    $unTroisieme = sessionsAppareilConnecte($utilisateur);

    sessionsChangerLeMotDePasseDepuis($cetAppareil);

    sessionsOuvrirLAccueil($unAutre)->assertRedirect(route('login'));
    sessionsSupprimerUneSerieParLApi($unTroisieme, $utilisateur)->assertUnauthorized();

    sessionsOuvrirLAccueil($cetAppareil)->assertOk();
    sessionsSupprimerUneSerieParLApi($cetAppareil, $utilisateur)->assertNoContent();
});

/**
 * Une session rouverte par le cookie « se souvenir de moi » ne porte pas
 * d'empreinte : la garde ne l'y pose qu'à la connexion. Le `AuthenticateSession`
 * de Sanctum laisse passer une session sans empreinte ; c'est le groupe `web`
 * qui la pose, à la première page, et l'API la vérifie ensuite.
 */
it('ferme aussi la session qu’un cookie « se souvenir de moi » a rouverte, API comprise', function (): void {
    $utilisateur = User::factory()->create();
    $cetAppareil = sessionsAppareilConnecte($utilisateur);
    $unAutre = sessionsAppareilConnecte($utilisateur);

    // Des heures plus tard : sa session a expiré, le cookie de rappel la rouvre.
    $unAutre->oublierLeCookie(config()->string('session.cookie'));
    sessionsOuvrirLAccueil($unAutre)->assertOk();

    sessionsChangerLeMotDePasseDepuis($cetAppareil);

    sessionsSupprimerUneSerieParLApi($unAutre, $utilisateur)->assertUnauthorized();
    sessionsOuvrirLAccueil($unAutre)->assertRedirect(route('login'));
});

/**
 * Le cookie copié sur l'appareil même où l'on change le mot de passe ouvre la
 * même session : l'empreinte y est mise à jour, et `AuthenticateSession` seul
 * ne la fermerait pas. Le contrôleur change donc d'identifiant de session.
 */
it('ferme aussi la copie du cookie de la session qui a changé le mot de passe', function (): void {
    $utilisateur = User::factory()->create();
    $cetAppareil = sessionsAppareilConnecte($utilisateur);
    $saCopie = $cetAppareil->copie();

    sessionsChangerLeMotDePasseDepuis($cetAppareil);

    sessionsOuvrirLAccueil($saCopie)->assertRedirect(route('login'));
    sessionsOuvrirLAccueil($cetAppareil)->assertOk();
});

it('garde « se souvenir de moi » sur l’appareil qui a changé le mot de passe, et seulement sur lui', function (): void {
    $utilisateur = User::factory()->create();
    $cetAppareil = sessionsAppareilConnecte($utilisateur);
    $unAutre = sessionsAppareilConnecte($utilisateur);

    sessionsChangerLeMotDePasseDepuis($cetAppareil);

    // Des heures plus tard, les deux sessions ont expiré : reste le cookie de rappel.
    $cetAppareil->oublierLeCookie(config()->string('session.cookie'));
    $unAutre->oublierLeCookie(config()->string('session.cookie'));

    sessionsOuvrirLAccueil($cetAppareil)->assertOk();
    sessionsOuvrirLAccueil($unAutre)->assertRedirect(route('login'));
});

/**
 * Le contrôleur écrit lui-même l'empreinte du nouveau mot de passe dans la
 * session qui l'a changé, sans compter sur le middleware : celui-ci ne la
 * réécrit qu'après la réponse, et seulement sur les routes qu'il couvre.
 */
it('renouvelle lui-même l’empreinte de la session qui change le mot de passe', function (): void {
    withoutMiddleware(AuthentifieLaSessionDuCompte::class);
    $utilisateur = User::factory()->create();

    actingAs($utilisateur)->put('/password', [
        'current_password' => 'password',
        'password' => sessionsNouveauMotDePasse(),
        'password_confirmation' => sessionsNouveauMotDePasse(),
    ])->assertSessionHasNoErrors();

    $motDePasse = (string) $utilisateur->fresh()?->getAuthPassword();

    expect(session('password_hash_web'))->toBe(sessionsGardeDesComptes()->hashPasswordForCookie($motDePasse));
});

it('ferme toutes les sessions quand le mot de passe est réinitialisé par courriel', function (): void {
    $utilisateur = User::factory()->create();
    $unAppareil = sessionsAppareilConnecte($utilisateur);
    $unAutre = sessionsAppareilConnecte($utilisateur);

    sessionsReinitialiserLeMotDePasse($utilisateur);

    sessionsOuvrirLAccueil($unAppareil)->assertRedirect(route('login'));
    sessionsSupprimerUneSerieParLApi($unAutre, $utilisateur)->assertUnauthorized();

    $apresLaReinitialisation = sessionsAppareilConnecte($utilisateur, sessionsNouveauMotDePasse());

    sessionsOuvrirLAccueil($apresLaReinitialisation)->assertOk();
});

/**
 * Le middleware ne connaît pas le chemin qui a changé le mot de passe : un
 * administrateur qui en donne un nouveau au compte depuis le panneau ferme de
 * même toutes ses sessions.
 */
it('ferme toutes les sessions quand le panneau donne un nouveau mot de passe au compte', function (): void {
    Model::preventSilentlyDiscardingAttributes(false);
    $utilisateur = User::factory()->create();
    $unAppareil = sessionsAppareilConnecte($utilisateur);
    $unAutre = sessionsAppareilConnecte($utilisateur);

    actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('User')), 'admin');
    Livewire::test(EditUser::class, ['record' => $utilisateur->getKey()])
        ->fillForm(['password' => sessionsNouveauMotDePasse()])
        ->call('save')
        ->assertHasNoFormErrors();

    sessionsOuvrirLAccueil($unAppareil)->assertRedirect(route('login'));
    sessionsSupprimerUneSerieParLApi($unAutre, $utilisateur)->assertUnauthorized();
});

/**
 * Une session refusée lève la même exception qu'une session expirée, avant
 * `HandleInertiaRequests` : la visite Inertia reçoit le renvoi vers la
 * connexion, le suit, et la page de connexion reste ouverte. Le cookie de
 * rappel est effacé dans la même réponse : sans quoi il reconnecterait, et
 * `/login` renverrait vers l'accueil, qui renverrait vers `/login`. Un
 * formulaire envoyé en PATCH reçoit un 303 (`EnsureGetOnRedirect`, qu'Inertia
 * pose sur la pile globale), que le navigateur suit en GET : un 302 lui ferait
 * rejouer le PATCH sur `/login`, qui n'accepte que GET et POST.
 */
it('mène la visite Inertia d’une session fermée à la connexion, sans boucle', function (): void {
    $utilisateur = User::factory()->create();
    $cetAppareil = sessionsAppareilConnecte($utilisateur);
    $unAutre = sessionsAppareilConnecte($utilisateur);
    $unTroisieme = sessionsAppareilConnecte($utilisateur);
    $nomDuCookieDeRappel = sessionsGardeDesComptes()->getRecallerName();

    // La version d'actifs que sert le serveur, lue sur une visite plutôt que devinée.
    $version = (string) $cetAppareil->envoyer('GET', '/dashboard', [], ['X-Inertia' => 'true'])
        ->headers->get('X-Inertia-Version');

    expect($version)->not->toBe('')
        ->and($unAutre->cookie($nomDuCookieDeRappel))->not->toBeNull();

    sessionsChangerLeMotDePasseDepuis($cetAppareil);

    $unAutre->envoyer('GET', '/dashboard', [], sessionsEntetesInertia($version))
        ->assertStatus(302)
        ->assertRedirect(route('login'));

    expect($unAutre->cookie($nomDuCookieDeRappel))->toBeNull();

    $unAutre->envoyer('GET', '/login', [], sessionsEntetesInertia($version))
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'Auth/Login');

    $unAutre->envoyer('GET', '/login', [], sessionsEntetesInertia($version))->assertOk();

    $unTroisieme->envoyer('PATCH', route('profile.update', absolute: false), ['name' => 'Autre nom'], sessionsEntetesInertia($version))
        ->assertStatus(303)
        ->assertRedirect(route('login'));

    expect($utilisateur->fresh()?->name)->toBe($utilisateur->name);
});

/**
 * La voie des comptes listés dans `HORIZON_ALLOWED_EMAILS` passe par le groupe
 * `web` : elle hérite de la vérification, sans autre changement.
 */
it('ferme Horizon à la session fermée d’un compte listé', function (): void {
    config(['horizon.allowed_emails' => 'ops@example.org']);
    test()->mock(MasterSupervisorRepository::class)->allows('all')->andReturn([]);
    test()->mock(SupervisorRepository::class)->allows('all')->andReturn([]);

    $utilisateur = User::factory()->create(['email' => 'ops@example.org']);
    $cetAppareil = sessionsAppareilConnecte($utilisateur);
    $unAutre = sessionsAppareilConnecte($utilisateur);
    $unTroisieme = sessionsAppareilConnecte($utilisateur);

    $unAutre->envoyer('GET', '/horizon')->assertOk();

    sessionsChangerLeMotDePasseDepuis($cetAppareil);

    $unAutre->envoyer('GET', '/horizon')->assertRedirect(route('login'));
    $unTroisieme->envoyer('GET', '/horizon/api/masters', [], ['Accept' => 'application/json'])->assertUnauthorized();

    $cetAppareil->envoyer('GET', '/horizon')->assertOk();
    $cetAppareil->envoyer('GET', '/horizon/api/masters', [], ['Accept' => 'application/json'])->assertOk();
});

/**
 * La connexion sociale passe par `Auth::login()`, qui pose l'empreinte comme
 * le formulaire : la session tient tant que le mot de passe ne change pas.
 */
it('ferme aussi une session ouverte par la connexion sociale', function (): void {
    $utilisateur = User::factory()->create([
        'email' => 'sociale@example.org',
        'provider' => 'github',
        'provider_id' => '4242',
    ]);
    $utilisateurSocial = new UtilisateurSocial()
        ->setRaw(['email_verified' => true])
        ->map(['id' => '4242', 'email' => 'sociale@example.org', 'name' => 'Sociale', 'nickname' => 'sociale', 'avatar' => null]);

    Socialite::shouldReceive('driver')->with('github')->andReturn(new readonly class($utilisateurSocial) implements Provider
    {
        public function __construct(private UtilisateurSocial $utilisateur)
        {
        }

        public function redirect(): never
        {
            throw new LogicException('Ce test n’emprunte pas la redirection.');
        }

        public function user(): UtilisateurSocial
        {
            return $this->utilisateur;
        }
    });

    $parLeFournisseur = new Appareil();
    $parLeFournisseur->envoyer('GET', route('social.callback', 'github', absolute: false))
        ->assertRedirect(route('dashboard'));

    sessionsOuvrirLAccueil($parLeFournisseur)->assertOk();
    sessionsOuvrirLAccueil($parLeFournisseur)->assertOk();

    sessionsReinitialiserLeMotDePasse($utilisateur);

    sessionsOuvrirLAccueil($parLeFournisseur)->assertRedirect(route('login'));
});
