<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Spatie\Permission\Models\Role;
use Tests\Support\FilamentAdminPanel;
use Tests\Support\WorkerOctane;

use function Pest\Laravel\actingAs;

/**
 * Le lecteur de journaux répondait 403 à tout le monde en production sous Octane.
 *
 * Sa porte était un rappel posé une fois au démarrage par `LogViewer::auth()`.
 * Le paquet lie son service en `scoped` : Octane oublie ces instances après
 * chaque requête, le service suivant naissait sans rappel, et
 * `AuthorizeLogViewer`, qui exige en production une porte `viewLogViewer` ou un
 * rappel, refusait. Hors production, le même middleware laissait alors passer
 * tout administrateur du panneau, sans `view-logs`.
 */

/**
 * Un super administrateur, relu en base comme le panneau le relirait.
 */
function journauxOctaneSuperAdministrateur(): Admin
{
    $superAdmin = Admin::factory()->create();
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'admin'));

    return $superAdmin->fresh() ?? $superAdmin;
}

/**
 * Fait servir les chemins donnés par un worker Octane démarré en production,
 * depuis une adresse admise, et rend les statuts dans l'ordre.
 *
 * @param  list<string>  $chemins
 * @return list<int>
 */
function journauxOctaneServir(?Admin $administrateur, array $chemins): array
{
    $resultat = WorkerOctane::servir(
        ['APP_ENV' => 'production', 'ADMIN_ALLOWED_IPS' => '203.0.113.10'],
        array_map(static fn (string $chemin): Request => Request::create($chemin, server: ['REMOTE_ADDR' => '203.0.113.10']), $chemins),
        $administrateur,
    );

    expect($resultat['environnement'])->toBe('production')
        ->and($resultat['erreurs'])->toBe([]);

    return $resultat['statuts'];
}

/**
 * Ce qu'Octane fait entre deux requêtes, réduit à ce qui compte ici : les
 * instances `scoped` résolues au démarrage sont oubliées, et la façade
 * reprendra le service du conteneur.
 */
function journauxOctaneOublierLesInstancesScoped(): void
{
    app()->forgetScopedInstances();
    Facade::clearResolvedInstance('log-viewer');
}

/**
 * La production, pour le lecteur et pour la liste blanche du panneau.
 */
function journauxOctaneSimulerLaProduction(): void
{
    app()->detectEnvironment(static fn (): string => 'production');
    config([
        'app.env' => 'production',
        'app.admin_allowed_ips' => ['127.0.0.1'],
    ]);
}

it('ouvre le lecteur au super administrateur à chaque requête d’un worker Octane en production, page et API', function (): void {
    expect(journauxOctaneServir(journauxOctaneSuperAdministrateur(), [
        '/backoffice/journaux',
        '/backoffice/journaux',
        '/backoffice/journaux/api/folders',
    ]))->toBe([200, 200, 200]);
});

it('refuse le lecteur sous Octane à un administrateur sans view-logs, et renvoie un invité à la connexion', function (): void {
    $administrateur = FilamentAdminPanel::admin(['ViewAny:User']);

    expect(journauxOctaneServir($administrateur, ['/backoffice/journaux', '/backoffice/journaux/api/folders']))->toBe([403, 403])
        ->and(journauxOctaneServir(null, ['/backoffice/journaux', '/backoffice/journaux/api/folders']))->toBe([302, 302]);
});

it('ouvre le lecteur au super administrateur en production après l’oubli des instances scoped', function (string $chemin): void {
    $superAdmin = journauxOctaneSuperAdministrateur();
    journauxOctaneSimulerLaProduction();
    journauxOctaneOublierLesInstancesScoped();

    actingAs($superAdmin, 'admin')->get($chemin)->assertOk();
})->with(['/backoffice/journaux', '/backoffice/journaux/api/folders']);

it('refuse le lecteur à un administrateur sans view-logs après l’oubli des instances scoped, en production comme ailleurs', function (bool $production, string $chemin): void {
    $administrateur = FilamentAdminPanel::admin(['ViewAny:User']);

    if ($production) {
        journauxOctaneSimulerLaProduction();
    }

    journauxOctaneOublierLesInstancesScoped();

    actingAs($administrateur, 'admin')->get($chemin)->assertForbidden();
})->with([
    'en production' => true,
    'hors production' => false,
])->with(['/backoffice/journaux', '/backoffice/journaux/api/folders']);

/**
 * `AuthorizeLogViewer` évalue la porte pour l'utilisateur de la garde par
 * défaut : `Filament\Http\Middleware\Authenticate` la règle sur celle du
 * panneau, si bien qu'un compte de l'application connecté à côté n'y change rien.
 */
it('évalue la porte pour l’administrateur du panneau, pas pour l’utilisateur de l’application connecté à côté', function (bool $superAdministrateur, int $statut): void {
    $administrateur = $superAdministrateur ? journauxOctaneSuperAdministrateur() : FilamentAdminPanel::admin(['ViewAny:User']);
    $utilisateur = User::factory()->create();
    journauxOctaneSimulerLaProduction();
    journauxOctaneOublierLesInstancesScoped();

    actingAs($utilisateur, 'web');
    actingAs($administrateur, 'admin')->get('/backoffice/journaux/api/folders')->assertStatus($statut);

    expect(Auth::getDefaultDriver())->toBe('admin');
})->with([
    'super administrateur' => [true, 200],
    'administrateur sans view-logs' => [false, 403],
]);

/**
 * La capacité suit Shield aussi : une permission `view-logs` accordée à un autre
 * rôle que le super administrateur ouvre le lecteur.
 */
it('ouvre le lecteur à un administrateur qui tient la permission view-logs, en production après l’oubli des instances scoped', function (): void {
    $administrateur = FilamentAdminPanel::admin(['view-logs']);
    journauxOctaneSimulerLaProduction();
    journauxOctaneOublierLesInstancesScoped();

    actingAs($administrateur, 'admin')->get('/backoffice/journaux/api/folders')->assertOk();
});
