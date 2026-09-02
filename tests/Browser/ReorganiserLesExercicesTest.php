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
                ->waitFor('@exercise-card-0', 30);

            // Le glissement au doigt ne se pilote pas par WebDriver ; la
            // poignee accepte aussi les fleches, et c'est le meme chemin
            // d'ecriture.
            $browser->keys('[dusk="reorder-line-0"]', '{ARROW_DOWN}')
                // `textContent` rend le texte SOURCE : la majuscule vient de
                // `uppercase`, une regle CSS, et ne s'y voit pas.
                ->waitUntil("document.querySelectorAll('[data-line-id]')[1].textContent.includes('Alpha')", 15);

            // L'ordre a bien ete ecrit : c'est la seule preuve que le PATCH a
            // atterri, et qu'il portait le bon ordre.
            $browser->refresh()
                ->waitFor('@exercise-card-0', 30)
                ->assertSeeIn('[dusk="exercise-card-0"]', 'BRAVO')
                ->assertSeeIn('[dusk="exercise-card-1"]', 'ALPHA')
                ->assertNoConsoleExceptions();
        });
    }
}
