<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A write the server refuses has to say so on screen.
 *
 * Every failure path on the session screen used to do the same two things: put
 * the optimistic row back, and buzz. On a phone that is a vibration with no
 * words; on a desktop it is nothing at all. So a server rejecting every write
 * was indistinguishable from a mis-tap — which is how an afternoon went by with
 * the database one migration behind, the app quietly undoing everything the
 * user did.
 *
 * The failure here is a real one rather than a stub: the session is dropped, so
 * the API answers 401 exactly as it did in production. Nothing about the page
 * is mocked.
 */
class SyncFailureIsVisibleTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_a_refused_add_says_so_rather_than_vanishing(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create(['email_verified_at' => now()]);
            $exercise = Exercise::factory()->for($user)->create(['name' => 'Développé Couché']);

            $workout = new Workout();
            $workout->forceFill(['user_id' => $user->id, 'started_at' => now()])->save();

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit('/workouts/'.$workout->id)
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitForText('AJOUTER UN EXERCICE', 15);

            // Drops the session out from under the page, so the next API call
            // gets a genuine 401 from the real stack.
            $browser->visit('/_dusk/logout')
                ->back()
                ->waitFor('#main-content', 30);

            $browser->press('AJOUTER UN EXERCICE')
                ->waitForText($exercise->name, 15)
                ->press($exercise->name)
                // The message is the assertion. Before this existed the row
                // simply disappeared again and the screen said nothing.
                ->waitForText('n’a pas pu être ajouté', 15)
                ->assertSee('Réessaie');
        });
    }
}
