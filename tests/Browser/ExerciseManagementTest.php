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
            ->waitFor('[data-testid="create-exercise-button"]', 15)
            ->click('[data-testid="create-exercise-button"]');

        // 2. Fill and submit the create form
        $exerciseName = 'DUSK TEST EXERCISE '.time();
        $browser->pause(2000)
            ->waitForText('NOUVEL EXERCICE', 15)
            ->type('input[placeholder="Ex: Développé couché"]', $exerciseName)
            ->waitFor('select', 5)
            ->select('select', 'strength')
            ->pause(1000)
            ->click('[data-testid="submit-exercise-button"]');

        // 3. Verify exercise was created
        $browser->pause(1000)
            ->waitFor('[data-testid="exercise-card"]', 20)
            ->assertSee(strtoupper($exerciseName));

        // 4. Edit the exercise
        $browser->mouseover('[data-testid="exercise-card"]')
            ->pause(500)
            ->script("const btn = document.querySelector('[data-testid=\"edit-exercise-button\"]'); btn.dispatchEvent(new Event('click', {bubbles: false}));");

        $updatedName = 'UPDATED EXERCISE '.time();
        $browser->waitFor('input[type="text"]', 10)
            ->pause(500)
            ->clear('input[type="text"]')
            ->type('input[type="text"]', $updatedName)
            ->click('[data-testid="save-exercise-button"]');

        // 5. Verify update
        $browser->pause(1000)
            ->waitForText(strtoupper($updatedName), 15);

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
