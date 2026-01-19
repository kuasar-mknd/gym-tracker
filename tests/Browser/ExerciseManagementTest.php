<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;

uses(DatabaseTruncation::class);

test('user can manage exercises from mobile plus menu', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->resize(375, 812) // Mobile view
            ->visit('/dashboard')
            ->assertPathIs('/dashboard')
            ->waitFor('a[aria-label="Plus"]', 15)

            // 1. Navigate to "Plus" menu
            ->click('a[aria-label="Plus"]')
            ->waitForLocation('/profile')
            ->waitForText('Biblioth', 15)
            ->assertPathIs('/profile')

            // 2. Click "Bibliothèque"
            ->clickLink('Bibliothèque')
            ->waitForLocation('/exercises')
            ->assertPathIs('/exercises')

            // 3. Add a new exercise
            ->waitFor('[data-testid="create-exercise-button"]', 15)
            ->click('[data-testid="create-exercise-button"]')
            ->waitForText('Nouvel exercice', 15)
            ->type('input[placeholder="Ex: Développé couché"]', 'Dusk Test Exercise')
            ->select('select', 'strength')
            ->pause(500) // Wait for animations
            ->press('Créer l\'exercice')
            ->waitForText('Dusk Test Exercise', 15)

            // 4. Edit the exercise
            ->click('[data-testid="edit-exercise-button"]')
            ->waitForText('Sauvegarder', 15)
            ->type('input[value="Dusk Test Exercise"]', 'Dusk Test Exercise Updated')
            ->press('Sauvegarder')
            ->waitForText('Dusk Test Exercise Updated', 15)

            // 5. Delete the exercise
            ->click('[data-testid="delete-exercise-button"]')
            ->assertDialogOpened('Supprimer cet exercice ?')
            ->acceptDialog()
            ->waitForText('Aucun exercice pour l\'instant', 15);
    });
});
