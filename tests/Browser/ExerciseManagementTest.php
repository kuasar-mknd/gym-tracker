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

        // 2. Fill and submit the create form - wait for input to be visible
        $browser->waitFor('input[placeholder="Ex: Développé couché"]', 15)
            ->type('input[placeholder="Ex: Développé couché"]', 'Dusk Test Exercise')
            ->waitFor('select', 5)
            ->select('select', 'strength')
            ->waitFor('[data-testid="submit-exercise-button"]', 5)
            ->click('[data-testid="submit-exercise-button"]');

        // 3. Verify exercise was created
        $browser->pause(1000)
            ->waitForText('DUSK TEST EXERCISE', 15);

        // 4. Edit the exercise
        // Using mouseover to reveal buttons revealed on hover
        $browser->waitFor('[data-testid="edit-exercise-button"]', 5)
            ->mouseover('[data-testid="edit-exercise-button"]')
            ->click('[data-testid="edit-exercise-button"]');

        $browser->waitFor('input[type="text"]', 15)
            ->pause(500)
            ->clear('input[type="text"]')
            ->type('input[type="text"]', 'UPDATED EXERCISE')
            ->waitFor('[data-testid="save-exercise-button"]', 5)
            ->click('[data-testid="save-exercise-button"]');

        // 5. Verify update
        $browser->pause(1000)
            ->waitForText('UPDATED EXERCISE', 15);

        // 6. Delete the exercise
        $browser->script('window.confirm = () => true;');
        $browser->waitFor('[data-testid="delete-exercise-button"]', 5)
            ->mouseover('[data-testid="delete-exercise-button"]')
            ->click('[data-testid="delete-exercise-button"]');

        $browser->pause(1500)
            ->assertDontSee('UPDATED EXERCISE')
            ->assertNoConsoleExceptions();
    });
});
