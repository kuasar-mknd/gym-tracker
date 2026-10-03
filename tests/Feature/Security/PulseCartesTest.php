<?php

declare(strict_types=1);

use App\Models\Admin;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * Les cartes de Pulse se chargent après la page, par des requêtes Livewire
 * (`__lazyLoad`, puis `wire:poll` toutes les cinq secondes). Ces requêtes ne
 * passent pas par la route de Pulse mais par celle de Livewire : seuls les
 * middleware que Pulse déclare persistants (`config/pulse.php`) y sont rejoués,
 * d'après la route d'origine notée dans l'instantané du composant.
 */
function pulseCartesSuperAdministrateur(): Admin
{
    $superAdministrateur = Admin::factory()->create();
    $superAdministrateur->assignRole(Role::findOrCreate('super_admin', 'admin'));

    return $superAdministrateur->fresh() ?? $superAdministrateur;
}

/**
 * L'instantané et le paramètre de chargement de la première carte paresseuse
 * de la page, tels que le navigateur les renverrait.
 *
 * @return array{instantane: string, parametre: string}
 */
function pulseCartesPremiereCarte(string $corps): array
{
    if (preg_match('/wire:snapshot="([^"]*)"[^>]*x-intersect="\$wire\.__lazyLoad\(&#039;([^&]+)&#039;\)"/', $corps, $trouve) !== 1) {
        throw new RuntimeException('Aucune carte paresseuse dans la page de Pulse.');
    }

    return ['instantane' => html_entity_decode($trouve[1], ENT_QUOTES), 'parametre' => $trouve[2]];
}

/**
 * @param  array{instantane: string, parametre: string}  $carte
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function pulseCartesCharger(array $carte): TestResponse
{
    $adresse = app(HandleRequests::class)->getUpdateUri();

    if (! is_string($adresse)) {
        throw new RuntimeException('Livewire ne donne pas l’adresse de ses mises à jour.');
    }

    return postJson($adresse, [
        'components' => [[
            'snapshot' => $carte['instantane'],
            'updates' => [],
            'calls' => [['method' => '__lazyLoad', 'params' => [$carte['parametre']], 'metadata' => []]],
        ]],
    ], ['X-Livewire' => '1']);
}

it('charge une carte de Pulse pour le super administrateur', function (): void {
    $superAdministrateur = pulseCartesSuperAdministrateur();
    $carte = pulseCartesPremiereCarte((string) actingAs($superAdministrateur, 'admin')->get('/backoffice/pulse')->assertOk()->getContent());

    $reponse = pulseCartesCharger($carte)->assertOk();

    expect($reponse->json('components.0.effects.html'))->toBeString()->toContain('wire:name="pulse.servers"', 'wire:poll.5s');
});

it('refuse de charger une carte à l’administrateur qui a perdu les outils depuis l’ouverture de la page', function (): void {
    $superAdministrateur = pulseCartesSuperAdministrateur();
    $carte = pulseCartesPremiereCarte((string) actingAs($superAdministrateur, 'admin')->get('/backoffice/pulse')->assertOk()->getContent());

    $superAdministrateur->removeRole('super_admin');
    $superAdministrateur->unsetRelation('roles');

    pulseCartesCharger($carte)->assertForbidden();
});

it('refuse de charger une carte depuis une adresse hors de la liste blanche', function (): void {
    $carte = pulseCartesPremiereCarte((string) actingAs(pulseCartesSuperAdministrateur(), 'admin')->get('/backoffice/pulse')->assertOk()->getContent());

    config(['app.admin_allowed_ips' => ['203.0.113.7']]);

    pulseCartesCharger($carte)->assertForbidden();
});

it('refuse de charger une carte une fois l’administrateur déconnecté', function (): void {
    $carte = pulseCartesPremiereCarte((string) actingAs(pulseCartesSuperAdministrateur(), 'admin')->get('/backoffice/pulse')->assertOk()->getContent());

    auth('admin')->logout();

    pulseCartesCharger($carte)->assertUnauthorized();
});
