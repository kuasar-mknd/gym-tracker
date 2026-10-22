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

            /** @var array<string, WorkoutLine> $lignes */
            $lignes = [];

            foreach (['Alpha', 'Bravo', 'Charlie'] as $rang => $nom) {
                $lignes[$nom] = WorkoutLine::factory()->create([
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
            //
            // Cette attente ne prouve que l'ecran : la page reordonne ses
            // cartes avant d'envoyer le PATCH (mise a jour optimiste), et
            // l'attente aboutit sans rien savoir du serveur.
            $browser->keys('[dusk="reorder-line-0"]', '{ARROW_DOWN}')
                // `textContent` rend le texte SOURCE : la majuscule vient de
                // `uppercase`, une regle CSS, et ne s'y voit pas.
                ->waitUntil("document.querySelectorAll('[data-line-id]')[1].textContent.includes('Alpha')", 15);

            // Celle-ci prouve l'ecriture : le PATCH api/v1/workouts/{id}/line-order
            // a atteint le serveur, et la base porte la ligne deplacee a son
            // nouveau rang. Recharger sans l'attendre faisait courir la lecture
            // de la page rechargee contre l'ecriture : servies en meme temps,
            // la lecture passait parfois la premiere et montrait l'ancien
            // ordre (#2100). Le serveur ecrit tous les rangs d'un seul UPDATE :
            // quand la ligne deplacee a le sien, l'ordre entier est ecrit, et
            // l'assertion qui suit dit s'il est celui de l'ecran.
            $browser->waitUsing(
                15,
                100,
                fn (): bool => $lignes['Alpha']->fresh()?->order === 1,
                'Apres %s s, la ligne deplacee (Alpha) n’a toujours pas le rang 1 en base : le PATCH '
                .'api/v1/workouts/{id}/line-order n’a pas ecrit l’ordre, et recharger maintenant relirait l’ancien.',
            );

            $this->assertSame(
                [$lignes['Bravo']->id, $lignes['Alpha']->id, $lignes['Charlie']->id],
                $workout->workoutLines()->pluck('id')->all(),
                'le PATCH a ecrit un autre ordre que celui de l’ecran',
            );

            // Le rechargement prouve que la page relit l'ordre ecrit : l'ordre
            // de l'ecran, en memoire, n'y survit pas. La page que le serveur
            // envoie le porte (son `data-page`, lu en base apres l'ecriture), et
            // l'ecran le montre. Le premier point compte : une ecriture coupee
            // par le rechargement part en file, et la page rechargee superpose
            // la file a ce qu'elle recoit ; l'ecran seul pourrait donc montrer
            // le bon ordre sans que le serveur l'ait lu.
            $browser->refresh()
                ->waitFor('@exercise-card-0', 30);

            $this->assertSame(
                ['Bravo', 'Alpha', 'Charlie'],
                $browser->script('return JSON.parse(document.querySelector("script[data-page]").textContent).props.workout.workout_lines.map((ligne) => ligne.exercise.name);')[0],
                'la page rechargee n’a pas recu du serveur l’ordre ecrit',
            );

            $browser->assertSeeIn('[dusk="exercise-card-0"]', 'BRAVO')
                ->assertSeeIn('[dusk="exercise-card-1"]', 'ALPHA')
                ->assertNoConsoleExceptions();
        });
    }
}
