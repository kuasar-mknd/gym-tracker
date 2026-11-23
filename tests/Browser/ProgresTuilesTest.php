<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use App\Models\Workout;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Progrès est un hub : le volume du mois, des tuiles d'accès, la régularité,
 * puis la période avec ses deux cartes et la durée des séances.
 *
 * Les tuiles se mesurent dans Chrome, à 320 px comme à 390 : Records pleine
 * largeur, les quatre autres deux par ligne, chacune d'au moins 44 pt de haut
 * et sans chiffre, et aucun débordement horizontal de la page. Un compte neuf
 * montre un seul état vide à la place des cartes, et garde les tuiles.
 */
class ProgresTuilesTest extends DuskTestCase
{
    private const string LA_PAGE_NE_DEBORDE_PAS = 'document.documentElement.scrollWidth <= document.documentElement.clientWidth';

    private const string GEOMETRIE_DES_TUILES = <<<'JS'
        return (() => {
            const tuiles = [...document.querySelectorAll('#main-content [dusk="tuiles-d-acces"] a')];
            const cadres = tuiles.map((a) => a.getBoundingClientRect());
            return JSON.stringify({
                chemins: tuiles.map((a) => new URL(a.href).pathname),
                hauteurs: cadres.map((c) => Math.round(c.height)),
                largeurs: cadres.map((c) => Math.round(c.width)),
                hauts: cadres.map((c) => Math.round(c.top)),
                chiffres: tuiles.some((a) => /\d/.test(a.textContent)),
            });
        })();
        JS;

    private const string POSITIONS_DES_BLOCS = <<<'JS'
        return (() => {
            const titres = [...document.querySelectorAll('#main-content h2, #main-content h3, #main-content h4')];
            const position = (texte) => {
                const noeud = titres.find((t) => t.textContent.trim() === texte);
                return noeud ? noeud.getBoundingClientRect().top : null;
            };
            return JSON.stringify({
                tuiles: document.querySelector('#main-content [dusk="tuiles-d-acces"]').getBoundingClientRect().top,
                volume: position('Volume mensuel'),
                regularite: position('Régularité'),
                periode: position('Sur la période'),
                evolution: position('Évolution du volume'),
                muscles: position('Répartition musculaire'),
                duree: position('Durée des séances'),
            });
        })();
        JS;

    /**
     * @return array<string, mixed>
     */
    private function lire(Browser $browser, string $script): array
    {
        $json = $browser->script($script)[0];
        $this->assertIsString($json);

        $lu = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($lu);

        /** @var array<string, mixed> $lu */
        return $lu;
    }

    private function assertLesTuilesTiennent(Browser $browser): void
    {
        $geometrie = $this->lire($browser, self::GEOMETRIE_DES_TUILES);

        $this->assertSame(['/records', '/exercises', '/corps', '/goals', '/achievements'], $geometrie['chemins']);
        $this->assertFalse($geometrie['chiffres'], 'une tuile porte un chiffre');

        /** @var list<int> $hauteurs */
        $hauteurs = $geometrie['hauteurs'];
        /** @var list<int> $largeurs */
        $largeurs = $geometrie['largeurs'];
        /** @var list<int> $hauts */
        $hauts = $geometrie['hauts'];

        foreach ($hauteurs as $hauteur) {
            $this->assertGreaterThanOrEqual(44, $hauteur);
            $this->assertLessThanOrEqual(112, $hauteur);
        }

        // Records pleine largeur, puis deux par ligne : les deux de chaque paire côte à côte.
        $this->assertGreaterThan($largeurs[1] * 1.8, $largeurs[0]);
        $this->assertSame($hauts[1], $hauts[2]);
        $this->assertSame($hauts[3], $hauts[4]);
        $this->assertGreaterThan($hauts[1], $hauts[3]);
        $this->assertGreaterThan($hauts[0], $hauts[1]);
    }

    public function test_un_compte_avec_des_seances_voit_le_heros_puis_les_tuiles_puis_la_periode(): void
    {
        $compte = User::factory()->create();
        Workout::factory()->faite()->create([
            'user_id' => $compte->id,
            'started_at' => now()->subDays(2),
            'ended_at' => now()->subDays(2)->addHour(),
        ]);

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)->visit('/stats')->disableAnimations()->waitFor('#main-content', 30);

            foreach ([320, 390, 1280] as $largeur) {
                $browser->resize($largeur, 844)
                    ->waitFor('#main-content [dusk="tuiles-d-acces"]', 30)
                    ->waitUntil("document.querySelectorAll('#main-content .glass-skeleton').length === 0", 30, 'des cartes de Progrès attendent encore leurs données')
                    ->assertScript(self::LA_PAGE_NE_DEBORDE_PAS, true);
                $this->assertLesTuilesTiennent($browser);
            }

            $browser->resize(390, 844);

            // L'ordre de la page : le héros, les tuiles, la régularité, la période, la durée.
            $y = $this->lire($browser, self::POSITIONS_DES_BLOCS);

            foreach (['volume', 'regularite', 'periode', 'evolution', 'muscles', 'duree'] as $cle) {
                $this->assertNotNull($y[$cle], "« {$cle} » manque sur Progrès");
            }

            $this->assertLessThan($y['tuiles'], $y['volume']);
            $this->assertLessThan($y['regularite'], $y['tuiles']);
            $this->assertLessThan($y['periode'], $y['regularite']);
            $this->assertLessThan($y['evolution'], $y['periode']);
            $this->assertLessThan($y['muscles'], $y['evolution']);
            $this->assertLessThan($y['duree'], $y['muscles']);

            // Le choix de période n'existe qu'une fois, et les anciennes tuiles et la carte 1RM ont disparu.
            $browser->assertScript("document.querySelectorAll('#main-content [role=\"group\"][aria-label=\"Période\"]').length", 1)
                ->assertScript("['Muscles', 'vs mois', 'Progression 1RM'].some((texte) => document.querySelector('#main-content').textContent.includes(texte))", false)
                ->assertScript("document.querySelector('header h1').textContent.trim()", 'Progrès')
                ->assertScript("document.title.startsWith('Progrès')", true)
                ->assertNoConsoleExceptions();
        });
    }

    public function test_un_compte_neuf_voit_un_seul_etat_vide_et_garde_les_tuiles(): void
    {
        $compte = User::factory()->create();

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)
                ->resize(320, 700)
                ->visit('/stats')
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitForText('Pas encore de séance', 30)
                ->waitForText('Tes chiffres apparaissent après la première.', 15)
                ->assertPresent('[dusk="start-workout-button"]')
                ->assertScript("document.querySelectorAll('#main-content [role=\"group\"][aria-label=\"Période\"]').length", 0)
                ->assertScript("['Volume mensuel', 'Régularité', 'Sur la période', 'Durée des séances'].some((texte) => document.querySelector('#main-content').textContent.includes(texte))", false)
                ->assertScript(self::LA_PAGE_NE_DEBORDE_PAS, true);

            $this->assertLesTuilesTiennent($browser);
        });
    }

    public function test_un_historique_de_plus_de_six_mois_n_est_pas_un_compte_neuf(): void
    {
        $compte = User::factory()->create();
        Workout::factory()->faite()->create([
            'user_id' => $compte->id,
            'started_at' => now()->subMonths(14),
            'ended_at' => now()->subMonths(14)->addHour(),
        ]);

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)
                ->resizeToIphone15()
                ->visit('/stats')
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitForText('Régularité', 30)
                ->assertDontSee('Pas encore de séance')
                ->assertScript("document.querySelectorAll('#main-content [role=\"group\"][aria-label=\"Période\"]').length", 1);
        });
    }
}
