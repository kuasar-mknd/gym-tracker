<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The exercise library has to show a creation, and lose a deletion, without
 * being reloaded.
 *
 * ExerciseManagementTest already creates an exercise, but all it waits for is
 * the "Exercice créé avec succès" toast. That toast is flashed by the redirect
 * and appears whether or not the list behind it re-renders, so the test would
 * stay green through exactly the regression this file exists for: the page
 * keeping its stale props, the new exercise showing up only after a reload or a
 * trip through the back button.
 *
 * Two details are load-bearing. The name is matched against textContent rather
 * than with waitForText, because the card styles it uppercase and Selenium's
 * getText() returns rendered text — a plain match fails on the casing instead
 * of on the bug. And the viewport is a phone, because the create button on a
 * desktop viewport is a different element.
 */
class ExerciseListLiveUpdateTest extends DuskTestCase
{
    use DatabaseTruncation;

    /** Matches against textContent so that CSS casing cannot decide the result. */
    private function domContains(string $needle): string
    {
        return 'document.body.textContent.includes('.json_encode($needle).')';
    }

    public function test_a_created_exercise_appears_without_a_reload(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create(['email_verified_at' => now()]);
            $name = 'Tirage horizontal '.random_int(1000, 9999);

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit('/exercises')
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('[dusk="create-exercise-btn"]', 15)
                ->click('[dusk="create-exercise-btn"]')
                ->waitFor('[dusk="exercise-modal-title"]', 15)
                ->type('@exercise-name-input', $name)
                ->select('type', 'strength')
                ->click('@submit-exercise-btn')
                // The list, not the toast.
                ->waitUntil($this->domContains($name), 15);

            $this->assertDatabaseHas('exercises', ['name' => $name, 'user_id' => $user->id]);
        });
    }

    public function test_a_deleted_exercise_leaves_without_a_reload(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create(['email_verified_at' => now()]);
            $exercise = Exercise::factory()->for($user)->create([
                'name' => 'Rowing barre '.random_int(1000, 9999),
            ]);

            // A desktop viewport, unlike the creation test above: on a phone the
            // card hides its delete button behind a swipe, and the gesture is not
            // what this test is about. Stale props do not depend on the width.
            $browser->loginAs($user)
                ->resize(1280, 900)
                ->visit('/exercises')
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitUntil($this->domContains($exercise->name), 15);

            /*
             * La page demande confirmation, et c'est desormais un dialogue de
             * l'APPLICATION. Ce test devait auparavant remplacer `window.confirm`
             * par une fonction qui repond toujours oui, parce que Dusk ne sait
             * pas repondre a une boite native — il verifiait donc la suppression
             * en desactivant la question.
             *
             * Il clique maintenant le vrai bouton, ce qui couvre aussi le fait
             * que la question soit bien posee.
             */
            // The row's own control, not the name: the name lingers elsewhere in
            // the document after the card goes, so a text match would never
            // clear and would report a working deletion as broken.
            $browser->click('[dusk="delete-exercise-btn-'.$exercise->id.'"]')
                ->waitFor('@confirm-dialog-confirm', 10)
                ->click('@confirm-dialog-confirm')
                ->waitUntilMissing('[dusk="delete-exercise-btn-'.$exercise->id.'"]', 15);

            // The row left the screen; this is what says it also left the
            // server, rather than only the optimistic local copy.
            //
            // Worth being straight about what this half does not cover:
            // neutering the props watch that the creation test guards leaves
            // this one green, because the deletion is optimistic and never
            // waits for the server's list. It guards the round trip, not the
            // refresh.
            //
            // Waited for rather than asserted outright. The removal is
            // optimistic: the row leaves the screen the moment it is tapped,
            // before the DELETE has been answered. Reading the table straight
            // after `waitUntilMissing` therefore raced the request, and lost on
            // CI often enough to fail a suite that was otherwise green.
            $this->waitForDatabase(
                fn (): bool => Exercise::query()->whereKey($exercise->id)->doesntExist(),
                15,
                'the exercise left the screen but never left the database'
            );

            // The wait above is what makes this one honest rather than lucky.
            $this->assertDatabaseMissing('exercises', ['id' => $exercise->id]);
        });
    }
}
