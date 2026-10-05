<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Horizon;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\WorkerOctane;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withServerVariables;
use function Pest\Laravel\withSession;

/*
 * La liste des adresses autorisees etait litteralement vide :
 * `in_array($user?->email, [], true)`. Horizon tournait donc derriere une porte
 * que personne ne pouvait ouvrir — un dispositif d'observation inconsultable ne
 * vaut pas mieux que pas de dispositif (#1443).
 *
 * PHPStan le voyait, d'ailleurs : l'appel etait signale comme toujours faux, et
 * l'avertissement dormait dans le baseline. Le figer avait transforme un defaut
 * en decor.
 */
it('n’ouvre Horizon à personne quand aucune adresse n’est configurée', function (): void {
    config(['horizon.allowed_emails' => '']);

    expect(Gate::forUser(User::factory()->create())->allows('viewHorizon'))->toBeFalse();
});

it('ouvre Horizon aux adresses configurées, et à elles seules', function (): void {
    $autorise = User::factory()->create(['email' => 'ops@example.org']);
    $autre = User::factory()->create(['email' => 'quelquun@example.org']);

    config(['horizon.allowed_emails' => ' ops@example.org , second@example.org ']);

    expect(Gate::forUser($autorise)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($autre)->allows('viewHorizon'))->toBeFalse();
});

/**
 * Un visiteur non authentifie n'est pas « une adresse absente de la liste » :
 * c'est l'absence d'utilisateur, et l'ancienne ecriture la traitait par un
 * `?->` qui rendait null — compare a une liste vide, donc toujours faux par
 * accident plutot que par intention.
 */
it('n’ouvre Horizon à personne quand nul n’est authentifié', function (): void {
    config(['horizon.allowed_emails' => 'ops@example.org']);

    expect(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});

/*
 * Le lien « Horizon » du menu du panneau s'affiche au super administrateur
 * (capacité `view-outils`), mais la porte ne regardait que la garde `web` : un
 * administrateur connecté au panneau seul recevait un 403. Il entre désormais,
 * depuis une adresse que `ADMIN_ALLOWED_IPS` admet, comme pour le panneau.
 */

/**
 * Un super administrateur, relu en base comme le panneau le relirait.
 */
function horizonSuperAdministrateur(): Admin
{
    $admin = Admin::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'admin'));

    return $admin->fresh() ?? $admin;
}

function horizonGardeDuPanneau(): SessionGuard
{
    $garde = Auth::guard('admin');

    if (! $garde instanceof SessionGuard) {
        throw new LogicException('La garde du panneau tient sa connexion en session.');
    }

    return $garde;
}

/**
 * L'empreinte du mot de passe que la connexion au panneau pose en session
 * (`SessionGuard::login()`), et que `AuthenticateSession` y tient à jour.
 *
 * @return array{password_hash_admin: string}
 */
function horizonEmpreinteDuPanneau(Admin $admin): array
{
    return ['password_hash_admin' => horizonGardeDuPanneau()->hashPasswordForCookie($admin->getAuthPassword())];
}

/**
 * La session telle que la connexion du panneau la laisse : la garde `admin`
 * seule, et l'empreinte du mot de passe. La garde par défaut reste `web`, ce
 * qu'`actingAs($admin, 'admin')` changerait, en masquant le défaut.
 *
 * @return array<string, mixed>
 */
function horizonSessionDuPanneau(Admin $admin): array
{
    return [horizonGardeDuPanneau()->getName() => $admin->getAuthIdentifier(), ...horizonEmpreinteDuPanneau($admin)];
}

/**
 * La production, une liste d'adresses du panneau, et la requête qui arrive
 * d'une adresse donnée. Un compte de l'application est listé pour Horizon, qui
 * n'est pas celui du test : la voie des comptes ne doit rien ouvrir ici.
 *
 * @param  list<string>  $adressesAdmises
 */
function horizonEnProductionDepuis(string $adresse, array $adressesAdmises): void
{
    config([
        'app.admin_allowed_ips' => $adressesAdmises,
        'horizon.allowed_emails' => 'ops@example.org',
    ]);
    app()->detectEnvironment(fn (): string => 'production');
    withServerVariables(['REMOTE_ADDR' => $adresse]);
}

/**
 * L'API qu'appelle le tableau de bord, sans Redis : seules les portes comptent.
 */
function horizonSansRedis(): void
{
    test()->mock(MasterSupervisorRepository::class)->allows('all')->andReturn([]);
    test()->mock(SupervisorRepository::class)->allows('all')->andReturn([]);
}

it('ouvre Horizon et son API au super administrateur du panneau, depuis une adresse admise, sans compte de l’application', function (): void {
    horizonEnProductionDepuis('203.0.113.5', ['198.51.100.0/24', '203.0.113.5']);
    horizonSansRedis();
    withSession(horizonSessionDuPanneau(horizonSuperAdministrateur()));

    get('/horizon')->assertOk();
    get('/horizon/api/masters')->assertOk();

    expect(config('auth.defaults.guard'))->toBe('web');
});

