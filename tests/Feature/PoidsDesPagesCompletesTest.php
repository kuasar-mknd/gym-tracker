<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tighten\Ziggy\BladeRouteGenerator;

/*
 * Ce que pèse une page complète : ouverture de l'application, rechargement,
 * connexion, clic sur une notification. Le service worker ne sert jamais ces
 * documents depuis son cache, ils voyagent à chaque fois (#1969).
 *
 * `@routes` y écrivait, en script en ligne, la table des routes ET la fonction
 * `route()` de Ziggy : 21 Ko, 7 Ko compressés, environ 70 % du document tel
 * qu'il voyage, et autant de script à analyser avant le premier rendu. Or le
 * bundle porte déjà cette fonction, mise en cache pour un an. La page n'écrit
 * plus que la table ; `resources/js/Utils/routeGlobale.js` pose la fonction.
 */

/**
 * Le corps de la page que sert une adresse, tel qu'il part au premier rendu
 * d'une requête.
 *
 * Ziggy n'écrit sa balise complète qu'une fois par processus, puis une simple
 * fusion de table ; sous Octane, il remet ce drapeau à chaque requête reçue
 * (`ZiggyServiceProvider`), donc chaque page complète porte la balise
 * complète. La suite tourne dans un seul processus : on remet le drapeau comme
 * Octane, sinon le premier test qui rend une page masquerait le poids réel à
 * tous les suivants.
 */
function poidsPageServie(string $adresse, ?User $utilisateur = null): string
{
    BladeRouteGenerator::$generated = false;

    if ($utilisateur instanceof User) {
        test()->actingAs($utilisateur);
    }

    $reponse = test()->get($adresse);

    if (! $reponse instanceof TestResponse) {
        throw new UnexpectedValueException("{$adresse} n'a pas rendu de réponse de test.");
    }

    return (string) $reponse->assertOk()->getContent();
}

/**
 * Les pages complètes mesurées : celle d'un visiteur et celle qu'ouvre
 * l'application installée.
 *
 * @return array<string, string>
 */
function poidsPagesMesurees(): array
{
    return [
        '/login' => poidsPageServie('/login'),
        '/dashboard' => poidsPageServie('/dashboard', User::factory()->create()),
    ];
}

it('n’embarque plus la fonction route() de Ziggy, seulement sa table', function (): void {
    $fonction = (string) file_get_contents(base_path('vendor/tightenco/ziggy/dist/route.umd.js'));

    expect(strlen($fonction))->toBeGreaterThan(10_000);

    foreach (poidsPagesMesurees() as $adresse => $page) {
        expect(str_contains($page, 'const Ziggy='))->toBeTrue("{$adresse} n'écrit plus la table des routes que lit route().")
            ->and(str_contains($page, substr($fonction, 0, 200)))->toBeFalse("{$adresse} embarque le corps de route.umd.js.")
            ->and(str_contains($page, 'is not in the route list'))->toBeFalse("{$adresse} embarque la fonction route() de Ziggy.");
    }
});

it('tient chaque page complète sous 4 Kio compressés', function (): void {
    foreach (poidsPagesMesurees() as $adresse => $page) {
        $compresse = gzencode($page, 6);

        expect($compresse)->toBeString()
            ->and(strlen((string) $compresse))->toBeLessThanOrEqual(4096, sprintf(
                '%s pèse %d octets compressés (%d bruts), au-delà du budget de 4 096.',
                $adresse,
                strlen((string) $compresse),
                strlen($page),
            ));
    }
});
