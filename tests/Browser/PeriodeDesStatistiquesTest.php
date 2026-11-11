<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * La période des statistiques change ce que la page montre, graphiques
 * compris (#2148, étape 2a).
 *
 * Le contenu du layout n'est plus remonté à chaque adresse : une visite qui
 * garde l'état et change l'adresse, comme le choix d'une période, laisse les
 * cartes en place et leur passe les nouvelles props. Un graphique qui ne les
 * suivrait pas garderait l'ancienne période. Les graphiques des statistiques
 * vivent sous `<Deferred>`, qui revient à son squelette quand la réponse
 * annonce de nouveau ses props différées, puis les monte avec celles de la
 * nouvelle période : ce parcours le vérifie dans Chrome, sur le compte des
 * séances de la période et sur le résumé que `BaseChart` écrit des valeurs
 * tracées par la courbe du volume.
 */
class PeriodeDesStatistiquesTest extends DuskTestCase
{
    /**
     * Le nombre que montre la carte « Séances », ou `null` tant que ses props
     * différées ne sont pas là.
     */
    private const string SEANCES_AFFICHEES = <<<'JS'
        const etiquette = [...document.querySelectorAll('div.uppercase')].find((noeud) => noeud.textContent.trim() === 'Séances');
        const valeur = etiquette?.nextElementSibling;
        return valeur && valeur.classList.contains('font-display') ? valeur.textContent.trim() : null;
        JS;

    /**
     * Le résumé que `BaseChart` écrit des valeurs que trace la courbe du volume.
     */
    private const string RESUME_DU_VOLUME = <<<'JS'
        const canevas = document.querySelector('canvas[aria-label="Évolution du volume"]');
        const resume = canevas ? document.getElementById(canevas.getAttribute('aria-describedby')) : null;
        return resume ? resume.textContent.trim() : null;
        JS;

    public function test_choisir_une_autre_periode_montre_ses_seances_et_ses_graphiques(): void
    {
        $this->browse(function (Browser $browser): void {
            $compte = User::factory()->create();
            $fuseau = config()->string('app.timezone');
            $recente = Carbon::now($fuseau)->subDays(3)->setTime(12, 0);
            $ancienne = Carbon::now($fuseau)->subDays(20)->setTime(12, 0);

            foreach ([$recente, $ancienne] as $debut) {
                Workout::factory()->create([
                    'user_id' => $compte->id,
                    'started_at' => $debut,
                    'ended_at' => $debut->copy()->addHour(),
                ]);
            }

            $browser->loginAs($compte)
                ->resizeToIphone15()
                ->visit(route('stats.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitUntil(sprintf('(() => { %s })() === "2"', self::SEANCES_AFFICHEES), 15)
                ->waitUntil(sprintf('(() => { %s })() !== null', self::RESUME_DU_VOLUME), 15);

            $resumeSurTrenteJours = $browser->script(sprintf('return (() => { %s })();', self::RESUME_DU_VOLUME))[0];

            $this->assertIsString($resumeSurTrenteJours);
            $this->assertStringContainsString($recente->format('d/m'), $resumeSurTrenteJours);
            $this->assertStringContainsString($ancienne->format('d/m'), $resumeSurTrenteJours);

            // Un pointeur JavaScript : ni défilement ni attente (`.ai/rules/tests.md`).
            $browser->script(<<<'JS'
                [...document.querySelectorAll('[role="group"][aria-label="Période"] button')]
                    .find((bouton) => bouton.textContent.trim() === '7 JOURS')
                    .click();
                JS);

            $browser->waitUntil('location.search === "?period=7j"', 15)
                ->waitUntil(sprintf('(() => { %s })() === "1"', self::SEANCES_AFFICHEES), 15)
                ->waitUntil(sprintf('(() => { %s })() !== null', self::RESUME_DU_VOLUME), 15);

            $resumeSurSeptJours = $browser->script(sprintf('return (() => { %s })();', self::RESUME_DU_VOLUME))[0];

            $this->assertIsString($resumeSurSeptJours);
            $this->assertStringContainsString($recente->format('d/m'), $resumeSurSeptJours);
            $this->assertStringNotContainsString($ancienne->format('d/m'), $resumeSurSeptJours);
            $this->assertSame(
                'true',
                $browser->script('return String(document.querySelector(\'[role="group"][aria-label="Période"] button[aria-pressed="true"]\')?.textContent.trim() === "7 JOURS");')[0],
            );
        });
    }
}
