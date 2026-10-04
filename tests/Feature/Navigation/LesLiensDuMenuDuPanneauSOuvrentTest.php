<?php

declare(strict_types=1);

use App\Models\Admin;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\get;

/**
 * Chaque lien que le menu du panneau montre au super administrateur s'ouvre
 * pour lui, en production et sous Octane, liens vers les outils hors panneau
 * compris (journaux, Pulse, Horizon).
 *
 * Le menu montrait « Journaux » et « Horizon » au super administrateur et les
 * deux répondaient 403 en production : le lecteur de journaux perdait sous
 * Octane la porte que `AppServiceProvider` lui donnait au démarrage, dans un
 * service `scoped` qu'Octane oublie après chaque requête ; Horizon n'admettait
 * que des comptes de l'application. « Pulse Serveur » restait caché, sa porte
 * fermée hors du poste de développement. Aucun test ne le voyait :
 * l'environnement de test n'est pas « production », une porte de test toujours
 * vraie ouvrait Pulse, et le noyau de test n'oublie rien entre deux requêtes.
 *
 * La garde ne lit que le menu : un lien ajouté demain y est soumis sans toucher
 * à ce fichier.
 */

/**
 * Le super administrateur tel que le crée `AdminSeeder` (le rôle seul), ou muni
 * de toutes les permissions que `shield:generate` poserait.
 */
function liensDuMenuSuperAdministrateur(bool $toutesLesPermissions): Admin
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'admin');

    if ($toutesLesPermissions) {
        $permissions = array_values(array_unique(array_filter(FilamentShield::getEntitiesPermissions() ?? [], is_string(...))));

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'admin');
        }

        $role->givePermissionTo($permissions);
    }

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin->fresh() ?? $admin;
}

/**
 * La production, après le démarrage : l'environnement (que le lecteur de
 * journaux, la liste blanche et la CSP lisent à chaque requête), la liste
 * blanche renseignée, la CSP et son nonce, le débogage coupé.
 */
function liensDuMenuEnProduction(): void
{
    app()['env'] = 'production';
    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.admin_allowed_ips' => ['127.0.0.1'],
        'csp.enabled' => true,
        'csp.nonce_enabled' => true,
    ]);
}

/**
 * Le début d'une requête sous Octane, hors du premier passage d'un worker : les
 * instances `scoped` de la requête d'avant sont oubliées
 * (`FlushTemporaryContainerInstances`), les façades relâchent la leur
 * (`CurrentApplication::set`), et la configuration repart de celle du
 * démarrage, donc la garde par défaut redevient celle de l'application.
 * L'administrateur reste connecté sur la garde du panneau, comme par sa session.
 */
function liensDuMenuNouvelleRequeteOctane(Admin $admin): void
{
    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();
    config(['auth.defaults.guard' => 'web']);
    auth()->guard('admin')->setUser($admin);
    RateLimiter::clear('admin-panel:127.0.0.1');
}

/**
 * @return array<string, string>
 */
function liensDuMenuVisibles(): array
{
    $liens = [];

    foreach (Filament::getNavigation() as $groupe) {
        $elements = $groupe instanceof NavigationGroup ? collect($groupe->getItems())->all() : [$groupe];

        foreach ($elements as $element) {
            if ($element instanceof NavigationItem && $element->isVisible() && filled($element->getUrl())) {
                $liens[$element->getLabel()] = $element->getUrl();
            }
        }
    }

    return $liens;
}

it('ouvre au super administrateur chaque lien de son menu, en production et sous Octane', function (bool $toutesLesPermissions): void {
    $admin = liensDuMenuSuperAdministrateur($toutesLesPermissions);
    liensDuMenuEnProduction();

    liensDuMenuNouvelleRequeteOctane($admin);
    get('/backoffice')->assertOk();
    $liens = liensDuMenuVisibles();

    expect($liens)->toHaveKeys(['Journaux', 'Pulse Serveur', 'Horizon']);

    $fermes = [];

    foreach ($liens as $libelle => $url) {
        liensDuMenuNouvelleRequeteOctane($admin);
        $statut = get($url)->getStatusCode();

        if ($statut !== 200) {
            $fermes[$libelle] = $url.' → '.$statut;
        }
    }

    expect($fermes)->toBe([]);
})->with([
    'super administrateur du seeder' => false,
    'super administrateur muni de toutes les permissions Shield' => true,
]);
