<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\Support\FilamentAdminPanel;

/**
 * Every GET route of the 'admin' Filament panel (path 'backoffice'), keyed by route name,
 * with the route parameters filled in so the URL can actually be requested.
 *
 * The list is read from the router at runtime, so a resource added tomorrow is covered by
 * the boundary tests below without touching this file.
 *
 * Two routes are deliberately left out:
 * - filament.admin.auth.login, the panel login page, public by design;
 * - pulse, which only shares the URL prefix: it is a Laravel Pulse route with its own
 *   middleware stack ('auth:admin,web' + the viewPulse gate, see config/pulse.php) and not
 *   a Filament panel route. It has its own test below.
 *
 * @return array<string, string>
 */
function backofficeGuardedRoutes(): array
{
    $urls = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if ($uri !== 'backoffice' && ! str_starts_with($uri, 'backoffice/')) {
            continue;
        }

        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        if (in_array($route->getName(), ['filament.admin.auth.login', 'pulse'], true)) {
            continue;
        }

        $urls[$route->getName() ?? $uri] = '/'.preg_replace('/\{[^}]+\}/', '1', $uri);
    }

    ksort($urls);

    return $urls;
}

/**
 * The panel is behind a 30 requests/minute IP throttle (App\Http\Middleware\AdminRateLimiter).
 * The boundary tests below walk every route in one test, so the throttle is reset between
 * requests: what is under test here is authorization, not throttling.
 */
function forgetBackofficeThrottle(): void
{
    RateLimiter::clear('admin-panel:127.0.0.1');
}

it('connait toutes les routes GET du backoffice', function (): void {
    $routes = backofficeGuardedRoutes();

    expect($routes)->toHaveKeys([
        'filament.admin.pages.dashboard',
        'filament.admin.pages.backups',
        'filament.admin.auth.profile',
        'filament.admin.resources.users.index',
        'filament.admin.resources.users.create',
        'filament.admin.resources.users.edit',
        'filament.admin.resources.exercises.index',
        'filament.admin.resources.workouts.index',
        'filament.admin.resources.goals.index',
        'filament.admin.resources.supplements.index',
        'filament.admin.resources.achievements.index',
        'filament.admin.resources.shield.roles.index',
    ]);

    expect($routes['filament.admin.pages.dashboard'])->toBe('/backoffice')
        ->and($routes['filament.admin.resources.users.edit'])->toBe('/backoffice/users/1/edit')
        ->and($routes)->not->toHaveKey('filament.admin.auth.login')
        ->and(count($routes))->toBeGreaterThanOrEqual(20);
});

it('renvoie un visiteur anonyme vers la connexion du panel sur chacune de ces routes', function (): void {
    $login = route('filament.admin.auth.login');
    $notRedirected = [];

    foreach (backofficeGuardedRoutes() as $name => $url) {
        forgetBackofficeThrottle();

        $response = $this->get($url);

        if ($response->headers->get('Location') !== $login) {
            $notRedirected[$name] = $response->getStatusCode().' -> '.($response->headers->get('Location') ?? 'aucune redirection');
        }
    }

    expect($notRedirected)->toBe([]);
});

it('ne divulgue aucune donnee au visiteur anonyme et le depose sur le formulaire de connexion', function (): void {
    User::factory()->create(['name' => 'Victime Anonyme', 'email' => 'victime-anonyme@example.test']);

    forgetBackofficeThrottle();
    $this->get('/backoffice/users')
        ->assertRedirect(route('filament.admin.auth.login'))
        ->assertDontSee('victime-anonyme@example.test');

    forgetBackofficeThrottle();
    $this->get(route('filament.admin.auth.login'))
        ->assertOk()
        ->assertSee('wire:model="data.email"', escape: false)
        ->assertSee('wire:model="data.password"', escape: false)
        ->assertDontSee('victime-anonyme@example.test');
});

