<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ParcoursHorsLigne;

/**
 * Se déconnecter depuis une copie hors ligne (#2048, parties A, D et E).
 *
 * Une page servie depuis sa copie n'envoie aucune écriture : la garde des
 * écritures l'arrête et recharge la page (`visitesHorsLigne.js`). La
 * déconnexion en est exemptée : arrêtée sur une copie montrée pour lenteur,
 * elle laisserait la session ouverte à la personne suivante. Le premier
 * parcours le joue sur un réseau lent (sept secondes par requête, par les
 * outils de Chrome) : « Plus » s'ouvre au bout de cinq secondes depuis sa
 * copie, sous « Le réseau ne répond pas », et « Se déconnecter » part de là
 * et aboutit. Le second la joue sans réseau, sur la copie que la coquille
 * démarre à froid : la déconnexion n'a pas lieu, le dit, et les copies sont
 * pourtant effacées, avant l'envoi (décision 6 du propriétaire) ; le réseau
 * rendu, la même déconnexion aboutit depuis ce document, qui n'a pas de
 * `<meta name="csrf-token">` (le jeton part du cookie, `X-XSRF-TOKEN`).
 *
 * Le second parcours coupe le réseau de la page et rend public/build
 * introuvable pendant la coupure (`Tests\Support\ParcoursHorsLigne`). Les
 * deux exigent un contexte sûr : la CI sert 127.0.0.1 ; sous Sail, ils se
 * déclarent sautés.
 */
class DeconnexionHorsLigneTest extends DuskTestCase
{
    use ParcoursHorsLigne;

    /** La latence de chaque requête de la page : au-delà des cinq secondes avant une copie. */
    private const int LATENCE_DU_RESEAU_LENT_MS = 7_000;

    private const string DECONNEXION_SANS_RESEAU = "Pas de réseau : la déconnexion n'a pas eu lieu.";

    public function test_se_deconnecter_part_et_aboutit_depuis_une_copie_montree_pour_lenteur(): void
    {
        $compte = $this->unCompte('deconnexion-lente');

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)
                ->visit('/dashboard')
                ->waitFor('#main-content', 30);

            $this->exigerUnContexteSur($browser);
            $this->attendreLeWorker($browser);
            $this->attendreLesCopies($browser, ['/dashboard', '/profile']);

            $outils = $this->outilsDuNavigateur($browser);

            try {
                $this->reglerLeReseau($outils, false, self::LATENCE_DU_RESEAU_LENT_MS);

                // « Plus » ne répond pas à temps : sa copie s'ouvre au bout de cinq secondes.
                $browser->disableAnimations()
                    ->clickWhenSettled('[dusk="nav-profile"]')
                    ->waitForLocation('/profile', 15)
                    ->waitFor('[dusk="bandeau-hors-ligne"]', 15)
                    ->assertScript($this->texteDe('[dusk="bandeau-hors-ligne-titre"]').".startsWith('Le réseau ne répond pas · copie du ')")
                    ->waitFor('[data-testid="logout-button"]', 15);

                // « Se déconnecter » part de la copie, et aboutit sur la page de connexion.
                $browser->clickWhenSettled('[data-testid="logout-button"]')
                    ->waitForLocation('/login', 60)
                    ->waitFor('input[type="email"]', 30);
            } finally {
                $this->reglerLeReseau($outils, false);
            }

            $this->assertSame([], $this->lireLeMagasin($browser)['copies'], 'des copies restent après la déconnexion');
            $this->assertNull($this->temoinDesCopies($browser), 'le témoin reste après la déconnexion');

            // La session est bien close côté serveur.
            $browser->visit('/dashboard')
                ->waitForLocation('/login', 15);
        });
    }

    public function test_sans_reseau_se_deconnecter_depuis_une_copie_efface_les_copies_et_le_dit(): void
    {
        $compte = $this->unCompte('deconnexion-sans-reseau');

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)
                ->visit('/dashboard')
                ->waitFor('#main-content', 30);

            $this->exigerUnContexteSur($browser);
            $this->attendreLeWorker($browser);
            $avant = $this->attendreLesCopies($browser, ['/dashboard', '/profile']);
            $this->assertIsArray($avant['etat']);
            $empreinte = $avant['etat']['empreinte'];

            $outils = $this->outilsDuNavigateur($browser);

            $this->sansReseauNiConstruction($browser, $outils, function () use ($browser, $empreinte): void {
                // « Plus », ouvert à froid : la coquille démarre l'application sur sa copie.
                $this->attendreLaCoquilleServieA($browser->visit('/profile'), '/profile')
                    ->waitFor('[dusk="bandeau-hors-ligne"]', 15)
                    ->disableAnimations()
                    ->waitFor('[data-testid="logout-button"]', 15);

                $browser->clickWhenSettled('[data-testid="logout-button"]')
                    ->waitUntil($this->laPageDit(self::DECONNEXION_SANS_RESEAU), 15, 'la déconnexion sans réseau ne l’a pas dit')
                    ->assertPathIs('/profile');

                // Les copies sont effacées, avant l'envoi, et la clé de la session fermée.
                $magasin = $this->attendreUnMagasinVide($browser, 'la déconnexion sans réseau a laissé des copies');
                $this->assertIsArray($magasin['etat']);
                $this->assertFalse($magasin['etat']['ouvert']);
                $this->assertContains($empreinte, $magasin['etat']['empreintesFermees'], 'la clé de la session n’est pas fermée');
            });

            // Le réseau rendu, la copie se recharge, et la même déconnexion aboutit depuis ce document.
            $browser->waitUntilMissing('[dusk="bandeau-hors-ligne"]', 90)
                ->assertScript("document.documentElement.hasAttribute('data-coquille') && document.querySelector('meta[name=\"csrf-token\"]') === null")
                ->clickWhenSettled('[data-testid="logout-button"]')
                ->waitForLocation('/login', 15)
                ->waitFor('input[type="email"]', 15);

            $this->assertNull($this->temoinDesCopies($browser), 'le témoin reste après la déconnexion');

            $browser->visit('/dashboard')
                ->waitForLocation('/login', 15);
        });
    }

    private function unCompte(string $prefixe): User
    {
        return User::factory()->create([
            'email' => $prefixe.'-'.Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);
    }
}
