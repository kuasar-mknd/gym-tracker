<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ExerciseManagementTest extends DuskTestCase
{
    use DatabaseTruncation;

    private function performExerciseManagement(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $browser->loginAs($user->id)
            ->{$sizeMacro}()
            ->visit('/exercises')
            ->waitFor('@main-content', 30)
            ->assertPathIs('/exercises');

        // 1. Click Create Button (Desktop or Mobile Header depending on viewport)
        $browser->waitFor('[data-testid="create-exercise-mobile-header"]', 15)
            ->click('[data-testid="create-exercise-mobile-header"]');

        // 2. Fill and submit the create form
        $exerciseName = 'DUSK TEST EXERCISE '.time();
        $browser->waitForText('NOUVEL EXERCICE', 30)
            ->type('input[placeholder="Ex: Développé couché"]', $exerciseName)
            ->waitFor('select', 10)
            ->select('select', 'strength')
            ->click('[data-testid="submit-exercise-button"]');

        // 3. Verify exercise was created
        $browser->waitFor('[data-testid="exercise-card"]', 30)
            ->assertSee(strtoupper($exerciseName));

        // 4. Edit the exercise
        $browser->script("document.querySelector('[data-testid=\"exercise-card\"]').scrollIntoView();");
        $browser->click('[data-testid="exercise-card"]');

        $browser->waitForText('MODIFIER', 15)
            ->click('[aria-label^="Modifier"]');

        $updatedName = 'UPDATED EXERCISE '.time();
        $browser->waitFor('input[placeholder="Nom de l\'exercice"]', 10)
            ->clear('input[placeholder="Nom de l\'exercice"]')
            ->type('input[placeholder="Nom de l\'exercice"]', $updatedName)
            ->click('[data-testid="save-exercise-button"]');

        // 5. Verify update
        $browser->waitForText(strtoupper($updatedName), 15);

        // 6. Delete the exercise
        $browser->script("document.querySelector('[data-testid=\"delete-exercise-button-mobile\"]').scrollIntoView();");
        $browser->waitFor('[data-testid="delete-exercise-button-mobile"]', 15)
            ->click('[data-testid="delete-exercise-button-mobile"]');

        $browser->assertDialogOpened('Supprimer cet exercice ?')
            ->acceptDialog()
            ->waitFor('@main-content', 15)
            ->assertNoConsoleExceptions();
    }

    public function test_exercise_management_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performExerciseManagement($browser, 'resizeToIphoneMini');
        });
    }

    public function test_exercise_management_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performExerciseManagement($browser, 'resizeToIphone15');
        });
    }

    public function test_exercise_management_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performExerciseManagement($browser, 'resizeToIphoneMax');
        });
    }
}