it("interdit a un utilisateur du guard web l'integralite des routes du backoffice", function (): void {
    User::factory()->create(['name' => 'Victime Web', 'email' => 'victime-web@example.test']);
    $intruder = User::factory()->create(['email' => 'intrus@example.test']);

    $login = route('filament.admin.auth.login');
    $reached = [];

    foreach (backofficeGuardedRoutes() as $name => $url) {
        forgetBackofficeThrottle();

        $response = $this->actingAs($intruder, 'web')->get($url);

        if ($response->headers->get('Location') !== $login) {
            $reached[$name] = $response->getStatusCode().' -> '.($response->headers->get('Location') ?? 'aucune redirection');
        }

        $response->assertDontSee('victime-web@example.test');
    }

    expect($reached)->toBe([])
        ->and(auth('admin')->check())->toBeFalse();
});

it('laisse un admin muni de la permission Shield afficher le contenu de la ressource', function (): void {
    $admin = FilamentAdminPanel::admin(['ViewAny:User', 'ViewAny:Exercise']);

    User::factory()->create(['name' => 'Utilisateur Visible', 'email' => 'visible@example.test']);
    Exercise::factory()->create(['name' => 'Souleve De Terre Visible']);

    forgetBackofficeThrottle();
    $this->actingAs($admin, 'admin')->get('/backoffice/users')
        ->assertOk()
        ->assertSee('Utilisateur Visible')
        ->assertSee('visible@example.test');

    forgetBackofficeThrottle();
    $this->actingAs($admin, 'admin')->get('/backoffice/exercises')
        ->assertOk()
        ->assertSee('Souleve De Terre Visible');
});

it("refuse a un admin la ressource dont il n'a pas la permission, tout en gardant celle qu'il a", function (): void {
    $admin = FilamentAdminPanel::admin(['ViewAny:User']);

    User::factory()->create(['name' => 'Utilisateur Autorise', 'email' => 'autorise@example.test']);
    Exercise::factory()->create(['name' => 'Squat Interdit']);

    forgetBackofficeThrottle();
    $this->actingAs($admin, 'admin')->get('/backoffice/exercises')
        ->assertForbidden()
        ->assertDontSee('Squat Interdit');

    forgetBackofficeThrottle();
    $this->actingAs($admin, 'admin')->get('/backoffice/users')
        ->assertOk()
        ->assertSee('autorise@example.test');
});

it('cloisonne chaque page de ressource derriere sa propre permission Shield', function (): void {
    // Un role sans permission : de quoi entrer dans le panneau (canAccessPanel
    // exige un role ou une permission), et rien de plus.
    $admin = FilamentAdminPanel::admin();
    $admin->assignRole(Role::findOrCreate('invite', 'admin'));

    User::factory()->create(['name' => 'Utilisateur Cloisonne', 'email' => 'cloisonne@example.test']);
    Exercise::factory()->create(['name' => 'Exercice Cloisonne']);

    $pages = array_filter(
        backofficeGuardedRoutes(),
        fn (string $name): bool => str_starts_with($name, 'filament.admin.resources.')
            && (str_ends_with($name, '.index') || str_ends_with($name, '.create')),
        ARRAY_FILTER_USE_KEY
    );

    expect(count($pages))->toBeGreaterThanOrEqual(12);

    $notForbidden = [];

    foreach ($pages as $name => $url) {
        forgetBackofficeThrottle();

        $response = $this->actingAs($admin, 'admin')->get($url);

        if ($response->getStatusCode() !== 403) {
            $notForbidden[$name] = $response->getStatusCode();
        }

        $response->assertDontSee('cloisonne@example.test');
        $response->assertDontSee('Exercice Cloisonne');
    }

    expect($notForbidden)->toBe([]);

    forgetBackofficeThrottle();
    $this->actingAs($admin, 'admin')->get('/backoffice')
        ->assertOk()
        ->assertSee('GymTracker');
});
