<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;

uses(DatabaseTruncation::class);

test('user can manage exercises from mobile plus menu', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->resize(375, 812) // Mobile view
            ->visit('/dashboard')
            ->assertPathIs('/dashboard')
            ->waitFor('.glass-nav', 15)
            ->waitFor('a[aria-label="Plus"]', 15)

            // 1. Navigate to "Plus" menu
            ->click('a[aria-label="Plus"]')
            ->waitForLocation('/profile')
            ->waitForText('Exercices', 15)
            ->assertPathIs('/profile')

            // 2. Click "Exercices" in Navigation section
            ->clickLink('Exercices')
            ->waitForLocation('/exercises')
            ->assertPathIs('/exercises')

            // 3. Add a new exercise
            ->waitForText('AUCUN EXERCICE', 15)
            ->script("document.querySelector('[data-testid=\"create-exercise-button\"]').click();");

        $browser->waitForText('NOUVEL EXERCICE', 15)
            ->type('input[placeholder="Ex: Développé couché"]', 'Dusk Test Exercise')
            ->select('select', 'strength')
            ->script("document.querySelector('[data-testid=\"submit-exercise-button\"]').click();");

        $browser->waitForText('DUSK TEST EXERCISE', 15);

        // 4. Edit the exercise
        $browser->script("document.querySelector('[data-testid=\"edit-exercise-button\"]').click();");

        $browser->waitForText('SAUVEGARDER', 15)
            ->type('input[value="Dusk Test Exercise"]', 'Dusk Test Exercise Updated');

        $browser->script("document.querySelector('[data-testid=\"save-exercise-button\"]').click();");

        $browser->waitForText('DUSK TEST EXERCISE UPDATED', 15);

        // 5. Delete the exercise
        $browser->script("document.querySelector('[data-testid=\"delete-exercise-button\"]').click();");

        $browser->assertDialogOpened('Supprimer cet exercice ?')
            ->acceptDialog()
            ->waitForText('AUCUN EXERCICE', 15);
    });
});
