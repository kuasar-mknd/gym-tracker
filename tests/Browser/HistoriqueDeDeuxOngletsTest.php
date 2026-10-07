<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\DailyJournal;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Le compte part dans un onglet : le bouton Retour d'un autre onglet ne relit
 * rien de son historique (#1965).
 *
 * La session vaut pour tous les onglets du navigateur, quand chaque onglet
 * garde sa propre clé de l'historique. Le serveur ne pose `clearHistory` que
 * sur la première page qu'il rend après la déconnexion, dans l'onglet qui la
 * demande : le second onglet gardait sa clé, et son bouton Retour relisait le
 * journal du compte parti sans rien demander au serveur. Chaque onglet jette
 * désormais sa clé quand un autre reçoit la page d'un autre titulaire
 * (resources/js/Utils/historiqueDuCompte.js).
 *
 * Le second onglet ouvre le tableau de bord en document, puis le journal et le
 * profil par des visites Inertia : la page initiale, que le document garde dans
 * sa balise `data-page`, ne contient donc pas la note. Le premier onglet se
 * déconnecte ; le second revient en arrière sans rien visiter avant.
 */
class HistoriqueDeDeuxOngletsTest extends DuskTestCase
{
    public function test_le_retour_dans_un_autre_onglet_ne_reaffiche_pas_le_journal_du_compte_parti(): void
    {
        $note = 'Note privée '.Str::random(12);
        $compte = User::factory()->create([
            'email' => 'deux-onglets-'.Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);
        DailyJournal::factory()->for($compte)->create(['content' => $note]);

        $this->browse(function (Browser $browser) use ($compte, $note): void {
            $browser->visit('/login')
                ->waitFor('input[type="email"]', 30)
                ->type('input[type="email"]', $compte->email)
                ->type('input[type="password"]', 'password')
                ->click('[data-testid="login-button"]')
                ->waitForLocation('/dashboard', 15);

            // Voir HistoriqueApresDeconnexionTest : sans `crypto.subtle`, rien ne se chiffre.
            if ($browser->script('return window.isSecureContext === true;')[0] !== true) {
                $this->markTestSkipped('Origine en http hors boucle locale : l’historique n’y est pas chiffré.');
            }

            $premierOnglet = $browser->driver->getWindowHandle();

            // Un second onglet du même navigateur : mêmes cookies, sa propre clé.
            $browser->driver->switchTo()->newWindow();
            $secondOnglet = $browser->driver->getWindowHandle();

            $browser->visit('/dashboard')
                ->waitForLocation('/dashboard', 15);
            $browser->script("window.Inertia.visit('/daily-journals');");
            $browser->waitForLocation('/daily-journals', 15)
                ->waitForText($note, 15);
            $browser->script("window.Inertia.visit('/profile');");
            $browser->waitForLocation('/profile', 15)
                ->waitFor('[data-testid="logout-button"]', 15);

            // Le premier onglet se déconnecte depuis son profil.
            $browser->driver->switchTo()->window($premierOnglet);
            $browser->script("window.Inertia.visit('/profile');");
            $browser->waitForLocation('/profile', 15)
                ->waitFor('[data-testid="logout-button"]', 15)
                ->clickWhenSettled('[data-testid="logout-button"]')
                ->waitForLocation('/login', 15)
                ->waitFor('input[type="email"]', 15);

            // Le second onglet revient en arrière, sur le journal.
            $browser->driver->switchTo()->window($secondOnglet);

            $temoin = <<<'JS'
                window.__noteRevue = false;
                window.__retourFait = false;
                window.addEventListener('popstate', () => {
                    window.__retourFait = true;
                });
                const note = NOTE;
                const application = document.getElementById('app');
                new MutationObserver(() => {
                    if (application.textContent.includes(note)) {
                        window.__noteRevue = true;
                    }
                }).observe(application, { childList: true, subtree: true, characterData: true });
            JS;

            $browser->script(str_replace('NOTE', json_encode($note, JSON_THROW_ON_ERROR), $temoin));
            $browser->script('window.history.back();');

            $browser->waitUntil("window.__retourFait === true && window.location.pathname === '/login' && !!document.querySelector('input[type=\"email\"]')", 15)
                ->assertDontSee($note);

            $this->assertFalse(
                $browser->script('return window.__noteRevue;')[0],
                'la note du compte parti a été réaffichée au retour arrière du second onglet'
            );

            $browser->driver->close();
            $browser->driver->switchTo()->window($premierOnglet);
        });
    }
}
