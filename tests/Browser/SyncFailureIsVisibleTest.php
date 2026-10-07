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

            /*
             * Retire la session sous la page, sans la quitter : le prochain
             * appel à l'API reçoit un vrai 401 de la pile entière.
             *
             * Le parcours passait par `visit('/_dusk/logout')` puis `back()`,
             * et comptait sur le navigateur pour rendre la séance de mémoire
             * après la déconnexion. C'est ce que #1965 ferme : une page de
             * compte sort en `no-store`, et revient donc du serveur, qui
             * renvoie vers la connexion. La déconnexion part ici de la page
             * elle-même, qui reste affichée.
             */
            $statut = $browser->driver->executeAsyncScript(
                'const fini = arguments[arguments.length - 1];'
                ."fetch('/_dusk/logout', { credentials: 'same-origin' }).then((reponse) => fini(reponse.status), () => fini(0));"
            );

            $this->assertSame(200, $statut, 'la déconnexion de Dusk n’a pas abouti');

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
