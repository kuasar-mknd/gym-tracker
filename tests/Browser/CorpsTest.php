<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\BodyMeasurement;
use App\Models\BodyPartMeasurement;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ParcoursHorsLigne;

/**
 * La page Corps réunit le poids, la masse grasse et les mensurations (#2434,
 * lot 4b), et n'est jamais gardée hors ligne : ses chiffres sont des mesures.
 *
 * En ligne, le parcours part du Profil, où une tuile « Corps » remplace
 * celles du poids et des mensurations, et y lit les trois : le poids et la
 * masse grasse du compte, la dernière mensuration de chaque partie. Le retour
 * mène à Progrès, qui reste l'onglet allumé. Sans réseau, la tuile ne mène à
 * aucune copie : l'application dit que la page n'est pas disponible et reste
 * sur le Profil, et une navigation complète sur /corps n'ouvre que la page
 * hors ligne, sans une mesure du compte ; le réseau rendu, la page s'ouvre sur
 * ses mesures.
 */
class CorpsTest extends DuskTestCase
{
    use ParcoursHorsLigne;

    /**
     * Le poids, la masse grasse et la mensuration du compte, sous la forme que
     * `nombre()` leur donne (point ou virgule). Lu en `innerText` (le texte brut colle la date à la valeur). Une seule ligne : `waitUntil`
     * la fait suivre d'un `return`, que des retours à la ligne rendraient nul.
     */
    private const string LES_MESURES_SONT_A_L_ECRAN = "(() => { const texte = document.querySelector('#main-content')?.innerText ?? ''; return /(?<![\\d.,])83[.,]7(?![\\d])/.test(texte) && /(?<![\\d.,])19[.,]3(?![\\d])/.test(texte) && /(?<![\\d.,])81(?![\\d.,])/.test(texte); })()";

    private const string PAGE_INDISPONIBLE = "Cette page n'est pas disponible sans réseau.";

    private function unCompteAvecDesMesures(): User
    {
        $compte = User::factory()->create([
            'email' => 'corps-'.Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);
        BodyMeasurement::factory()->create([
            'user_id' => $compte->id,
            'weight' => '83.70',
            'body_fat' => '19.30',
            'measured_at' => now()->subDay()->toDateString(),
            'notes' => null,
        ]);
        BodyPartMeasurement::factory()->create([
            'user_id' => $compte->id,
            'part' => 'Waist',
            'value' => 81,
            'unit' => 'cm',
            'measured_at' => now()->subDay()->toDateString(),
            'notes' => null,
        ]);

        return $compte;
    }

    public function test_en_ligne_corps_reunit_le_poids_la_masse_grasse_et_les_mensurations(): void
    {
        $compte = $this->unCompteAvecDesMesures();

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)
                ->resizeToIphone15()
                ->visit('/profile')
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->clickWhenSettled('main a[href$="/corps"]')
                ->waitForLocation('/corps', 15)
                ->waitFor('#main-content', 30)
                ->waitUntil("document.querySelectorAll('#main-content .glass-skeleton').length === 0", 30, 'les cartes de Corps attendent encore leurs données')
                ->waitUntil(self::LES_MESURES_SONT_A_L_ECRAN, 30, 'le poids, la masse grasse et la mensuration ne sont pas à l’écran')
                ->assertScript("[...document.querySelectorAll('h1')].filter((titre) => titre.getClientRects().length > 0).length", 1)
                ->assertScript("[...document.querySelectorAll('h1')].find((titre) => titre.getClientRects().length > 0).textContent.trim().toLowerCase()", 'corps')
                ->assertScript("[...document.querySelectorAll('#main-content a')].map((a) => new URL(a.href).pathname).filter((p) => ['/body-measurements', '/body-metrics'].includes(p)).length > 0", true);

            // Poids et mensurations renvoient à Corps, et Progrès reste allumé.
            $browser->clickWhenSettled('#main-content a[href$="/body-measurements"]')
                ->waitForLocation('/body-measurements', 15)
                ->waitFor('header a[aria-label="Retour"]', 15)
                ->assertScript("new URL(document.querySelector('header a[aria-label=\"Retour\"]').href).pathname", '/corps')
                ->clickWhenSettled('header a[aria-label="Retour"]')
                ->waitForLocation('/corps', 15)
                ->assertScript("document.querySelectorAll('nav[aria-label=\"Navigation principale\"] a[aria-current=\"page\"]').length", 1)
                ->assertScript("new URL(document.querySelector('nav[aria-label=\"Navigation principale\"] a[aria-current=\"page\"]').href).pathname", '/stats')
                ->assertNoConsoleExceptions();
        });
    }

    public function test_sans_reseau_corps_dit_qu_il_est_indisponible_sans_une_mesure(): void
    {
        $compte = $this->unCompteAvecDesMesures();

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)
                ->visit('/profile')
                ->waitFor('#main-content', 30);

            $this->exigerUnContexteSur($browser);
            $this->attendreLeWorker($browser);
            $this->attendreLesCopies($browser, ['/profile']);

            $this->noterLesMessages($browser);

            $outils = $this->outilsDuNavigateur($browser);

            $this->sansReseauNiConstruction($browser, $outils, function () use ($browser): void {
                $browser->clickWhenSettled('main a[href$="/corps"]')
                    ->waitUntil($this->laPageDit(self::PAGE_INDISPONIBLE), 15, 'Corps n’a rien dit sans réseau')
                    ->assertPathIs('/profile')
                    ->assertScript('!/(?<![\d.,])(83[.,]7|19[.,]3)(?![\d])/.test(document.body.textContent)');

                // Une navigation complète sur Corps, sans copie : la page qui dit ce qui s'ouvre sans réseau, sans une mesure.
                $this->attendreLaCoquilleServieA($browser->visit('/corps'), '/corps')
                    ->waitUsing(15, 200, fn (): bool => (bool) $browser->script('return document.querySelector(\'[dusk="page-hors-ligne-sans-copie"], [dusk="page-hors-ligne"]\') !== null')[0])
                    ->assertPathIs('/corps')
                    ->assertScript('!/(?<![\d.,])(83[.,]7|19[.,]3)(?![\d])/.test(document.documentElement.outerHTML)');
            });

            // Le réseau rendu, la page s'ouvre sur ses mesures.
            $browser->visit('/corps')
                ->waitFor('#main-content', 30)
                ->waitUntil(self::LES_MESURES_SONT_A_L_ECRAN, 30, 'les mesures ne reviennent pas avec le réseau');
        });
    }
}
