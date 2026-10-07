<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use App\Models\Workout;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Sans réseau, l'application s'ouvre sur la page « hors ligne », pas sur
 * l'erreur du navigateur (#1966).
 *
 * Le worker ne servait que les actifs construits : une navigation partait au
 * réseau, et sans lui le navigateur affichait sa propre page d'erreur.
 * L'application installée, que le système avait arrêtée pendant que le
 * téléphone dormait, ne se rouvrait pas dans une salle sans réseau. Le worker
 * prend désormais chaque navigation, la laisse au réseau tant qu'il répond, et
 * sert sinon la page « hors ligne » qu'il a précachée.
 *
 * Le réseau est coupé par les outils de développement de Chrome, sur la page :
 * la requête d'une navigation, que le navigateur précharge pour le worker, y
 * échoue comme sur un téléphone sans réseau. Le compte se déconnecte avant :
 * la page servie ne doit rien porter de lui, puisqu'aucune page de compte
 * n'est gardée.
 */
class OuvertureHorsLigneTest extends DuskTestCase
{
    public function test_sans_reseau_une_navigation_ouvre_la_page_hors_ligne_sans_rien_du_compte_parti(): void
    {
        $compte = User::factory()->create([
            'name' => 'Compte '.Str::random(10),
            'email' => 'hors-ligne-'.Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);
        $seance = Workout::factory()->for($compte)->create(['ended_at' => null]);

        $this->browse(function (Browser $browser) use ($compte, $seance): void {
            $browser->loginAs($compte)
                ->visit('/dashboard')
                ->waitFor('#main-content', 30);

            /*
             * Un worker ne s'inscrit que dans un contexte sûr : HTTPS ou la
             * boucle locale. La CI sert 127.0.0.1 ; sous Sail, les parcours
             * visent http://laravel.test, où il n'y a pas de worker du tout.
             */
            if ($browser->script("return window.isSecureContext === true && 'serviceWorker' in navigator;")[0] !== true) {
                $this->markTestSkipped('Origine en http hors boucle locale : aucun service worker n’y est inscrit.');
            }

            // Installé, precache compris, et maître de la page.
            $browser->waitUntil(
                "navigator.serviceWorker.controller !== null && navigator.serviceWorker.controller.state === 'activated'",
                30,
                'le service worker n’a jamais pris la page en charge',
            );

            $browser->logout();

            $outils = new ChromeDevToolsDriver($browser->driver);
            $outils->execute('Network.enable');
            $outils->execute('Network.emulateNetworkConditions', [
                'offline' => true,
                'latency' => 0,
                'downloadThroughput' => -1,
                'uploadThroughput' => -1,
            ]);

            /*
             * Les textes se lisent dans le DOM : `titre-carte` et `glass-button`
             * les passent en majuscules, et WebDriver rend le texte transformé,
             * contre lequel une absence se vérifierait aussi à tort.
             */
            $texteDe = static fn (string $selecteur): string => 'document.querySelector('.json_encode($selecteur, JSON_THROW_ON_ERROR).').textContent.trim()';
            $absentDeLaPage = static fn (string $texte): string => '!document.body.textContent.includes('.json_encode($texte, JSON_THROW_ON_ERROR).')';

            try {
                foreach (['/dashboard', '/workouts/'.$seance->id, '/'] as $chemin) {
                    $browser->visit($chemin)
                        ->waitFor('[dusk="page-hors-ligne"]', 15)
                        ->assertTitle('Hors ligne - GymTracker')
                        ->assertScript($texteDe('[dusk="page-hors-ligne"] h1'), 'Pas de réseau')
                        ->assertScript($texteDe('[dusk="page-hors-ligne"] a'), 'Réessayer')
                        ->assertScript($absentDeLaPage($compte->name))
                        ->assertScript($absentDeLaPage($compte->email));
                }
            } finally {
                $outils->execute('Network.emulateNetworkConditions', [
                    'offline' => false,
                    'latency' => 0,
                    'downloadThroughput' => -1,
                    'uploadThroughput' => -1,
                ]);
            }

            // Le réseau revenu, la navigation repart au serveur, qui renvoie
            // vers la connexion : le compte s'est déconnecté.
            $browser->visit('/dashboard')
                ->waitForLocation('/login', 15)
                ->assertMissing('[dusk="page-hors-ligne"]');
        });
    }
}
