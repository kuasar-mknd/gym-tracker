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
            $browser->click('[data-testid="create-exercise-button"]');
        } else {
            // Header buttons
            $selector = $browser->isVisible('[data-testid="create-exercise-desktop"]')
                ? '[data-testid="create-exercise-desktop"]'
                : '[data-testid="create-exercise-mobile-header"]';
            $browser->click($selector);
        }

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
        // On mobile, the edit button is visible. On desktop, it shows on hover.
        $browser->click('[data-testid="exercise-card"]'); // Open detail or focus

        // Use JS to click the edit button reliably across mobile/desktop
        $browser->script("document.querySelector('[aria-label^=\"Modifier\"]').click();");

        $updatedName = 'UPDATED EXERCISE '.time();
        $browser->waitFor('input[placeholder="Nom de l\'exercice"]', 10)
            ->clear('input[placeholder="Nom de l\'exercice"]')
            ->type('input[placeholder="Nom de l\'exercice"]', $updatedName)
            ->click('[data-testid="save-exercise-button"]');

        // 5. Verify update
        $browser->waitForText(strtoupper($updatedName), 15);

        // 6. Delete the exercise
        // Use JS click for the mobile delete button (which is in the SwipeableRow or detail)
        $browser->script("document.querySelector('[data-testid=\"delete-exercise-button-mobile\"]').click();");

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
