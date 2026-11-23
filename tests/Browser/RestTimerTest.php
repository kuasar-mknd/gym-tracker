<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class RestTimerTest extends DuskTestCase
{
    use DatabaseTruncation;

    /**
     * L'etat enfonce de la coche (`aria-pressed`, « true » ou « false »).
     *
     * Sert a dire ce qu'on a trouve quand l'attente echoue, plutot que de
     * laisser un « Waited 10 seconds for [selector] » qui ne distingue pas un
     * clic perdu d'une bascule dans le mauvais sens.
     */
    private function togglePressed(Browser $browser): string
    {
        return (string) $browser->attribute('[dusk="complete-set-0-0"]', 'aria-pressed');
    }

    /**
     * Attend que la coche porte l'etat voulu, et dit ce qu'elle porte sinon.
     */
    private function waitForToggle(Browser $browser, string $label): void
    {
        $browser->waitUsing(
            10,
            100,
            fn (): bool => $this->togglePressed($browser) === $label,
            "La coche de la serie devait valoir « {$label} », elle vaut « {$this->togglePressed($browser)} »",
        );
    }

    /**
     * Attend que la base porte la coche que l'ecran montre.
     *
     * La bascule est optimiste : le libelle change avant la reponse du serveur,
     * et `waitForToggle()` ne prouve que l'ecran. Chaque coche attend donc la
     * sienne en base avant la suivante : la base porte alors la derniere, et non
     * une precedente de meme valeur. La derniere s'attend aussi avant la fin :
     * arrivee au serveur apres coup, elle s'ecrirait dans la base que le
     * parcours suivant vient de vider, qui reprend ses identifiants a 1 (#2100).
     * La base atteinte ne retire pas l'ecriture de la page : une reponse encore
     * en route quand la page suivante s'ouvre la laisse en file, au nom du meme
     * identifiant de compte. `DuskTestCase` arrete la page et vide sa file avant
     * de rendre le navigateur au parcours suivant.
     */
    private function waitForCompletionInDatabase(Browser $browser, Workout $seance, bool $validee): void
    {
        $browser->waitUsing(
            15,
            100,
            fn (): bool => Set::query()
                ->whereHas('workoutLine', fn ($ligne) => $ligne->where('workout_id', $seance->id))
                ->first()?->is_completed === $validee,
            $validee
                ? 'Apres %s s, la serie n’est toujours pas validee en base : la coche est restee a l’ecran'
                : 'Apres %s s, la serie est toujours validee en base : la decoche est restee a l’ecran',
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
     * Les rectangles des trois boutons de la barre d'outils, mesures par le
     * navigateur.
     *
     * jsdom n'a pas de moteur de rendu : `getBoundingClientRect()` y rend des
     * zeros, et aucun temoin Vitest ne peut donc voir deux boutons se
     * recouvrir. Seul Dusk le peut.
     *
     * @return array<string, array{x: float, y: float, w: float, h: float}|null>
     */
    private function rectanglesDeLaBarre(Browser $browser): array
    {
        /** @var array<string, array{x: float, y: float, w: float, h: float}|null> */
        return $browser->script(
            <<<'JS'
                const rect = (selecteur) => {
                    const e = document.querySelector(selecteur);
                    if (e === null) { return null; }
                    const b = e.getBoundingClientRect();
                    return { x: b.left, y: b.top, w: b.width, h: b.height };
                };

                return {
                    exercice: rect('[dusk="add-exercise-existing"]'),
                    repos: rect('[dusk="rest-timer"]'),
                    terminer: rect('[dusk="finish-workout-mobile"]'),
                };
            JS
        )[0];
    }

    private function retriggerRestTimer(Browser $browser, Workout $seance): Browser
    {
        // En instructions plutot qu'en chaine fluide : `tap()` ne declare pas
        // son type de retour, et l'enchainer rendait la methode `mixed`.
        $browser->clickWhenSettled('[dusk="complete-set-0-0"]');
        $this->waitForToggle($browser, 'false');
        $this->waitForCompletionInDatabase($browser, $seance, false);

        $browser->clickWhenSettled('[dusk="complete-set-0-0"]');
        $this->waitForToggle($browser, 'true');
        $this->waitForCompletionInDatabase($browser, $seance, true);

        return $browser->waitFor('[dusk="rest-timer"]', 15);
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
            $browser->loginAs(User::findOrFail($user->id))
                ->{$sizeMacro}()
                ->visit('/workouts/'.$workout->id)
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->assertMissing('[dusk="rest-timer"]'); // Ensure timer is NOT visible initially

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
            $browser->waitFor('[dusk="rest-timer"]', 15)
                ->assertPresent('[dusk="anneau-de-la-barre"], [dusk="rest-timer"]');
            $this->waitForCompletionInDatabase($browser, $workout, true);

            /*
             * Le bouton « Repos » est devenu l'anneau et le decompte, dans la
             * barre : les trois boutons gardent 44 px de haut et ne se
             * recouvrent pas.
             */
            $rectangles = $this->rectanglesDeLaBarre($browser);

            foreach (['exercice', 'repos', 'terminer'] as $nom) {
                $this->assertNotNull($rectangles[$nom] ?? null, "le bouton « {$nom} » est absent");
                $this->assertGreaterThanOrEqual(44, $rectangles[$nom]['h'], "« {$nom} » est sous 44 px de haut");
            }

            $this->assertLessThanOrEqual(
                $rectangles['repos']['x'] + 0.5,
                $rectangles['exercice']['x'] + $rectangles['exercice']['w'],
                'Exercice recouvre le decompte'
            );
            $this->assertLessThanOrEqual(
                $rectangles['terminer']['x'] + 0.5,
                $rectangles['repos']['x'] + $rectangles['repos']['w'],
                'le decompte recouvre Terminer'
            );

            // 2. Un toucher ouvre la feuille ; Passer arrete le repos.
            $browser->click('@rest-timer')
                ->waitFor('@skip-rest-timer', 10)
                ->click('@skip-rest-timer')
                ->waitUntilMissing('[dusk="rest-timer"]', 10);

            // 3. Trigger again and use "Skip" (Passer)
            $this->retriggerRestTimer($browser, $workout)
                ->click('@rest-timer')
                ->waitFor('@skip-rest-timer', 10)
                ->click('@skip-rest-timer')
                ->waitUntilMissing('[dusk="rest-timer"]', 10)
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
