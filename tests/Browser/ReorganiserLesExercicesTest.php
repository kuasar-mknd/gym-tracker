<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Ce que Vitest ne peut pas porter.
 *
 * jsdom n'a ni moteur de rendu ni pointeur : `getBoundingClientRect()` y rend
 * zero partout, et SortableJS y est bouchonne — aucun clone n'est jamais cree.
 * Restent donc pour ici : la persistance apres rechargement, et le fait que le
 * mode masque bien les cartes sans les detruire.
 *
 * Ce que ce fichier ne prouve PAS, et qu'il ne faut pas lui faire dire :
 * `DuskTestCase` force `prefers-reduced-motion`, et la charte ecrase alors
 * toute transition. Un « ca ne clignote plus » valide ici n'aurait rien
 * demontre — cette preuve-la se fait au doigt, sur le simulateur.
 */
class ReorganiserLesExercicesTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_reordering_survives_a_reload(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();
            $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);

            foreach (['Alpha', 'Bravo', 'Charlie'] as $rang => $nom) {
                WorkoutLine::factory()->create([
                    'workout_id' => $workout->id,
                    'exercise_id' => Exercise::factory()->create(['name' => $nom, 'user_id' => $user->id])->id,
                    'order' => $rang,
                ]);
            }

            $browser->loginAs($user)
                ->visit('/workouts/'.$workout->id)
                ->disableAnimations()
                ->waitFor('@exercise-card-0', 30)
                ->assertMissing('@reorder-list');

            // 1. La poignee ouvre le mode, et les cartes se replient d'un coup
            //    — avant tout geste, pas pendant.
            $browser->click('@reorder-line-0')
                ->waitFor('@reorder-list', 15)
                ->assertSeeIn('[dusk="reorder-row-0"]', 'ALPHA');

            // Masquees, pas detruites : les champs de saisie doivent garder leur
            // etat et leur focus.
            $browser->assertMissing('@add-set-0')
                ->assertPresent('[dusk="exercise-card-0"]');

            // 2. Descendre le premier exercice, par la fleche — le glissement
            //    au doigt ne se pilote pas par WebDriver.
            $browser->click('@reorder-down-0')
                ->waitUntil("document.querySelector('[dusk=\"reorder-row-1\"]').textContent.includes('ALPHA')", 15);

            // 3. Sortir du mode rend les cartes.
            $browser->click('@finish-reorder')
                ->waitFor('@add-set-0', 15)
                ->assertMissing('@reorder-list');

            // 4. Et l'ordre a bien ete ecrit : c'est la seule preuve que le
            //    PATCH a atterri, et qu'il portait le bon ordre.
            $browser->refresh()
                ->waitFor('@exercise-card-0', 30)
                ->assertSeeIn('[dusk="exercise-card-0"]', 'BRAVO')
                ->assertSeeIn('[dusk="exercise-card-1"]', 'ALPHA')
                ->assertNoConsoleExceptions();
        });
    }
}
