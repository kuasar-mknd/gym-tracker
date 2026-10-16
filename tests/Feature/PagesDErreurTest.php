<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * Seules les erreurs 404 et 500 avaient une page française. Les autres codes
 * retombaient sur la vue minimale du framework, en `lang="en"` : « 403 |
 * Forbidden », « 419 | Page Expired », « 429 | Too Many Requests », « 503 |
 * Service Unavailable » (#1977). Un client Inertia l'affiche telle quelle dans
 * sa modale : la septième tentative de connexion de la minute, que
 * `throttle:6,1` refuse, ouvrait ainsi une page anglaise.
 *
 * Chaque page passe par `errors.partials.page`, autonome et à la charte, et
 * ne rend jamais le message de l'exception, qui peut nommer un modèle ou une
 * ligne.
 */

beforeEach(function (): void {
    Route::middleware('web')->get('/__sonde-des-pages-d-erreur/{code}', static function (string $code): never {
        abort((int) $code, 'Détail interne App\Models\Workout 4242');
    });
});

it('rend chaque code d’erreur en français, sans le message de l’exception', function (int $code, string $titre): void {
    $reponse = $this->get("/__sonde-des-pages-d-erreur/{$code}");

    $reponse->assertStatus($code)
        ->assertSee('<html lang="fr">', false)
        ->assertSee("<title>{$titre}</title>", false)
        ->assertSee((string) $code)
        ->assertSee("Retour à l'accueil", false)
        ->assertDontSee('Détail interne')
        ->assertDontSee('4242')
        ->assertDontSee('lang="en"', false);

    foreach (['Unauthorized', 'Payment Required', 'Forbidden', 'Page Expired', 'Too Many Requests', 'Service Unavailable', 'Not Found', 'Server Error', 'Whoops', 'Method Not Allowed', 'Bad Gateway'] as $anglais) {
        $reponse->assertDontSee($anglais);
    }
})->with([
    '401' => [401, 'Connexion requise'],
    '402' => [402, 'Demande refusée'],
    '403' => [403, 'Accès refusé'],
    '404' => [404, 'Page introuvable'],
    '419' => [419, 'Session expirée'],
    '429' => [429, 'Trop de tentatives'],
    '500' => [500, 'Erreur serveur'],
    '503' => [503, 'Mise à jour en cours'],
    'un autre 4xx (405)' => [405, 'Demande refusée'],
    'un autre 5xx (502)' => [502, 'Service indisponible'],
]);

/*
 * Le framework fournit ses propres vues pour quelques codes (401, 402…), et
 * Laravel les trouve avant le repli `errors::4xx` : chacune doit donc avoir sa
 * page française, sans quoi le code rend la vue anglaise.
 */
it('donne une page française à chaque code dont le framework fournit la vue', function (): void {
    $vues = glob(base_path('vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/views/[0-9][0-9][0-9].blade.php'));
    $vuesDuFramework = is_array($vues) ? $vues : [];

    expect($vuesDuFramework)->not->toBeEmpty();

    $sansPageFrancaise = array_values(array_filter(
        array_map(static fn (string $vue): string => basename($vue, '.blade.php'), $vuesDuFramework),
        static fn (string $code): bool => ! is_file(resource_path("views/errors/{$code}.blade.php")),
    ));

    expect($sansPageFrancaise)->toBe([]);
});

it('répond en français à la septième tentative de connexion de la minute, même en Inertia', function (): void {
    $compte = User::factory()->create();

    for ($tentative = 1; $tentative <= 6; $tentative++) {
        $this->withHeaders(['X-Inertia' => 'true'])
            ->post('/login', ['email' => $compte->email, 'password' => 'mauvais-mot-de-passe'])
            ->assertStatus(302);
    }

    $this->withHeaders(['X-Inertia' => 'true'])
        ->post('/login', ['email' => $compte->email, 'password' => 'mauvais-mot-de-passe'])
        ->assertStatus(429)
        ->assertSee('<html lang="fr">', false)
        ->assertSee('Trop de tentatives')
        ->assertSee('Patiente une minute, puis réessaie.')
        ->assertDontSee('Too Many Requests');
});

/*
 * Hors du groupe `web`, la réponse d'erreur ne porte aucune politique de
 * contenu : adresse inconnue, méthode refusée, maintenance. La page que
 * `php artisan down --render` écrit d'avance non plus : sous Octane, en
 * production, `PreventRequestsDuringMaintenance` la sert dans Laravel, avec
 * les en-têtes de `SecurityHeaders` mais sans CSP ; hors d'Octane
 * (`php artisan serve`), `public/index.php` la sert avant Laravel, sans aucun
 * en-tête de l'application. La page porte donc la sienne, en tête, avant son
 * bloc `<style>`. Le pilote du cache, sur le magasin `array`, garde l'état de
 * la maintenance hors du dossier storage, que les processus d'une suite
 * parallèle partagent.
 */
it('porte sa propre politique de contenu, même hors du groupe web et dans la page de maintenance écrite d’avance', function (): void {
    $politique = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src \'self\'; base-uri \'none\'; form-action \'none\'">';
    $pages = [
        'adresse inconnue' => (string) get('/une-adresse-que-rien-ne-sert')->assertNotFound()->getContent(),
        'méthode refusée' => (string) post('/up')->assertMethodNotAllowed()->getContent(),
    ];

    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);

    try {
        expect(Artisan::call('down'))->toBe(0);
        $pages['maintenance'] = (string) get('/login')->assertServiceUnavailable()->getContent();
        Artisan::call('up');

        expect(Artisan::call('down', ['--render' => 'errors::503']))->toBe(0);

        $gabarit = app()->maintenanceMode()->data()['template'] ?? null;
        $pages['page écrite d’avance, servie par Laravel (Octane)'] = (string) get('/login')->assertServiceUnavailable()->getContent();
        $pages['page écrite d’avance, telle que public/index.php la sert'] = is_string($gabarit) ? $gabarit : '';
    } finally {
        Artisan::call('up');
    }

    foreach ($pages as $page => $html) {
        $politiqueA = strpos($html, $politique);
        $styleA = strpos($html, '<style>');

        expect($politiqueA)->toBeInt("La page « {$page} » ne porte pas sa politique.")
            ->and($styleA)->toBeInt()
            ->and((int) $politiqueA)->toBeLessThan((int) $styleA);
    }
});
