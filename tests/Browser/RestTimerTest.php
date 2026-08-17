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
     * Remaining rest time, in seconds, read from the "M:SS" timer.
     */
    private function timerSecondsLeft(Browser $browser): int
    {
        [$minutes, $seconds] = array_map(intval(...), explode(':', trim($browser->text('[role="timer"]'))));

        return $minutes * 60 + $seconds;
    }

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
            $browser->waitFor('[dusk="add-first-exercise"]', 15)
                ->pause(500)
                ->click('[dusk="add-first-exercise"]')
                ->waitFor('input[placeholder="Rechercher..."]', 10)
                ->type('input[placeholder="Rechercher..."]', $exercise->name)
                ->waitFor('@select-exercise-'.$exercise->id, 10)
                ->pause(500)
                ->click('@select-exercise-'.$exercise->id)
                ->waitFor('@exercise-card-0', 15);

            // Add set
            $browser->pause(500)
                ->click('[dusk="add-set-0"]')
                ->waitFor('@weight-input-0-0', 15)
                ->type('@weight-input-0-0', '50')
                ->pause(300)
                ->type('@reps-input-0-0', '10')
                ->pause(500);

            // 1. Trigger timer by completing set
            $browser->click('[dusk="complete-set-0-0"]')
                ->waitFor('[dusk="skip-rest-timer"]', 15)
                ->assertSee('REPOS');

            // 2. Add Time (+30s) — the countdown is ticking down, so the only stable
            // fact is that the remaining time went UP. Waiting on that condition also
            // removes the race a fixed pause left open on a slow runner.
            $secondsLeft = fn (): int => $this->timerSecondsLeft($browser);
            $before = $secondsLeft();

            $browser->click('@add-30s')
                ->waitUsing(10, 100, fn (): bool => $secondsLeft() > $before, 'Le minuteur n’a pas augmenté après +30s');

            // 3. Close via "X" button
            $browser->click('@close-timer-x')
                ->waitUntilMissing('[dusk="skip-rest-timer"]', 10);

            // 4. Trigger again and close via "Fermer" button
            $this->retriggerRestTimer($browser)
                ->click('@close-timer')
                ->waitUntilMissing('[dusk="skip-rest-timer"]', 10);

            // 5. Trigger again and use "Skip" (Passer)
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
