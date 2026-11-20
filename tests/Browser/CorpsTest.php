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
 * aucune copie : la page « Hors ligne » s'ouvre, sans une mesure du compte, et
 * le réseau rendu la page s'ouvre sur ses mesures.
 */
class CorpsTest extends DuskTestCase
{
    use ParcoursHorsLigne;

    private const string LES_MESURES_SONT_A_L_ECRAN = <<<'SCRIPT'
        (() => {
            const texte = document.querySelector('#main-content')?.textContent ?? '';

            return /\b83[.,]7\b/.test(texte) && /\b19[.,]3\b/.test(texte) && /\b81\b/.test(texte);
        })()
    SCRIPT;

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
                ->assertScript("document.querySelectorAll('h1').length", 1)
                ->assertScript("document.querySelector('main h1, header h1').textContent.trim().toLowerCase()", 'corps')
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

    public function test_sans_reseau_corps_ouvre_la_page_hors_ligne_sans_une_mesure(): void
    {
        $compte = $this->unCompteAvecDesMesures();

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)
                ->visit('/profile')
                ->waitFor('#main-content', 30);

            $this->exigerUnContexteSur($browser);
            $this->attendreLeWorker($browser);
            $this->attendreLesCopies($browser, ['/profile']);

            $outils = $this->outilsDuNavigateur($browser);
            $texteDe = $this->texteDe(...);

            $this->sansReseauNiConstruction($browser, $outils, function () use ($browser, $texteDe): void {
                $browser->clickWhenSettled('main a[href$="/corps"]')
                    ->waitFor('[dusk="page-hors-ligne"]', 30)
                    ->assertScript($texteDe('[dusk="page-hors-ligne"] h1'), 'Pas de réseau')
                    ->assertScript('!/\b83[.,]7\b|\b19[.,]3\b/.test(document.body.textContent)');
            });

            // Le réseau rendu, la page s'ouvre sur ses mesures.
            $browser->visit('/corps')
                ->waitFor('#main-content', 30)
                ->waitUntil(self::LES_MESURES_SONT_A_L_ECRAN, 30, 'les mesures ne reviennent pas avec le réseau');
        });
    }
}
