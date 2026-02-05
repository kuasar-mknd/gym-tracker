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
            ->click('[data-testid="create-exercise-desktop"]');

        // 2. Fill and submit the create form
        $browser->pause(500)
            ->waitForText('NOUVEL EXERCICE', 15) // Case sensitive check or wait for element
            ->type('input[placeholder="Ex: Développé couché"]', 'Dusk Test Exercise')
            ->waitFor('[data-testid="exercise-type-select"]', 5)
            ->select('[data-testid="exercise-type-select"]', 'strength')
            ->waitFor('[data-testid="exercise-category-select"]', 5)
            ->select('[data-testid="exercise-category-select"]', 'Pectoraux')
            ->waitFor('[data-testid="submit-exercise-button"]', 5)
            ->press('[data-testid="submit-exercise-button"]')
            ->waitFor('[data-exercise-name="Dusk Test Exercise"]', 20)
            ->assertVisible('[data-exercise-name="Dusk Test Exercise"]');

        // 2. Set test cookie and check visibility
        $browser->addCookie('is_dusk_test', 'true')
            ->refresh() // Reload so the cookie is sent to the server and is_testing becomes true
            ->pause(1000)
            ->assertPathIs('/exercises')
            ->assertVisible('[data-exercise-name="Dusk Test Exercise"]')
            ->waitFor('[data-exercise-name="Dusk Test Exercise"]', 5);

        // 3. Verify exercise is present (removed outdated tbody check)

        // 4. Edit the exercise
        // Hovering is technically not needed if is_testing=true makes buttons visible,
        // but we keep specific selector usage.
        $browser->click('[data-testid="edit-exercise-button-desktop"]');

        $browser->pause(1000)
            ->screenshot('debug_after_edit_click')
            ->storeSource('debug_source_after_edit_click')
            ->waitFor('[data-testid="edit-exercise-name-input"]', 15)
            ->type('[data-testid="edit-exercise-name-input"]', 'Updated Exercise')
            ->press('[data-testid="save-exercise-button"]');

        // 5. Verify exercise was updated
        $browser->pause(2000)
            ->waitFor('[data-exercise-name="Updated Exercise"]', 15);

        // 6. Delete the exercise
        $browser->script(
            "console.log('DUSK_DEBUG: Starting delete interaction'); ".
                "const row = document.querySelector('[data-exercise-row=\"Updated Exercise\"]'); ".
                'if (row) { '.
                "    const btns = Array.from(row.querySelectorAll('[data-testid=\"delete-exercise-button\"]')); ".
                '    const visibleBtn = btns.find(btn => btn.offsetWidth > 0 || btn.offsetHeight > 0); '.
                '    if (visibleBtn) visibleBtn.click(); '.
                '    else { '.
                "        const mobileBtn = row.querySelector('[data-testid=\"delete-exercise-button-mobile\"]'); ".
                '        if (mobileBtn) mobileBtn.click(); '.
                '    } '.
                "} else { console.error('DUSK_DEBUG: Delete row not found'); }"
        );

        $browser->assertDialogOpened('Supprimer cet exercice ?')
            ->acceptDialog()
            ->pause(1000)
            ->waitFor('[data-testid="create-exercise-desktop"]', 15)
            ->assertNoConsoleExceptions();
    });
});
