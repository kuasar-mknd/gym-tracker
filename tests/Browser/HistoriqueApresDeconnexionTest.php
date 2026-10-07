<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\DailyJournal;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Après la déconnexion, le bouton Retour ne réaffiche rien du compte parti (#1965).
 *
 * Inertia range les props de chaque page visitée dans l'historique du
 * navigateur et les rend au bouton Retour sans rien demander au serveur. Sur
 * un appareil partagé, la personne suivante revoyait ainsi le journal du compte
 * qui venait de se déconnecter. Les pages d'un compte sont désormais chiffrées
 * dans l'historique, et la déconnexion en jette la clé : l'entrée ne se relit
 * plus, et Inertia redemande la page au serveur, qui renvoie vers la connexion.
 *
 * Le parcours reste dans un seul document, comme l'application : connexion,
 * journal et profil par des visites Inertia, déconnexion par le bouton du
 * profil. Un témoin posé dans la page après la déconnexion note toute
 * apparition de la note, même fugace, avant le retour de la page de connexion.
 */
class HistoriqueApresDeconnexionTest extends DuskTestCase
{
    public function test_le_retour_apres_la_deconnexion_ne_reaffiche_pas_le_journal(): void
    {
        $note = 'Note privée '.Str::random(12);
        $compte = User::factory()->create([
            'email' => 'historique-'.Str::lower(Str::random(10)).'@example.org',
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

            /*
             * `crypto.subtle`, sans lequel rien ne se chiffre, n'existe qu'en
             * HTTPS et sur la boucle locale. La CI sert 127.0.0.1 ; sous Sail,
             * les parcours visent http://laravel.test, où le serveur ne demande
             * pas le chiffrement, faute de quoi Inertia lèverait.
             */
            if ($browser->script('return window.isSecureContext === true;')[0] !== true) {
                $this->markTestSkipped('Origine en http hors boucle locale : l’historique n’y est pas chiffré.');
            }

            $browser->script("window.Inertia.visit('/daily-journals');");
            $browser->waitForLocation('/daily-journals', 15)
                ->waitForText($note, 15);

            $browser->script("window.Inertia.visit('/profile');");
            $browser->waitForLocation('/profile', 15)
                ->waitFor('[data-testid="logout-button"]', 15)
                ->clickWhenSettled('[data-testid="logout-button"]')
                ->waitForLocation('/login', 15)
                ->waitFor('input[type="email"]', 15);

            $temoin = <<<'JS'
                window.__noteRevue = false;
                window.__retourFait = false;
                window.addEventListener('popstate', () => {
                    window.__retourFait = true;
                });
                const note = NOTE;
                const regarder = () => {
                    if (document.body.textContent.includes(note)) {
                        window.__noteRevue = true;
                    }
                };
                new MutationObserver(regarder).observe(document.body, {
                    childList: true,
                    subtree: true,
                    characterData: true,
                });
            JS;

            $browser->script(str_replace('NOTE', json_encode($note, JSON_THROW_ON_ERROR), $temoin));

            // La personne suivante revient deux pages en arrière : sur le journal.
            $browser->script('window.history.go(-2);');

            $browser->waitUntil("window.__retourFait === true && window.location.pathname === '/login' && !!document.querySelector('input[type=\"email\"]')", 15)
                ->assertDontSee($note);

            $this->assertFalse(
                $browser->script('return window.__noteRevue;')[0],
                'la note du compte parti a été réaffichée au retour arrière'
            );
        });
    }
}
