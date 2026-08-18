<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Facebook\WebDriver\WebDriverKeys;
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

            $browser->waitForText('Confirmer la suppression', 15);

            // <html>, not <body>: the viewport takes its overflow from the root
            // element, and only falls back to <body> when the root's own is
            // `visible`. app.css clips the root, so a lock written to <body> was
            // read by nothing and the page scrolled behind every modal.
            $locked = $browser->script('return getComputedStyle(document.documentElement).overflowY;')[0];

            $this->assertSame('hidden', $locked, 'An open modal must stop the page scrolling behind it.');

            // Sent to whatever holds focus rather than through a selector: the
            // dialog closes on the keydown, which hides its contents before the
            // keyup lands, and WebDriver refuses to finish typing into an
            // element that stopped being interactable mid-gesture.
            $browser->driver->action()->sendKeys(null, WebDriverKeys::ESCAPE)->perform();

            $browser->waitUntilMissingText('Confirmer la suppression', 15);

            /**
             * Escape is a close request the browser answers itself: it closes
             * the <dialog> before any of our code runs. Nothing was listening
             * for that, so the panel left the screen with the scroll lock still
             * on <body> — the whole app stopped scrolling, with no modal on
             * screen to explain why, until the page was reloaded.
             */
            $released = $browser->script('return getComputedStyle(document.documentElement).overflowY;')[0];

            $this->assertNotSame(
                'hidden',
                $released,
                'Closing the modal must give the page scroll back, however it was closed.'
            );
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

    /**
     * The template exercise picker is a bottom sheet, which is why it was built
     * by hand: Modal centred its panel. Modal now takes a position, so the sheet
     * keeps its placement and gains the semantics — the overlay had no role, no
     * Escape, and left Tab free to reach the template form behind it.
     *
     * Create rather than Edit: templates.edit deliberately aborts with a 404.
     */
    public function test_the_template_exercise_picker_is_a_real_dialog(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('templates.create'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@open-add-exercise', 15)
                ->script('document.querySelector(\'[dusk="open-add-exercise"]\').click();');

            $browser->waitFor('dialog[open]', 15);

            $state = $browser->script(
                'const dialog = document.querySelector("dialog[open]");'
                .'return {'
                .'  modal: dialog.matches(":modal"),'
                .'  labelledby: dialog.getAttribute("aria-labelledby"),'
                .'  titled: !! document.getElementById("add-exercise-title"),'
                .'  glassLayers: dialog.querySelectorAll(".glass-modal").length,'
                .'};'
            )[0];

            $this->assertTrue($state['modal'], 'The picker must be opened modally, which is what traps Tab.');
            $this->assertSame('add-exercise-title', $state['labelledby']);
            $this->assertTrue($state['titled'], 'aria-labelledby must point at an element that exists.');
            $this->assertSame(
                1,
                $state['glassLayers'],
                'Modal already paints the glass panel; a second one stacks two backgrounds and two radii.'
            );

            // Sent to whatever holds focus rather than through a selector: the
            // dialog closes on the keydown, which hides its contents before the
            // keyup lands, and WebDriver refuses to finish typing into an
            // element that stopped being interactable mid-gesture.
            $browser->driver->action()->sendKeys(null, WebDriverKeys::ESCAPE)->perform();

            // `waitUntilMissing` leve deja si le dialogue reste ouvert, mais un
            // `assertTrue(true)` derriere ne disait rien a personne. L'assertion
            // porte desormais le fait verifie.
            $browser->waitUntilMissing('dialog[open]', 15)
                ->assertMissing('dialog[open]');
        });
    }

    /**
     * The picker is a bottom sheet on a phone so it stays under the thumb. The
     * shared Modal centres by default, so this pins the placement the position
     * prop exists to preserve.
     */
    public function test_the_template_exercise_picker_stays_a_bottom_sheet(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('templates.create'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@open-add-exercise', 15)
                ->script('document.querySelector(\'[dusk="open-add-exercise"]\').click();');

            $browser->waitFor('dialog[open]', 15)
                ->waitFor('#add-exercise-title', 15);

            $geometry = $browser->script(
                'const panel = document.querySelector("dialog[open] .glass-modal").getBoundingClientRect();'
                .'return {gapBelow: window.innerHeight - panel.bottom, gapAbove: panel.top};'
            )[0];

            $this->assertLessThan(
                48,
                $geometry['gapBelow'],
                'The sheet must sit against the bottom of the viewport, within thumb reach.'
            );
            $this->assertGreaterThan(
                $geometry['gapBelow'],
                $geometry['gapAbove'],
                'A bottom sheet leaves more room above it than below it.'
            );
        });
    }
}
