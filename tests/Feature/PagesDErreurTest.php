<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;

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

    foreach (['Forbidden', 'Page Expired', 'Too Many Requests', 'Service Unavailable', 'Not Found', 'Server Error', 'Whoops', 'Method Not Allowed', 'Bad Gateway'] as $anglais) {
        $reponse->assertDontSee($anglais);
    }
})->with([
    '403' => [403, 'Accès refusé'],
    '404' => [404, 'Page introuvable'],
    '419' => [419, 'Session expirée'],
    '429' => [429, 'Trop de tentatives'],
    '500' => [500, 'Erreur serveur'],
    '503' => [503, 'Mise à jour en cours'],
    'un autre 4xx (405)' => [405, 'Demande refusée'],
    'un autre 5xx (502)' => [502, 'Service indisponible'],
]);

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
