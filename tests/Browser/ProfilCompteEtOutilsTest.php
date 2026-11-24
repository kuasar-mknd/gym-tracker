<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use App\Models\Workout;
use Facebook\WebDriver\Exception\TimeoutException;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ParcoursHorsLigne;

/**
 * Le Profil est le compte et les outils (#2434, lot Profil).
 *
 * Une carte d'identité dont la cloche occupe le coin, deux listes (Outils et
 * suivi, Compte), puis la déconnexion, seule, en dernier. Le parcours mesure ce
 * qu'une capture ne prouve pas : à 320, 375 et 402 px, avec une séance ouverte
 * (le bandeau « Reprendre » et la barre d'onglets flottent au bas de l'écran)
 * et une adresse très longue, rien ne déborde, le texte ne passe pas sous la
 * cloche, la cloche se touche, et la déconnexion, au pied de la page, n'est
 * recouverte par rien. Il rejoue la même mesure sur la copie hors ligne.
 */
class ProfilCompteEtOutilsTest extends DuskTestCase
{
    use ParcoursHorsLigne;

    private const string LA_PAGE_NE_DEBORDE_PAS = 'document.documentElement.scrollWidth <= document.documentElement.clientWidth';

    /**
     * Le texte de la carte d'identité, la cloche et la déconnexion, mesurés
     * après un défilement jusqu'au bas de la page : c'est là que la
     * déconnexion risque de passer sous le bandeau ou sous la barre d'onglets.
     */
    private const string LA_MESURE = <<<'JS'
        return (() => {
            window.scrollTo(0, 0);

            const rect = (element) => {
                const r = element.getBoundingClientRect();
                return { gauche: r.left, haut: r.top, droite: r.right, bas: r.bottom };
            };
            const separes = (a, b) => a.droite <= b.gauche || a.gauche >= b.droite || a.bas <= b.haut || a.haut >= b.bas;
            const titre = document.querySelector('#main-content h2');
            const adresse = titre.nextElementSibling;
            const carte = titre.closest('.rounded-carte');
            const cloche = document.querySelector('#main-content a[href$="/notifications"]');
            const sortie = document.querySelector('[data-testid="logout-button"]');
            const bandeau = document.querySelector('[dusk="active-workout-banner"]');
            const barre = document.querySelector('nav[aria-label="Navigation principale"]');
            const atteint = (element, x, y) => {
                const trouve = document.elementFromPoint(x, y);
                return trouve !== null && element.contains(trouve);
            };
            const points = (r) => [
                [(r.gauche + r.droite) / 2, (r.haut + r.bas) / 2],
                [(r.gauche + r.droite) / 2, r.haut + 6],
                [(r.gauche + r.droite) / 2, r.bas - 6],
                [r.gauche + 14, (r.haut + r.bas) / 2],
                [r.droite - 14, (r.haut + r.bas) / 2],
            ];
            const rCloche = rect(cloche);
            const rCarte = rect(carte);
            const clocheTouchee = points(rCloche).map(([x, y]) => atteint(cloche, x, y)).every(Boolean);
            const mesureHaute = {
                titreLibreDeLaCloche: separes(rect(titre), rCloche),
                adresseLibreDeLaCloche: separes(rect(adresse), rCloche),
                clocheTouchee,
                clocheDansLaCarte: rCloche.gauche >= rCarte.gauche && rCloche.droite <= rCarte.droite && rCloche.haut >= rCarte.haut && rCloche.bas <= rCarte.bas,
            };

            window.scrollTo(0, document.documentElement.scrollHeight);
            const rSortie = rect(sortie);

            return {
                ...mesureHaute,
                largeur: window.innerWidth,
                debordement: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                nomTronque: titre.scrollWidth > titre.clientWidth,
                adresseTronquee: adresse.scrollWidth > adresse.clientWidth,
                sortieDansLaFenetre: rSortie.haut >= 0 && rSortie.bas <= window.innerHeight,
                sortieTouchee: points(rSortie).map(([x, y]) => atteint(sortie, x, y)).every(Boolean),
                sortieAuDessusDuBandeau: bandeau === null || rSortie.bas <= rect(bandeau).haut,
                sortieAuDessusDeLaBarre: barre === null || rSortie.bas <= rect(barre).haut,
                hauteurDeLaSortie: rSortie.bas - rSortie.haut,
            };
        })();
        JS;

