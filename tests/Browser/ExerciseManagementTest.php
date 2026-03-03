<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;

uses(DatabaseTruncation::class);

test('user can manage exercises on different iphone sizes', function (string $sizeMacro): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user, $sizeMacro): void {
        $browser->loginAs($user)
            ->{$sizeMacro}()
            ->visit('/exercises')
            ->waitFor('main', 30)
            ->assertPathIs('/exercises');

        // 1. Click Create Button (Desktop or Mobile Header depending on viewport)
        // If empty state is visible, it uses create-exercise-button
        if ($browser->resolver->find('[data-testid="create-exercise-button"]')) {
            $browser->script("document.querySelector('[data-testid=\"create-exercise-button\"]').click();");
        } else {
            // Header buttons
            $selector = $browser->isVisible('[data-testid="create-exercise-desktop"]')
                ? '[data-testid="create-exercise-desktop"]'
                : '[data-testid="create-exercise-mobile-header"]';
            $browser->script("document.querySelector('$selector').click();");
        }

        // 2. Fill and submit the create form
        $exerciseName = 'DUSK TEST EXERCISE '.time();
        $browser->waitForText('NOUVEL EXERCICE', 30)
            ->type('input[placeholder="Ex: Développé couché"]', $exerciseName)
            ->waitFor('select', 10)
            ->select('select', 'strength')
            ->script("document.querySelector('[data-testid=\"submit-exercise-button\"]').click();");

        // 3. Verify exercise was created
        $browser->waitFor('[data-testid="exercise-card"]', 30)
            ->assertSee(strtoupper($exerciseName));

        // 4. Edit the exercise
        $browser->waitFor('[data-testid="edit-exercise-button-mobile-icon"], [data-testid="edit-exercise-button-desktop"]', 20)
            ->script("
                const mobileBtn = document.querySelector('[data-testid=\"edit-exercise-button-mobile-icon\"]');
                const desktopBtn = document.querySelector('[data-testid=\"edit-exercise-button-desktop\"]');
                if (mobileBtn && getComputedStyle(mobileBtn).display !== 'none') {
                    mobileBtn.click();
                } else if (desktopBtn) {
                    desktopBtn.click();
                }
            ");

        $updatedName = 'UPDATED EXERCISE '.time();
        $browser->waitFor('input[placeholder="Nom de l\'exercice"]', 20)
            ->clear('input[placeholder="Nom de l\'exercice"]')
            ->type('input[placeholder="Nom de l\'exercice"]', $updatedName)
            ->script("document.querySelector('[data-testid=\"save-exercise-button\"]').click();");

        // 5. Verify update
        $browser->waitForText(strtoupper($updatedName), 15);

        // 6. Delete the exercise
        $browser->waitFor('[data-testid="delete-exercise-button-mobile"], [data-testid="delete-exercise-button-desktop"]', 20)
            ->script("
                const desktopDel = document.querySelector('[data-testid=\"delete-exercise-button-desktop\"]');
                const mobileDel = document.querySelector('[data-testid=\"delete-exercise-button-mobile\"]');
                if (desktopDel && getComputedStyle(desktopDel).display !== 'none') {
                    desktopDel.click();
                } else if (mobileDel) {
                    mobileDel.click();
                }
            ");
        $browser->assertDialogOpened('Supprimer cet exercice ?')
            ->acceptDialog()
            ->waitFor('main', 15)
            ->assertNoConsoleExceptions();
    });
})->with([
    'iPhone Mini' => 'resizeToIphoneMini',
    'iPhone 15' => 'resizeToIphone15',
    'iPhone Pro Max' => 'resizeToIphoneMax',
]);
