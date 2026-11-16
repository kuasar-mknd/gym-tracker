<?php

declare(strict_types=1);

use App\Http\Middleware\AdminRateLimiter;
use App\Http\Middleware\IpWhitelist;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;

use function Livewire\trigger;

/**
 * La connexion du panneau et toutes ses actions passent par
 * `POST /livewire-{empreinte}/update` (#2352). Cette route n'appartient à aucun groupe du
 * panneau : seuls ses middleware déclarés persistants y sont rejoués, sur la
 * page d'origine du composant. La liste blanche et le limiteur y tenaient par
 * effet de bord de Pulse ; ces tests les prennent à la requête Livewire elle-même.
 */
const ADRESSE_ADMISE_DU_PANNEAU = '203.0.113.5';
const ADRESSE_HORS_LISTE_DU_PANNEAU = '198.51.100.7';

/**
 * L'instantané d'un composant de la page de connexion, tel que le navigateur le renvoie.
 */
function instantaneDeLaConnexionDuPanneau(): string
{
    /** @var string $page */
    $page = test()->withServerVariables(['REMOTE_ADDR' => ADRESSE_ADMISE_DU_PANNEAU])
        ->get('/backoffice/login')
        ->assertOk()
        ->getContent();

    expect(preg_match('/wire:snapshot="([^"]+)"/', $page, $trouvaille))->toBe(1);

    return html_entity_decode($trouvaille[1] ?? '', ENT_QUOTES);
}

/**
 * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function miseAJourLivewireDepuis(string $adresse, string $instantane): Illuminate\Testing\TestResponse
{
    // Octane vide l'etat de Livewire entre deux requetes
    // (PrepareLivewireForNextOperation) ; le noyau de test garde le sien, et
    // sauterait le middleware pour la meme page d'origine.
    trigger('flush-state');

    /** @var \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $reponse */
    $reponse = test()->withSession(['_token' => 'jeton'])->withServerVariables(['REMOTE_ADDR' => $adresse])->postJson(
        route('default-livewire.update', absolute: false),
        ['components' => [['snapshot' => $instantane, 'updates' => [], 'calls' => []]]],
        ['X-Livewire' => 'true', 'X-CSRF-TOKEN' => 'jeton'],
    );

    return $reponse;
}

beforeEach(function (): void {
    RateLimiter::clear('admin-panel:'.ADRESSE_ADMISE_DU_PANNEAU);
    RateLimiter::clear('admin-panel:'.ADRESSE_HORS_LISTE_DU_PANNEAU);
    config(['app.admin_allowed_ips' => [ADRESSE_ADMISE_DU_PANNEAU]]);
});

it('repond 404 a une mise a jour Livewire du panneau venue d une adresse hors liste', function (): void {
    $instantane = instantaneDeLaConnexionDuPanneau();
    $this->app->detectEnvironment(fn (): string => 'production');

    miseAJourLivewireDepuis(ADRESSE_HORS_LISTE_DU_PANNEAU, $instantane)->assertNotFound();
});

it('laisse passer une mise a jour Livewire du panneau venue d une adresse de la liste', function (): void {
    $instantane = instantaneDeLaConnexionDuPanneau();
    $this->app->detectEnvironment(fn (): string => 'production');

    miseAJourLivewireDepuis(ADRESSE_ADMISE_DU_PANNEAU, $instantane)->assertOk();
});

it('limite les mises a jour Livewire du panneau a cent vingt par minute et par adresse', function (): void {
    $instantane = instantaneDeLaConnexionDuPanneau();

    foreach (range(1, 119) as $_) {
        miseAJourLivewireDepuis(ADRESSE_ADMISE_DU_PANNEAU, $instantane)->assertOk();
    }

    miseAJourLivewireDepuis(ADRESSE_ADMISE_DU_PANNEAU, $instantane)->assertStatus(429);
});

it('laisse la liste blanche vide ouverte hors production sur les mises a jour Livewire', function (): void {
    config(['app.admin_allowed_ips' => []]);
    $instantane = instantaneDeLaConnexionDuPanneau();

    miseAJourLivewireDepuis(ADRESSE_HORS_LISTE_DU_PANNEAU, $instantane)->assertOk();
});

it('declare la liste blanche et le limiteur persistants pour Livewire', function (): void {
    expect(app(PersistentMiddleware::class)->getPersistentMiddleware())
        ->toContain(AdminRateLimiter::class, IpWhitelist::class);
});

it('porte lui-meme la liste blanche et le limiteur, sans l aide de Pulse', function (): void {
    $persistants = app(PersistentMiddleware::class);
    /** @var array<int, string> $avant */
    $avant = $persistants->getPersistentMiddleware();
    $persistants->setPersistentMiddleware(array_values(array_diff($avant, [IpWhitelist::class, AdminRateLimiter::class])));

    try {
        new AdminPanelProvider(app())->panel(Panel::make())->register();

        expect($persistants->getPersistentMiddleware())->toContain(AdminRateLimiter::class, IpWhitelist::class);
    } finally {
        $persistants->setPersistentMiddleware($avant);
    }
});