    private function unCompte(): User
    {
        return User::factory()->create([
            'name' => 'Jean-Baptiste Montmorency-Beaumont de la Fontaine',
            'email' => 'une.adresse.vraiment.tres.longue.'.Str::lower(Str::random(10)).'@un-nom-de-domaine-long.example.org',
            'email_verified_at' => now(),
        ]);
    }

    private function avecUneSeanceOuverte(User $compte): void
    {
        Workout::factory()->for($compte)->create([
            'name' => 'Séance '.Str::random(8),
            'started_at' => now()->subMinutes(20),
            'ended_at' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mesurer(Browser $browser, int $largeur): array
    {
        $browser->resize($largeur, 640)
            ->waitFor('[data-testid="logout-button"]', 30)
            ->waitFor('[dusk="active-workout-banner"]', 30)
            ->disableAnimations()
            ->waitForStableLayout();

        /** @var array<string, mixed> $mesure */
        $mesure = $browser->script(self::LA_MESURE)[0];

        return $mesure;
    }

    /**
     * @param  array<string, mixed>  $mesure
     */
    private function assertMesure(array $mesure, string $ou): void
    {
        $this->assertSame(0, $mesure['debordement'], "{$ou} : la page déborde horizontalement");
        $this->assertTrue($mesure['titreLibreDeLaCloche'], "{$ou} : le nom passe sous la cloche");
        $this->assertTrue($mesure['adresseLibreDeLaCloche'], "{$ou} : l'adresse passe sous la cloche");
        $this->assertTrue($mesure['clocheDansLaCarte'], "{$ou} : la cloche sort de la carte d'identité");
        $this->assertTrue($mesure['clocheTouchee'], "{$ou} : un autre élément recouvre la cloche");
        $this->assertTrue($mesure['sortieDansLaFenetre'], "{$ou} : la déconnexion est hors de la fenêtre au pied de la page");
        $this->assertTrue($mesure['sortieTouchee'], "{$ou} : un élément recouvre la déconnexion");
        $this->assertTrue($mesure['sortieAuDessusDuBandeau'], "{$ou} : la déconnexion passe sous le bandeau « Reprendre »");
        $this->assertTrue($mesure['sortieAuDessusDeLaBarre'], "{$ou} : la déconnexion passe sous la barre d'onglets");
        $this->assertGreaterThanOrEqual(55.5, $mesure['hauteurDeLaSortie'], "{$ou} : la déconnexion fait moins de 56 px de haut");
    }

    public function test_en_ligne_la_carte_d_identite_la_cloche_et_la_deconnexion_tiennent_a_chaque_largeur(): void
    {
        $compte = $this->unCompte();
        $this->avecUneSeanceOuverte($compte);

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)->visit('/profile')->waitFor('#main-content h2', 30);

            foreach ([320, 375, 402] as $largeur) {
                $mesure = $this->mesurer($browser, $largeur);

                $this->assertMesure($mesure, "en ligne à {$largeur} px");
                $this->assertTrue($mesure['nomTronque'], "en ligne à {$largeur} px : le nom long n'est pas tronqué, la mesure ne prouve rien");
                $this->assertTrue($mesure['adresseTronquee'], "en ligne à {$largeur} px : l'adresse longue n'est pas tronquée, la mesure ne prouve rien");
            }
        });
    }

    public function test_les_rangees_sont_les_sept_entrees_du_compte_sans_description_ni_doublon(): void
    {
        $compte = User::factory()->create(['email' => 'profil-rangees-'.Str::lower(Str::random(10)).'@example.org', 'email_verified_at' => now()]);

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)->resize(375, 812)->visit('/profile')
                ->waitFor('#main-content h2', 30)->disableAnimations()->waitForStableLayout();

            $browser->assertScript("[...document.querySelectorAll('#main-content nav')].map((nav) => nav.getAttribute('aria-label'))", ['Outils et suivi', 'Compte'])
                ->assertScript("[...document.querySelectorAll('#main-content nav a')].filter((a) => a.getClientRects().length > 0).map((a) => new URL(a.href).pathname)", ['/tools', '/habits', '/daily-journals', '/supplements', '/profile/edit'])
                ->assertScript("[...document.querySelectorAll('#main-content nav a')].filter((a) => a.getClientRects().length > 0).every((a) => a.getBoundingClientRect().height >= 55.5)", true)
                ->assertScript("document.querySelectorAll('#main-content nav a p, #main-content h3, #main-content h4').length", 0)
                ->assertScript("document.querySelector('#main-content a[href$=\"/raccourcis\"]').getClientRects().length", 0);

