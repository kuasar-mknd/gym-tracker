<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ParcoursHorsLigne;

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
 * la page servie ne doit rien porter de lui, puisque le worker ne garde ni ne
 * sert aucune page de compte.
 *
 * Depuis #2048 (partie F), la page « hors ligne » est la coquille : elle
 * démarre l'application sur la copie chiffrée de l'adresse quand le magasin en
 * a une lisible pour le compte (`resources/js/Utils/demarrageHorsLigne.js`).
 * Le premier parcours part donc avec au moins une copie, que la déconnexion
 * par `/_dusk/logout` ne vide pas (aucune page de l'application n'y tourne) :
 * elle efface le témoin, porteur de la clé, et la coquille, qui ne trouve plus
 * de clé, purge le magasin et garde son contenu statique. Le second démarre à
 * froid sur la copie de l'accueil, et y trouve le logo et `cloud_off`.
 */
class OuvertureHorsLigneTest extends DuskTestCase
{
    use ParcoursHorsLigne;

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
            $this->exigerUnContexteSur($browser);

            // Installé, precache compris, et maître de la page.
            $this->attendreLeWorker($browser);

            // Au moins une copie avant la déconnexion : celle de l'accueil, que le chargement complet a gardée.
            $avant = $this->attendreLesCopies($browser, ['/dashboard']);
            $this->assertIsArray($avant['etat']);
            $this->assertTrue($avant['etat']['ouvert']);

            $browser->logout();

            // `/_dusk/logout` efface le témoin, porteur de la clé, sans qu'aucune page de l'application ne tourne.
            $this->assertNull($this->temoinDesCopies($browser), 'le témoin reste après /_dusk/logout');

            $outils = $this->outilsDuNavigateur($browser);
            $this->reglerLeReseau($outils, true);

            /*
             * Les textes se lisent dans le DOM : `titre-carte` et `glass-button`
             * les passent en majuscules, et WebDriver rend le texte transformé,
             * contre lequel une absence se vérifierait aussi à tort.
             */
            $texteDe = $this->texteDe(...);
            $absentDeLaPage = fn (string $texte): string => '!'.$this->laPageDit($texte);

            try {
                foreach (['/dashboard', '/workouts/'.$seance->id, '/'] as $chemin) {
                    $browser->visit($chemin)
                        ->waitFor('[dusk="page-hors-ligne"]', 15)
                        ->assertTitle('Hors ligne - GymTracker')
                        ->assertScript($texteDe('[dusk="page-hors-ligne"] h1'), 'Pas de réseau')
                        ->assertScript($texteDe('[dusk="page-hors-ligne"] a'), 'Réessayer')
                        // Le logo tracé dans la page, au-dessus de la carte.
                        ->assertScript("(() => { const logo = document.querySelector('main > div > svg'); return logo !== null && logo.querySelectorAll('path').length > 0; })()")
                        ->assertScript($absentDeLaPage($compte->name))
                        ->assertScript($absentDeLaPage($compte->email));
                }

                // La coquille, sans clé, a purgé le magasin : plus aucune copie, et un état fermé.
                $apres = $this->attendreUnMagasinVide($browser, 'des copies restent sans clé');
                $this->assertTrue($apres['etat'] === null || $apres['etat']['ouvert'] === false, 'le magasin reste ouvert sans clé');
            } finally {
                $this->reglerLeReseau($outils, false);
            }

            // Le réseau revenu, la navigation repart au serveur, qui renvoie
            // vers la connexion : le compte s'est déconnecté.
            $browser->visit('/dashboard')
                ->waitForLocation('/login', 15)
                ->assertMissing('[dusk="page-hors-ligne"]');
        });
    }

    /**
     * Un démarrage à froid sans réseau montre le logo et l'icône `cloud_off`
     * (#2048).
     *
     * Le compte a ouvert l'accueil en ligne : la coquille démarre donc
     * l'application sur sa copie (partie F), sous le bandeau « Hors ligne »,
     * et non sur son contenu statique, que le parcours précédent vérifie. Le
     * logo est tracé dans la barre de l'application (#2056), visible à partir
     * de 1 024 px : le parcours s'ouvre à cette largeur. L'icône du bandeau
     * vient de la police Material Symbols, qui n'entrait au cache qu'à sa
     * première utilisation. Couper le réseau par les outils de Chrome ne coupe
     * que la page, pas le worker : public/build est retiré du serveur pendant
     * la coupure, après les caches du worker et du navigateur, et tout ce que
     * la page montre vient du precache et de la copie.
     */
    public function test_un_demarrage_a_froid_sans_reseau_montre_le_logo_et_l_icone_cloud_off(): void
    {
        $compte = User::factory()->create([
            'email' => 'demarrage-a-froid-'.Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)
                ->resize(1280, 900)
                ->visit('/dashboard')
                ->waitFor('#main-content', 30);

            $this->exigerUnContexteSur($browser);
            $this->attendreLeWorker($browser);
            $this->attendreLesCopies($browser, ['/dashboard']);

            $outils = $this->outilsDuNavigateur($browser);

            try {
                $this->sansReseauNiConstruction($browser, $outils, function () use ($browser): void {
                    $this->attendreLaCoquilleServieA($browser->visit('/dashboard'), '/dashboard')
                        ->waitFor('[dusk="bandeau-hors-ligne"]', 15)
                        ->assertScript($this->texteDe('[dusk="bandeau-hors-ligne-titre"]').".startsWith('Hors ligne · copie du ')")
                        // Le logo tracé dans la barre, à sa taille.
                        ->assertScript("(() => { const logo = document.querySelector('nav a svg'); return logo !== null && logo.getBoundingClientRect().width > 0 && logo.querySelectorAll('path').length > 0; })()")
                        ->waitUntil(
                            "[...document.fonts].some((police) => police.family.includes('Material Symbols') && police.status === 'loaded')",
                            15,
                            'la police des icônes ne se charge pas sans réseau',
                        )
                        // Dessinée par sa police, l'icône tient dans son carré ; sans elle, on lirait le mot « cloud_off ».
                        ->assertScript("(() => { const icone = document.querySelector('[dusk=\"bandeau-hors-ligne\"] .material-symbols-outlined'); return icone !== null && icone.textContent.trim() === 'cloud_off' && icone.getBoundingClientRect().width < parseFloat(getComputedStyle(icone).fontSize) * 1.5; })()");
                });
            } finally {
                $browser->resize(393, 852);
            }
        });
    }
}
