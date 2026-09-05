<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class RestTimerTest extends DuskTestCase
{
    use DatabaseTruncation;

    /**
     * L'etat du bouton, tel que l'utilisateur l'entend.
     *
     * Sert a dire ce qu'on a trouve quand l'attente echoue, plutot que de
     * laisser un « Waited 10 seconds for [selector] » qui ne distingue pas un
     * clic perdu d'une bascule dans le mauvais sens.
     */
    private function toggleLabel(Browser $browser): string
    {
        return (string) $browser->attribute('[dusk="complete-set-0-0"]', 'aria-label');
    }

    /**
     * Attend que le bouton porte le libelle voulu, et dit ce qu'il porte sinon.
     */
    private function waitForToggle(Browser $browser, string $label): void
    {
        $browser->waitUsing(
            10,
            100,
            fn (): bool => $this->toggleLabel($browser) === $label,
            "Le bouton de la serie devait porter « {$label} », il porte « {$this->toggleLabel($browser)} »",
        );
    }

    /**
     * Decoche la serie, puis la recoche pour relancer un minuteur de repos.
     *
     * Les deux clics visent la meme bascule, donc le second ne doit pas partir
     * avant que le premier ait ete applique. Attendre l'aria-label du bouton
     * rend cela deterministe : une pause fixe perdait la course des que le
     * runner etait plus lent que le delai.
     *
     * Reste que ce test a flake en CI sur cette meme attente, sans que la cause
     * ait pu etre reproduite en local — huit executions de suite, toutes vertes.
     * Deux choses ont donc ete faites plutot qu'une hypothese :
     *
     * 1. `clickWhenSettled` au lieu de `click`. La macro attend que la boite du
     *    bouton soit stable et que `document.elementFromPoint` en son centre
     *    rende bien ce bouton — c'est-a-dire que rien ne le recouvre. Le
     *    minuteur est `fixed … z-[9999]` et s'etend sur toute la largeur en
     *    mobile : il peut passer par-dessus la ligne de serie. C'est le mode de
     *    defaillance le plus plausible, et la macro le supprime, qu'il soit ou
     *    non la cause.
     *
     * 2. Une attente qui DIT ce qu'elle a trouve. Le message d'echec en CI
     *    etait « Waited 10 seconds for selector [...] », qui ne distingue pas un
     *    clic perdu d'une bascule partie dans le mauvais sens. La prochaine
     *    occurrence nommera le libelle reellement porte, et tranchera.
     */
    /**
     * Les rectangles des commandes du minuteur, mesures par le navigateur.
     *
     * jsdom n'a pas de moteur de rendu : `getBoundingClientRect()` y rend des
     * zeros, et aucun temoin Vitest ne peut donc voir deux boutons se
     * recouvrir. Seul Dusk le peut.
     *
     * @return array<string, array{x: float, y: float, w: float, h: float}>
     */
    private function rectanglesDesCommandes(Browser $browser): array
    {
        /** @var array<string, array{x: float, y: float, w: float, h: float}> */
        return $browser->script(
            <<<'JS'
                const rect = (selecteur) => {
                    const e = document.querySelector(selecteur);
                    if (e === null) { return null; }
                    const b = e.getBoundingClientRect();
                    return { x: b.left, y: b.top, w: b.width, h: b.height };
                };

                return {
                    pause: rect('[aria-label="Pause"], [aria-label="Démarrer le minuteur"]'),
                    fermer: rect('[aria-label="Fermer le minuteur"]'),
                    passer: rect('[dusk="skip-rest-timer"]'),
                };
            JS
        )[0];
    }

    private function retriggerRestTimer(Browser $browser): Browser
    {
        // En instructions plutot qu'en chaine fluide : `tap()` ne declare pas
        // son type de retour, et l'enchainer rendait la methode `mixed`.
        $browser->clickWhenSettled('[dusk="complete-set-0-0"]');
        $this->waitForToggle($browser, 'Valider la série');

        $browser->clickWhenSettled('[dusk="complete-set-0-0"]');
        $this->waitForToggle($browser, 'Annuler la série');

        return $browser->waitFor('[dusk="skip-rest-timer"]', 15);
    }

    private function performTimerLifecycle(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'email' => 'timer-user-'.time().random_int(0, 9999).'@example.com',
            'email_verified_at' => now(),
        ]);

        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'type' => 'strength',
        ]);

        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now(),
        ]);

        try {
            $browser->loginAs(User::find($user->id))
                ->{$sizeMacro}()
                ->visit('/workouts/'.$workout->id)
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->assertMissing('[dusk="skip-rest-timer"]'); // Ensure timer is NOT visible initially

            // Add exercise
            $browser->clickWhenSettled('[dusk="add-first-exercise"]');
            $browser->waitFor('input[placeholder="Rechercher..."]', 10)
                ->type('input[placeholder="Rechercher..."]', $exercise->name)
                ->waitFor('@select-exercise-'.$exercise->id, 10)
                ->clickWhenSettled('[dusk="select-exercise-'.$exercise->id.'"]')
                ->waitFor('@exercise-card-0', 15);

            // Add set
            $browser->clickWhenSettled('[dusk="add-set-0"]');
            $browser->waitFor('@weight-input-0-0', 15)
                ->type('@weight-input-0-0', '50')
                ->type('@reps-input-0-0', '10');

            // 1. Trigger timer by completing set
            $browser->clickWhenSettled('[dusk="complete-set-0-0"]');
            $browser->waitFor('[dusk="skip-rest-timer"]', 15)
                ->assertSee('REPOS');

            /*
             * La croix etait posee en `absolute` PAR-DESSUS le bouton de pause :
             * 28 x 20 px de recouvrement visible, 34 x 26 px de zone tactile.
             * Un appui sur le coin de la pause fermait le minuteur, et la croix
             * se lisait mal sur l'orange.
             */
            $rectangles = $this->rectanglesDesCommandes($browser);

            foreach (['pause', 'fermer', 'passer'] as $nom) {
                $this->assertNotNull($rectangles[$nom] ?? null, "la commande « {$nom} » est absente");
                $this->assertGreaterThanOrEqual(44, $rectangles[$nom]['h'], "« {$nom} » est sous 44 px de haut");
            }

            $pause = $rectangles['pause'];
            $fermer = $rectangles['fermer'];

            $recouvrement = max(0, min($pause['x'] + $pause['w'], $fermer['x'] + $fermer['w']) - max($pause['x'], $fermer['x']))
                * max(0, min($pause['y'] + $pause['h'], $fermer['y'] + $fermer['h']) - max($pause['y'], $fermer['y']));

            $this->assertSame(0.0, (float) $recouvrement, 'la croix et la pause se recouvrent');

            // 2. Close via "X" button — desormais la seule affordance de
            // fermeture, le bouton « Fermer » qui la doublait ayant ete retire.
            $browser->click('@close-timer-x')
                ->waitUntilMissing('[dusk="skip-rest-timer"]', 10);

            // 3. Trigger again and use "Skip" (Passer)
            $this->retriggerRestTimer($browser)
                ->click('@skip-rest-timer')
                ->waitUntilMissing('[dusk="skip-rest-timer"]', 10)
                ->assertNoConsoleExceptions();
        } catch (\Exception $e) {
            $browser->screenshot('timer-failure-'.$sizeMacro);
            throw $e;
        }
    }

    public function test_timer_lifecycle_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performTimerLifecycle($browser, 'resizeToIphoneMini');
        });
    }

    public function test_timer_lifecycle_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performTimerLifecycle($browser, 'resizeToIphone15');
        });
    }

    public function test_timer_lifecycle_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performTimerLifecycle($browser, 'resizeToIphoneMax');
        });
    }
}
