<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * La page complète n'écrit plus la fonction `route()` de Ziggy, seulement sa
 * table des routes (#1969) : la fonction vient du bundle, que `main.js` pose en
 * globale. Les `<script setup>` l'appellent sans l'importer ; la connexion,
 * qui poste vers `route('login')`, doit donc toujours aboutir, dans un vrai
 * navigateur, où la table est une constante globale d'un script classique et
 * non une propriété de `window`.
 */
class LaFonctionRouteEstGlobaleTest extends DuskTestCase
{
    public function test_une_page_appelle_route_dans_son_script_sans_la_fonction_dans_le_document(): void
    {
        $utilisateur = User::factory()->create([
            'email' => 'route-globale-'.time().random_int(0, 999).'@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->browse(function (Browser $browser) use ($utilisateur): void {
            $browser->visit('/login')
                ->waitFor('input[type="email"]', 30);

            $this->assertSame(
                [false, 'function', '/dashboard'],
                $browser->script([
                    "return [...document.scripts].some((script) => !script.src && script.textContent.includes('is not in the route list'))",
                    'return typeof window.route',
                    "return window.route('dashboard', undefined, false)",
                ]),
                'La page embarque encore la fonction route() de Ziggy, ou le bundle ne la pose plus en globale.',
            );

            $browser->type('input[type="email"]', $utilisateur->email)
                ->type('input[type="password"]', 'password')
                ->clickWhenSettled('[data-testid="login-button"]')
                ->waitForLocation('/dashboard', 15)
                ->waitFor('#main-content', 15)
                ->assertNoConsoleExceptions();
        });
    }
}