            // Au bureau : la cloche part dans la barre, deux colonnes, la déconnexion sous « Compte », « Raccourcis clavier » revient.
            $browser->resize(1280, 900)->waitForStableLayout();

            /** @var array<string, mixed> $bureau */
            $bureau = $browser->script(<<<'JS'
                return (() => {
                    const rect = (e) => e.getBoundingClientRect();
                    const outils = rect(document.querySelector('#main-content nav[aria-label="Outils et suivi"]'));
                    const compte = rect(document.querySelector('#main-content nav[aria-label="Compte"]'));
                    const sortie = rect(document.querySelector('[data-testid="logout-button"]'));
                    const raccourcis = document.querySelector('#main-content a[href$="/raccourcis"]');
                    const cloche = document.querySelector('#main-content a[href$="/notifications"]');

                    return {
                        cote: compte.left >= outils.right,
                        memeHaut: Math.abs(compte.top - outils.top) < 1,
                        sortieSousCompte: sortie.top >= compte.bottom && Math.abs(sortie.left - compte.left) < 1 && Math.abs(sortie.width - compte.width) < 1,
                        raccourcis: raccourcis.getClientRects().length > 0,
                        cloche: cloche.getClientRects().length > 0,
                    };
                })();
                JS)[0];

            $this->assertEquals(['cote' => true, 'memeHaut' => true, 'sortieSousCompte' => true, 'raccourcis' => true, 'cloche' => false], $bureau);

            $browser->resize(393, 852);
        });
    }

    public function test_sans_reseau_la_copie_garde_l_identite_outils_une_phrase_et_la_deconnexion(): void
    {
        $compte = $this->unCompte();
        $this->avecUneSeanceOuverte($compte);

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)
                ->resize(393, 852)
                ->visit('/stats')
                ->waitFor('#main-content', 30);

            $this->exigerUnContexteSur($browser);
            $this->attendreLeWorker($browser);

            $browser->disableAnimations()
                ->waitUntil("document.querySelectorAll('#main-content .glass-skeleton').length === 0", 30, 'Progrès attend encore ses données')
                ->clickWhenSettled('[dusk="nav-profile"]')
                ->waitForLocation('/profile', 15)
                ->waitFor('#main-content h2', 30);

            $this->attendreLesCopies($browser, ['/stats', '/profile']);
            $this->noterLesMessages($browser);

            $outils = $this->outilsDuNavigateur($browser);

            $this->sansReseauNiConstruction($browser, $outils, function () use ($browser): void {
                $browser->clickWhenSettled('[dusk="nav-stats"]');
                $this->attendreLaCopie($browser, '/stats');
                $browser->clickWhenSettled('[dusk="nav-profile"]');
                $this->attendreLaCopie($browser, '/profile');

                $browser->waitFor('#main-content [data-indisponible-hors-ligne]', 15)
                    ->assertScript("document.querySelector('#main-content [data-indisponible-hors-ligne]').textContent.includes('Habitudes, journal, compléments et paramètres reviennent avec le réseau.')", true)
                    ->assertScript("[...document.querySelectorAll('#main-content nav a')].map((a) => new URL(a.href).pathname)", ['/tools'])
                    ->assertMissing('[dusk="page-hors-ligne"]');

                foreach ([320, 375, 402] as $largeur) {
                    $mesure = $this->mesurer($browser, $largeur);

                    $this->assertMesure($mesure, "sur la copie à {$largeur} px");

                    // La phrase ne passe pas sous la cloche, que la carte d'identité contient.
                    $browser->assertScript(<<<'JS'
                        (() => {
                            const phrase = document.querySelector('#main-content [data-indisponible-hors-ligne]').getBoundingClientRect();
                            const cloche = document.querySelector('#main-content a[href$="/notifications"]').getBoundingClientRect();

                            return phrase.right <= cloche.left || phrase.left >= cloche.right || phrase.bottom <= cloche.top || phrase.top >= cloche.bottom;
                        })()
                        JS, true);
                }

                $this->assertSame([], $this->messagesSansReseau($browser));
            });
        });
    }

    private function attendreLaCopie(Browser $browser, string $chemin): void
    {
        try {
            $browser->waitForLocation($chemin, 15);
        } catch (TimeoutException) {
            $this->fail("la copie de {$chemin} ne s'est pas ouverte ; la page a dit : ".implode(' | ', $this->messagesSansReseau($browser)));
        }

        $browser->waitFor('[dusk="bandeau-hors-ligne"]', 15)
            ->assertScript($this->leBandeauDit('Hors ligne'));
    }
}
