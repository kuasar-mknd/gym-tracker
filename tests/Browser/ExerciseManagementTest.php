<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('user can manage exercises from mobile plus menu', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->resize(375, 812) // Mobile view
            ->visit('/dashboard')
            ->waitForText('DÉMARRER', 15) // Increased timeout

            // 1. Navigate to "Plus" menu
            ->click('a[aria-label="Plus"]')
            ->waitForText('Bibliothèque', 15)
            ->assertPathIs('/profile')

            // 2. Click "Bibliothèque"
            ->clickLink('Bibliothèque')
            ->waitForText('LA BIBLIOTHÈQUE', 15)
            ->assertPathIs('/exercises')

            // 3. Add a new exercise
            ->waitFor('[data-testid="create-exercise-mobile-header"]', 15)
            ->click('[data-testid="create-exercise-mobile-header"]')
            ->waitForText('Nouvel exercice', 15)
            ->type('input[placeholder="Ex: Développé couché"]', 'Dusk Test Exercise')
            ->select('select', 'strength')
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
