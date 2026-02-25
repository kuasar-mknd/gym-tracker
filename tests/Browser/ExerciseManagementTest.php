<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;

uses(DatabaseMigrations::class);

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
            ->waitFor('[data-testid="create-exercise-desktop"]', 15)
            ->script("document.querySelector('[data-testid=\"create-exercise-desktop\"]').click();");

        // 2. Fill and submit the create form
        $browser->pause(500)
            ->waitForText('NOUVEL EXERCICE', 15) // Case sensitive check or wait for element
            ->type('input[placeholder="Ex: Développé couché"]', 'Dusk Test Exercise')
            ->waitFor('select', 5)
            ->select('select', 'strength')
            ->waitFor('[data-testid="submit-exercise-button"]', 5)
            ->pause(500) // Ensure Vue state sync before click
            ->click('[data-testid="submit-exercise-button"]');

        // 3. Verify exercise was created
        $browser->waitForText('DUSK TEST EXERCISE', 15);

        // 4. Edit the exercise
        $browser->mouseover('[data-testid="exercise-card"]')
            ->pause(500)
            ->script("const btn = document.querySelector('[data-testid=\"edit-exercise-button\"]'); btn.dispatchEvent(new Event('click', {bubbles: false}));");

        $browser->waitFor('input[type="text"]', 10)
            ->pause(500)
            ->clear('input[type="text"]')
            ->type('input[type="text"]', 'Updated Exercise')
            ->click('[data-testid="save-exercise-button"]');

        // 5. Verify update
        $browser->pause(1000)
            ->waitForText('UPDATED EXERCISE', 15);

        // 6. Delete the exercise
        $browser->mouseover('[data-testid="exercise-card"]')
            ->pause(500)
            ->script("const btn = document.querySelector('[data-testid=\"delete-exercise-button\"]'); btn.dispatchEvent(new Event('click', {bubbles: false}));");

        $browser->assertDialogOpened('Supprimer cet exercice ?')
            ->acceptDialog()
            ->pause(1000)
            ->waitFor('[data-testid="create-exercise-desktop"]', 15)
            ->assertNoConsoleExceptions();
    });
});
