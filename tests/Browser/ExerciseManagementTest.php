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
            ->disableAnimations()
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
        $browser->script("
            const cards = document.querySelectorAll('[data-testid=\"exercise-card\"]');
            const targetCard = Array.from(cards).find(c => {
                const text = c.textContent.trim().toUpperCase();
                return text.includes('".strtoupper($exerciseName)."');
            });
            if (targetCard) {
                targetCard.scrollIntoView();
                targetCard.click();
            }
        ");

        $browser->pause(1500)
            ->script("
                const spans = document.querySelectorAll('span');
                const editBtn = Array.from(spans).find(s => s.textContent.trim().toUpperCase() === 'MODIFIER');
                if (editBtn) editBtn.click();
            ");

        $updatedName = 'UPDATED EXERCISE '.time();
        $browser->waitFor('input[placeholder="Nom de l\'exercice"]', 10)
            ->clear('input[placeholder="Nom de l\'exercice"]')
            ->type('input[placeholder="Nom de l\'exercice"]', $updatedName)
            ->script("
                const btns = document.querySelectorAll('button');
                const saveBtn = Array.from(btns).find(b => {
                    const text = b.textContent.trim().toUpperCase();
                    return text.includes('ENREGISTRER') || b.getAttribute('data-testid') === 'save-exercise-button';
                });
                if (saveBtn) saveBtn.click();
            ");

        // 5. Verify update
        $browser->waitForText(strtoupper($updatedName), 15);

        // 6. Delete the exercise
        $browser->script("
            const deleteBtn = document.querySelector('[data-testid=\"delete-exercise-button-mobile\"]');
            if (deleteBtn) {
                deleteBtn.scrollIntoView();
                deleteBtn.click();
            }
        ");

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
