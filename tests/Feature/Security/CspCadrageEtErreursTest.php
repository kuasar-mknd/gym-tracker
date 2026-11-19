<?php

declare(strict_types=1);

use App\Models\Admin;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function (): void {
    config(['csp.enabled' => true, 'csp.nonce_enabled' => true, 'app.debug' => false]);
});

/**
 * @return list<string>
 */
function cspCadrageDirectives(string $politique): array
{
    return array_map(trim(...), explode(';', $politique));
}

it('interdit à une autre origine de cadrer l’application, le panneau et Pulse', function (string $chemin, bool $connecte): void {
    if ($connecte) {
        $superAdmin = Admin::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'admin'));
        actingAs($superAdmin->fresh() ?? $superAdmin, 'admin');
    }

    $politique = (string) get($chemin)->assertOk()->headers->get('Content-Security-Policy');

    expect(cspCadrageDirectives($politique))->toContain("frame-ancestors 'self'");
})->with([
    'application' => ['/login', false],
    'panneau' => ['/backoffice/login', false],
    'Pulse' => ['/backoffice/pulse', true],
]);

it('pose une CSP restrictive sur une réponse d’erreur rendue sans route', function (): void {
    $reponse = get('/une-adresse-que-rien-ne-sert')->assertNotFound();
    $methode = post('/up')->assertMethodNotAllowed();

    foreach ([$reponse, $methode] as $erreur) {
        $politique = (string) $erreur->headers->get('Content-Security-Policy');

        expect(cspCadrageDirectives($politique))
            ->toContain("default-src 'none'")
            ->toContain("frame-ancestors 'none'")
            ->not()->toContain("script-src 'self'");
    }
});

it('laisse à la réponse sa propre politique quand elle en porte une', function (): void {
    $politique = (string) get('/up')->assertOk()->headers->get('Content-Security-Policy');

    expect($politique)->toBe("default-src 'none'; base-uri 'none'; form-action 'none'");
});

it('ne dispense aucune route _dusk du contrôle de falsification de requête', function (): void {
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
    Route::middleware('web')->post('/_dusk/sonde', fn (): string => 'ecrit');

    post('/_dusk/sonde', [], ['Sec-Fetch-Site' => 'cross-site'])->assertStatus(419);
});

it('laisse sans politique la page de débogage de Laravel et respecte csp.enabled', function (): void {
    Route::get('/_csp-erreur-de-debogage', static fn (): never => throw new RuntimeException('boum'));

    config(['app.debug' => true]);
    expect(get('/_csp-erreur-de-debogage')->assertStatus(500)->headers->has('Content-Security-Policy'))->toBeFalse();

    config(['app.debug' => false, 'csp.enabled' => false]);
    expect(get('/chemin-inconnu-sans-csp')->assertNotFound()->headers->has('Content-Security-Policy'))->toBeFalse();
});
