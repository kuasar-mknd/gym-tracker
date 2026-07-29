<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Two modals were hand-rolled as positioned divs rather than going through
 * Components/UI/Modal, which opens a native <dialog> with showModal(). A styled
 * div looks identical and behaves nothing alike: no dialog role, nothing keeping
 * Tab inside it, and focus left sitting on the button underneath the overlay.
 *
 * The account deletion modal is the one that mattered — a keyboard user could
 * tab out of a destructive confirmation and back into the page behind it.
 */
class ModalSemanticsTest extends DuskTestCase
{
    public function test_the_account_deletion_modal_traps_focus_in_a_real_dialog(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('profile.edit'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('[data-testid="delete-account-button"]', 15)
                ->script('document.querySelector(\'[data-testid="delete-account-button"]\').click();');

            $browser->waitForText('Confirmer la suppression', 15);

            $state = $browser->script(
                'const dialog = document.querySelector("dialog[open]");'
                .'if (! dialog) { return {open: false}; }'
                .'return {'
                .'  open: true,'
                .'  modal: dialog.matches(":modal"),'
                .'  labelledby: dialog.getAttribute("aria-labelledby"),'
                .'  focusInside: dialog.contains(document.activeElement),'
                .'};'
            )[0];

            $this->assertTrue($state['open'], 'Opening the modal must open a native <dialog>.');
            $this->assertTrue($state['modal'], 'The dialog must be opened modally, which is what traps focus.');
            $this->assertSame('delete-account-title', $state['labelledby']);
            $this->assertTrue(
                $state['focusInside'],
                'Focus must move into the dialog, not stay on the button behind the overlay.'
            );
        });
    }

    public function test_escape_closes_the_account_deletion_modal(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('profile.edit'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('[data-testid="delete-account-button"]', 15)
                ->script('document.querySelector(\'[data-testid="delete-account-button"]\').click();');

            $browser->waitForText('Confirmer la suppression', 15)
                ->keys('[data-testid="delete-password-input"]', ['{escape}'])
                ->waitUntilMissingText('Confirmer la suppression', 15);

            $this->assertTrue(true, 'The modal closed on Escape.');
        });
    }

    public function test_the_habit_form_is_a_real_dialog(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('habits.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@add-habit', 15)
                ->click('@add-habit')
                ->waitFor('dialog[open]', 15);

            $state = $browser->script(
                'const dialog = document.querySelector("dialog[open]");'
                .'return {modal: dialog.matches(":modal"), labelledby: dialog.getAttribute("aria-labelledby")};'
            )[0];

            $this->assertTrue($state['modal'], 'The habit form must be opened modally so Tab stays inside it.');
            $this->assertSame('habit-form-title', $state['labelledby']);
        });
    }
}