/**
 * Une permission Shield `view-outils` accordée à un autre rôle montre le lien
 * (`AdminPanelProvider::getNavigationItems()`) : elle ouvre la porte aussi.
 */
it('ouvre Horizon à l’administrateur à qui un autre rôle donne view-outils', function (): void {
    horizonEnProductionDepuis('203.0.113.5', ['203.0.113.5']);
    $role = Role::findOrCreate('exploitation', 'admin');
    $role->givePermissionTo(Permission::findOrCreate('view-outils', 'admin'));
    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    withSession(horizonSessionDuPanneau($admin->fresh() ?? $admin));

    get('/horizon')->assertOk();
});

it('ferme Horizon et son API au super administrateur hors des adresses admises', function (): void {
    horizonEnProductionDepuis('198.51.100.7', ['203.0.113.5']);
    horizonSansRedis();
    withSession(horizonSessionDuPanneau(horizonSuperAdministrateur()));

    get('/horizon')->assertForbidden();
    get('/horizon/api/masters')->assertForbidden();
});

/**
 * En production, une liste vide ferme le panneau : elle ferme aussi la voie de
 * ses administrateurs vers Horizon, au lieu de l'ouvrir au monde.
 */
it('ferme Horizon au super administrateur en production quand aucune adresse n’est admise', function (): void {
    horizonEnProductionDepuis('203.0.113.5', []);
    withSession(horizonSessionDuPanneau(horizonSuperAdministrateur()));

    get('/horizon')->assertForbidden();
});

it('ferme Horizon à l’administrateur qui ne voit pas le lien', function (): void {
    horizonEnProductionDepuis('203.0.113.5', ['203.0.113.5']);
    $invite = Admin::factory()->create();
    $invite->assignRole(Role::findOrCreate('invite', 'admin'));
    $invite = $invite->fresh() ?? $invite;

    withSession(horizonSessionDuPanneau($invite));

    expect($invite->can('view-outils'))->toBeFalse();
    get('/horizon')->assertForbidden();
});

it('ferme Horizon à un invité, même depuis une adresse admise', function (): void {
    horizonEnProductionDepuis('203.0.113.5', ['203.0.113.5']);
    horizonSansRedis();

    get('/horizon')->assertForbidden();
    get('/horizon/api/masters')->assertForbidden();
});

/**
 * Le panneau pose l'empreinte du mot de passe à la connexion : une session qui
 * ne la porte pas n'a jamais été validée par lui. Le cas d'une empreinte
 * périmée est dans `tests/Feature/Security/SessionPerimeeDesOutilsTest.php`.
 */
it('ferme Horizon à une session du panneau qui ne porte pas l’empreinte du mot de passe', function (): void {
    horizonEnProductionDepuis('203.0.113.5', ['203.0.113.5']);
    horizonSansRedis();
    $admin = horizonSuperAdministrateur();

    withSession([horizonGardeDuPanneau()->getName() => $admin->getAuthIdentifier()]);

    get('/horizon')->assertForbidden();
    get('/horizon/api/masters')->assertForbidden();
});

/**
 * La page de profil de Filament, quand l'administrateur change son mot de
 * passe, écrit en session le hachage lui-même et non son HMAC : Horizon
 * l'admet, comme `AuthenticateSession`, sans attendre une autre page du panneau.
 */
it('ouvre Horizon juste après que la page de profil a changé le mot de passe', function (): void {
    horizonEnProductionDepuis('203.0.113.5', ['203.0.113.5']);
    $admin = horizonSuperAdministrateur();
    $admin->forceFill(['password' => 'Un-autre-mot-de-passe-2026!'])->save();

    withSession([horizonGardeDuPanneau()->getName() => $admin->getAuthIdentifier(), 'password_hash_admin' => $admin->getAuthPassword()]);

    get('/horizon')->assertOk();
});

/**
 * L'adresse n'ouvre que la voie de l'administrateur : elle n'est pas une porte
 * à elle seule, et un compte de l'application non listé reste dehors.
 */
it('n’ouvre pas Horizon à un compte de l’application non listé, même depuis une adresse admise', function (): void {
    horizonEnProductionDepuis('203.0.113.5', ['203.0.113.5']);

    actingAs(User::factory()->create(['email' => 'quelquun@example.org']))->get('/horizon')->assertForbidden();
});

/**
 * La voie des comptes listés reste ce qu'elle était : la porte `viewHorizon`,
 * sans liste d'adresses.
 */
it('garde la voie des comptes listés, hors de toute liste d’adresses', function (): void {
    horizonEnProductionDepuis('198.51.100.7', ['203.0.113.5']);

    actingAs(User::factory()->create(['email' => 'ops@example.org']))->get('/horizon')->assertOk();
});

