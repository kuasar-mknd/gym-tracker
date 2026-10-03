<?php

declare(strict_types=1);

use App\Models\Admin;
use PHPUnit\Framework\Assert;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Les outils d'administration servis hors de l'application Vue tiennent sous
 * la même Content-Security-Policy qu'elle.
 *
 * Le panneau Filament avait sa propre pile, sans `ConditionalCspHeaders` : il
 * répondait sans aucune CSP, et le nonce de ses scripts ne protégeait rien
 * (#1920). Le lecteur de journaux, lui, recevait bien la CSP du groupe `web`,
 * mais servait un script en ligne sans nonce, que cette CSP bloquait : la page
 * restait vide (#1922). Les scripts en ligne de ces paquets sont signés à la
 * compilation de leurs gabarits (`SigneLesScriptsEnLigneDesPaquets`).
 */
beforeEach(function (): void {
    config([
        'csp.enabled' => true,
        'csp.nonce_enabled' => true,
        'app.debug' => false,
    ]);
});

/**
 * Le nonce que la Content-Security-Policy de la réponse autorise.
 */
function cspOutilsNonceDeLEnTete(Response $reponse): string
{
    $politique = (string) $reponse->headers->get('Content-Security-Policy');

    if (preg_match("/'nonce-([^']+)'/", $politique, $trouve) !== 1) {
        Assert::fail('Aucun nonce dans la Content-Security-Policy : '.($politique === '' ? '(en-tête absent)' : $politique));
    }

    return $trouve[1];
}

/**
 * Tous les nonces distincts que les balises du corps portent.
 *
 * @return list<string>
 */
function cspOutilsNoncesDuCorps(Response $reponse): array
{
    preg_match_all('/\snonce="([^"]+)"/', (string) $reponse->getContent(), $trouves);

    return array_values(array_unique($trouves[1]));
}

/**
 * Les balises `<script>` en ligne du corps qui ne portent pas de nonce : le
 * navigateur les bloque sous la CSP de production, où `'unsafe-inline'` n'est
 * pas permis.
 *
 * @return list<string>
 */
function cspOutilsScriptsEnLigneSansNonce(Response $reponse): array
{
    preg_match_all('/<script\b(?![^>]*\b(?:src|nonce)=)[^>]*>/i', (string) $reponse->getContent(), $trouves);

    return $trouves[0];
}

/**
 * Un super administrateur du panneau, celui qui ouvre aussi les journaux.
 *
 * Relu en base, comme le panneau le relirait : la fabrique ne remplit pas
 * `app_authentication_secret`, que la page de profil lit, et le mode strict des
 * modèles refuserait l'attribut absent.
 */
function cspOutilsSuperAdministrateur(): Admin
{
    $superAdmin = Admin::factory()->create();
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'admin'));

    return $superAdmin->fresh() ?? $superAdmin;
}

it('pose une CSP sur la connexion du panneau, au nonce de ses scripts', function (): void {
    $reponse = get('/backoffice/login')->assertOk()->baseResponse;

    expect(cspOutilsNoncesDuCorps($reponse))->toBe([cspOutilsNonceDeLEnTete($reponse)])
        ->and(cspOutilsScriptsEnLigneSansNonce($reponse))->toBe([]);
});

/**
 * Filament écrit dans ses gabarits des scripts en ligne sans nonce : le thème
 * forcé, l'état replié des groupes du menu, l'alerte de modifications non
 * enregistrées. Sous la CSP, le navigateur les bloquait et le menu levait
 * « Cannot read properties of null (reading 'includes') ».
 */
it('signe chaque script en ligne des pages du panneau ouvertes à un administrateur', function (string $chemin): void {
    $reponse = actingAs(cspOutilsSuperAdministrateur(), 'admin')->get($chemin)->assertOk()->baseResponse;

    expect(cspOutilsNoncesDuCorps($reponse))->toBe([cspOutilsNonceDeLEnTete($reponse)])
        ->and(cspOutilsScriptsEnLigneSansNonce($reponse))->toBe([]);
})->with(['/backoffice', '/backoffice/profile']);

it('signe le script en ligne du lecteur de journaux du nonce de son en-tête', function (): void {
    $reponse = actingAs(cspOutilsSuperAdministrateur(), 'admin')->get('/backoffice/journaux')->assertOk()->baseResponse;
    $corps = (string) $reponse->getContent();

    expect($corps)->toContain('<script nonce="'.cspOutilsNonceDeLEnTete($reponse).'">')
        ->and(cspOutilsScriptsEnLigneSansNonce($reponse))->toBe([])
        ->and(cspOutilsNoncesDuCorps($reponse))->toBe([cspOutilsNonceDeLEnTete($reponse)]);
});
