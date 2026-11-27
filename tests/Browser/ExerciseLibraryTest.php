<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ExerciseLibraryTest extends DuskTestCase
{
    use DatabaseTruncation;

    /**
     * WebDriver ne sait pas glisser : le focus clavier ouvre la rangée comme le
     * geste (SwipeableRow). Il est redonné à chaque essai, la fermeture de la
     * fenêtre de création pouvant le reprendre après coup.
     */
    private function glisserJusquA(Browser $browser, string $action): void
    {
        $browser->waitUsing(10, 250, fn (): bool => (bool) $browser->script(
            "const action = document.querySelector(\"{$action}\"); action.focus();"
            .' return action.closest("[data-panneau]").getBoundingClientRect().width > 60;'
        )[0], "la rangée ne s'est pas ouverte pour {$action}");
    }

    /**
     * Test the full lifecycle of exercise management in the library.
     */
    private function performExerciseLibraryLifecycle(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'email' => 'library-'.time().random_int(0, 999).'@example.com',
            'password' => bcrypt('password'),
        ]);

        // Create some initial exercises
        Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Bench Press', 'category' => 'Pectoraux']);
        Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Squat', 'category' => 'Jambes']);
        Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Deadlift', 'category' => 'Dos']);

        try {
            $browser->loginAs(User::findOrFail($user->id))
                ->{$sizeMacro}()
                ->visit('/exercises')
                ->disableAnimations()
                ->waitFor('#main-content', 30);

            // 1. Check listing
            $browser->assertSee('Bench Press')
                ->assertSee('Squat')
                ->assertSee('Deadlift');

            // 2. Test Search
            $browser->type('@search-exercises', 'Bench')
                ->waitUntilMissingText('Squat', 10)
                ->assertSee('Bench Press')
                ->assertDontSee('Squat')
                ->assertDontSee('Deadlift');

            $browser->clear('@search-exercises')
                ->type('@search-exercises', ' ') // Clear properly
                ->keys('@search-exercises', ['{backspace}'])
                ->waitForText('Squat', 10);

            // 3. Test Filter by Category
            // Le filtrage est un re-rendu Vue : attendre son résultat, pas une durée.
            // Avec un simple pause(), l'assertion pouvait porter sur le DOM d'avant
            // le filtrage et passer au vert sans que le filtre ait rien fait.
            $browser->click('@category-pill-Jambes')
                ->waitForText('Squat', 10)
                ->waitUntilMissingText('Bench Press', 10)
                ->assertSee('Squat')
                ->assertDontSee('Bench Press')
                ->assertDontSee('Deadlift');

            $browser->click('@category-pill-all')
                ->waitForText('Bench Press', 10)
                ->assertSee('Bench Press');

            // 4. Create Exercise
            $newExerciseName = 'Pull Up '.time().random_int(0, 999);
            $browser->click('@create-exercise-btn')
                ->waitFor('@exercise-modal-title', 15)
                ->type('@exercise-name-input', $newExerciseName)
                ->select('type', 'strength')
                ->select('form select:last-of-type', 'Dos') // Category select
                ->click('@submit-exercise-btn')
                ->waitUntilMissing('@exercise-modal-title', 15)
                ->waitForText($newExerciseName, 15);

            // Le nom affiché ne prouve pas l'écriture : Inertia rend la réponse du
            // serveur, mais rien ici ne distinguait un rendu optimiste d'une vraie
            // création. On vérifie la ligne, et les attributs saisis avec elle.
            $this->assertDatabaseHas('exercises', [
                'name' => $newExerciseName,
                'user_id' => $user->id,
                'type' => 'strength',
            ]);

            // 5. Edit Exercise (Inline)
            $exercise = Exercise::where('name', $newExerciseName)->firstOrFail();
            $updatedName = 'Updated Pull Up '.time().random_int(0, 999);

            // Au doigt, la modification est derrière le glissement ; WebDriver ne sait pas glisser,
            // mais le focus clavier ouvre la rangée exactement comme lui (SwipeableRow).
            $actionDeModification = "[dusk='swipe-edit-exercise-{$exercise->id}']";
            $this->glisserJusquA($browser, $actionDeModification);
            $browser->clickWhenSettled($actionDeModification);
            $browser->waitFor('@edit-exercise-name', 10)
                ->type('@edit-exercise-name', $updatedName)
                ->click('@save-exercise-btn')
                ->waitUntilMissing('@edit-exercise-name', 10)
                ->assertSee($updatedName);

            $this->waitForDatabase(
                fn (): bool => $exercise->fresh()?->name === $updatedName,
                message: 'le renommage est resté à l\'écran sans atteindre la base'
            );

            // 6. Delete Exercise
            // Même chemin que la modification : l'action de glissement, atteinte au focus.
            $actionDeSuppression = "[dusk='swipe-delete-exercise-{$exercise->id}']";
            $this->glisserJusquA($browser, $actionDeSuppression);
            $browser->clickWhenSettled($actionDeSuppression);

            // La confirmation est un dialogue de l'application, plus la boite
            // native : Dusk clique un bouton au lieu de repondre au navigateur.
            $browser->waitFor('@confirm-dialog-confirm', 10)
                ->click('@confirm-dialog-confirm')
                ->waitUntilMissingText($updatedName, 10)
                ->assertDontSee($updatedName);

            // Disparaître de l'écran n'est pas être supprimé : sans cette
            // vérification, un retrait purement côté client passait au vert.
            $this->waitForDatabase(
                fn (): bool => $exercise->fresh() === null,
                message: 'l\'exercice a disparu de l\'écran mais pas de la base'
            );
        } catch (\Exception $e) {
            $browser->screenshot('exercise-library-lifecycle-failure-'.$sizeMacro);
            throw $e;
        }
    }

    public function test_exercise_library_full_lifecycle_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performExerciseLibraryLifecycle($browser, 'resizeToIphoneMini');
        });
    }

    public function test_exercise_library_full_lifecycle_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performExerciseLibraryLifecycle($browser, 'resizeToIphone15');
        });
    }

    public function test_exercise_library_full_lifecycle_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performExerciseLibraryLifecycle($browser, 'resizeToIphoneMax');
        });
    }

    /**
     * Test responsiveness and layout on different mobile sizes.
     */
    public function test_exercise_library_responsive_layout(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();
            Exercise::factory()->count(10)->create(['user_id' => $user->id]);

            $sizes = ['resizeToIphoneMini', 'resizeToIphone15', 'resizeToIphoneMax'];

            foreach ($sizes as $size) {
                $browser->loginAs(User::findOrFail($user->id))
                    ->{$size}()
                    ->visit('/exercises')
                    ->disableAnimations()
                    ->waitFor('#main-content', 30)
                    ->waitFor('@search-exercises', 10)
                    ->assertVisible('@create-exercise-btn')
                    ->assertVisible('@search-exercises')
                    ->assertNoConsoleExceptions();
            }
        });
    }
}
