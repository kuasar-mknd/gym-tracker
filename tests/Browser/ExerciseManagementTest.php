<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Dusk\Browser;

test('user can manage exercises', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        // Start with desktop view for reliability
        $browser->loginAs($user)
            ->resize(1280, 800)
            ->visit('/exercises')
            ->waitFor('main', 15)
            ->assertPathIs('/exercises')

            // 1. Verify empty state and create button
            ->waitFor('[data-testid="create-exercise-button"]', 15)
            ->click('[data-testid="create-exercise-button"]');

        // 2. Fill and submit the create form
        $browser->waitFor('input[placeholder="Ex: Développé couché"]', 15)
            ->pause(500) // Ensure transition matches
            ->type('input[placeholder="Ex: Développé couché"]', 'Dusk Test Exercise')
            ->waitFor('select', 5)
            ->select('select', 'strength')
            ->waitFor('[data-testid="submit-exercise-button"]', 5)
            ->click('[data-testid="submit-exercise-button"]');

        // 3. Verify exercise was created
        $browser->pause(1000)
            ->waitForText('Dusk Test Exercise', 15);

        // 4. Edit the exercise
        $browser->waitFor('[data-testid="edit-exercise-button"]', 5)
            ->click('[data-testid="edit-exercise-button"]');

        $browser->waitFor('input[type="text"]', 10)
            ->pause(500)
            ->clear('input[type="text"]')
            ->type('input[type="text"]', 'Updated Exercise')
            ->waitFor('[data-testid="save-exercise-button"]', 5)
            ->click('[data-testid="save-exercise-button"]');

        // 5. Verify update
        $browser->pause(1000)
            ->waitForText('Updated Exercise', 15);

        // 6. Delete the exercise
        $browser->waitFor('[data-testid="delete-exercise-button"]', 5)
            ->click('[data-testid="delete-exercise-button"]');

        $browser->assertDialogOpened('Supprimer cet exercice ?')
            ->acceptDialog()
            ->pause(1000)
            ->waitFor('[data-testid="create-exercise-button"]', 15)
            ->assertNoConsoleExceptions();
    });
});
