<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\ExceptionEnregistree;
use BezhanSalleh\FilamentExceptions\Resources\ExceptionResource;
use Illuminate\Support\Facades\Route;
use Opcodes\LogViewer\Facades\LogViewer;
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
 * compilation de leurs gabarits (`SigneLesScriptsEnLigneDesPaquets`), y compris
 * ceux qu'un gabarit recopie depuis du PHP (filament-exceptions, Pulse).
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
    preg_match_all('/\snonce="([^"]+)"/', cspOutilsBalisesSansContenu($reponse), $trouves);

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
    preg_match_all('/<script\b(?![^>]*\b(?:src|nonce)=)[^>]*>/i', cspOutilsBalisesSansContenu($reponse), $trouves);

    return $trouves[0];
}

/**
 * Les balises `<style>` du corps qui ne portent pas de nonce.
 *
 * @return list<string>
 */
function cspOutilsStylesEnLigneSansNonce(Response $reponse): array
{
    preg_match_all('/<style\b(?![^>]*\bnonce=)[^>]*>/i', cspOutilsBalisesSansContenu($reponse), $trouves);

    return $trouves[0];
}

/**
 * Le corps, chaque script et chaque style vidé de son contenu : un script
 * recopié dans la page (livewire.js chez Pulse) contient lui-même le texte
 * `<script>`, que la recherche des balises prendrait pour une balise. Le
 * contenu s'arrête au premier `</script>`, comme pour le navigateur.
 */
function cspOutilsBalisesSansContenu(Response $reponse): string
{
    return (string) preg_replace('#(<(script|style)\b[^>]*>).*?</\2>#is', '$1</$2>', (string) $reponse->getContent());
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

/**
 * Le détail d'une exception colore la pile par `window.highlight`, que
 * filament-exceptions définit dans un `<script type="module">` en ligne. Ce
 * script n'est pas écrit dans le gabarit mais par du PHP
 * (`FilamentExceptions::renderJs()`), que le gabarit recopie brut : le
 * précompilateur ne voyait que l'appel, la CSP bloquait le script, et chaque
 * bloc de code levait « window.highlight is not a function ».
 */
it('signe le script de coloration du détail d’une exception', function (): void {
    Route::get('/_csp-outils-boum', function (): never {
        throw new RuntimeException('boum');
    });
    get('/_csp-outils-boum')->assertStatus(500);
    $exception = ExceptionEnregistree::query()->sole();

    $reponse = actingAs(cspOutilsSuperAdministrateur(), 'admin')
        ->get(ExceptionResource::getUrl('view', ['record' => $exception], panel: 'admin'))
        ->assertOk()
        ->baseResponse;

    expect((string) $reponse->getContent())->toContain('<script type="module" nonce="'.cspOutilsNonceDeLEnTete($reponse).'">')
        ->and(cspOutilsScriptsEnLigneSansNonce($reponse))->toBe([])
        ->and(cspOutilsNoncesDuCorps($reponse))->toBe([cspOutilsNonceDeLEnTete($reponse)]);
});

/**
 * Pulse recopie livewire.js et pulse.js dans la page depuis son code
 * (`Pulse::js()`), sa feuille de style aussi (`Pulse::css()`), sans nonce. Un
 * middleware les signait en réécrivant toute la réponse : il réécrivait aussi
 * le texte `<script>` que livewire.js contient, cassait sa syntaxe, et la page
 * restait sans Livewire ni Alpine, ses cartes jamais chargées.
 */
it('signe chaque script et chaque style de Pulse du nonce de son en-tête, sans toucher au code recopié', function (): void {
    $reponse = actingAs(cspOutilsSuperAdministrateur(), 'admin')->get('/backoffice/pulse')->assertOk()->baseResponse;
    $nonce = cspOutilsNonceDeLEnTete($reponse);

    expect(cspOutilsScriptsEnLigneSansNonce($reponse))->toBe([])
        ->and(cspOutilsStylesEnLigneSansNonce($reponse))->toBe([])
        ->and(cspOutilsNoncesDuCorps($reponse))->toBe([$nonce])
        ->and((string) $reponse->headers->get('Content-Security-Policy'))->not->toContain("'unsafe-inline'")
        ->and((string) $reponse->getContent())
        ->toContain('<script nonce="'.$nonce.'">'.file_get_contents(base_path('vendor/livewire/livewire/dist/livewire.js')).'</script>')
        ->toContain('<style nonce="'.$nonce.'">'.file_get_contents(base_path('vendor/laravel/pulse/dist/pulse.css')).'</style>');
});

/**
 * Comme en production, où l'image publie les fichiers du lecteur
 * (`log-viewer:publish`, tenu par LeDemarrageNeDefaitPasSesCachesTest) : la
 * page charge alors app.js par `src`. Sans eux, comme sur un poste ou en CI, le
 * paquet recopie tout app.js dans un `<script>` en ligne, depuis son code et
 * non depuis le gabarit, que rien ne signe.
 */
it('signe le script en ligne du lecteur de journaux du nonce de son en-tête', function (): void {
    $this->withoutMix();
    LogViewer::partialMock()->shouldReceive('assetsArePublished')->andReturn(true);

    $reponse = actingAs(cspOutilsSuperAdministrateur(), 'admin')->get('/backoffice/journaux')->assertOk()->baseResponse;
    $corps = (string) $reponse->getContent();

    expect($corps)->toContain('<script nonce="'.cspOutilsNonceDeLEnTete($reponse).'">')
        ->and(cspOutilsScriptsEnLigneSansNonce($reponse))->toBe([])
        ->and(cspOutilsNoncesDuCorps($reponse))->toBe([cspOutilsNonceDeLEnTete($reponse)]);
});