/**
 * La liste nomme des adresses, pas des comptes : une adresse listée qu'aucun
 * compte n'occupe (jamais créé, supprimé, ou passé à une autre adresse)
 * s'inscrit par mot de passe sans qu'on en détienne la boîte. Le compte
 * n'entre qu'une fois l'adresse confirmée par le lien envoyé à celle-ci.
 */
it('n’ouvre Horizon à un compte listé qu’une fois son adresse confirmée', function (): void {
    post(route('register'), [
        'name' => 'Inconnu',
        'email' => 'ops@example.org',
        'password' => 'Un-mot-de-passe-solide-42!',
        'password_confirmation' => 'Un-mot-de-passe-solide-42!',
    ])->assertSessionHasNoErrors();

    $compte = User::query()->where('email', 'ops@example.org')->sole();
    horizonEnProductionDepuis('198.51.100.7', ['203.0.113.5']);

    expect($compte->hasVerifiedEmail())->toBeFalse()
        ->and(Gate::forUser($compte)->allows('viewHorizon'))->toBeFalse();
    actingAs($compte)->get('/horizon')->assertForbidden();

    $compte->markEmailAsVerified();

    expect(Gate::forUser($compte)->allows('viewHorizon'))->toBeTrue();
    actingAs($compte)->get('/horizon')->assertOk();
});

/**
 * Quand la garde par défaut devient celle du panneau — `actingAs(..., 'admin')`
 * dans un test, ou le middleware d'authentification de Filament posé un jour
 * devant Horizon —, la porte `viewHorizon` reçoit un `Admin`. Typée `?User`,
 * elle levait une TypeError : un 500 au lieu d'une réponse.
 */
it('répond à l’administrateur même quand la garde par défaut est celle du panneau', function (): void {
    horizonEnProductionDepuis('203.0.113.5', ['203.0.113.5']);
    $admin = horizonSuperAdministrateur();
    withSession(horizonEmpreinteDuPanneau($admin));

    actingAs($admin, 'admin')->get('/horizon')->assertOk();
    expect(Gate::forUser(horizonSuperAdministrateur())->allows('viewHorizon'))->toBeFalse();
});

/**
 * Le cas de production, rejoué : le rappel de `Horizon::auth()` est posé une
 * fois, au démarrage du worker, dans une propriété statique qu'Octane ne remet
 * jamais à zéro. Il doit juger chaque requête clonée sur elle-même : le
 * super administrateur d'une adresse admise entre, puis ni lui depuis une autre
 * adresse, ni l'invité qui le suit, puis lui de nouveau. Une liste figée au
 * démarrage, ou un administrateur gardé d'une requête à l'autre, ferait mentir
 * l'une des quatre réponses.
 *
 * L'administrateur est connecté au panneau au début de chaque requête qui
 * porte l'en-tête `X-Test-Admin`, empreinte du mot de passe comprise, à la
 * manière de la session du panneau.
 */
it('juge chaque requête d’un worker Octane avec le rappel posé à son démarrage', function (): void {
    $administrateur = horizonSuperAdministrateur();
    $rappelDuTest = Horizon::$authUsing;
    $rappels = ['demarrage' => null, 'apres' => []];

    $resultat = WorkerOctane::servir(
        ['APP_ENV' => 'production', 'ADMIN_ALLOWED_IPS' => '203.0.113.5'],
        [
            Request::create('/horizon', server: ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_TEST_ADMIN' => '1']),
            Request::create('/horizon', server: ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_TEST_ADMIN' => '1']),
            Request::create('/horizon', server: ['REMOTE_ADDR' => '203.0.113.5']),
            Request::create('/horizon', server: ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_TEST_ADMIN' => '1']),
        ],
        avantDeServir: static function (Application $base) use ($administrateur, &$rappels): void {
            $rappels['demarrage'] = Horizon::$authUsing;
            $evenements = $base->make('events');
            $evenements->listen(RequestReceived::class, static function (RequestReceived $evenement) use ($administrateur): void {
                if ($evenement->request->headers->has('X-Test-Admin')) {
                    WorkerOctane::connecterAuPanneau($evenement->sandbox, $administrateur);
                }
            });
            $evenements->listen(RequestTerminated::class, static function () use (&$rappels): void {
                $rappels['apres'][] = Horizon::$authUsing;
            });
        },
    );

    expect($resultat['environnement'])->toBe('production')
        ->and($resultat['erreurs'])->toBe([])
        ->and($resultat['statuts'])->toBe([200, 403, 403, 200])
        ->and($rappels['demarrage'])->toBeInstanceOf(Closure::class)
        ->and($rappels['demarrage'] === $rappelDuTest)->toBeFalse()
        ->and($rappels['apres'])->toBe(array_fill(0, 4, $rappels['demarrage']));
});
