<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * AuthenticatedLayout renders the #header slot inside a `hidden sm:block`
 * element, so any primary action placed only there silently disappears on
 * phones — the app's main target. Pages are expected to mirror the action into
 * #header-actions; Goals shipped without it and became impossible to use on
 * mobile. These tests pin that contract at the viewport sizes users actually
 * have.
 */
class MobileHeaderActionsTest extends DuskTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function mobileViewports(): array
    {
        return [
            'iphone mini' => ['resizeToIphoneMini'],
            'iphone 15' => ['resizeToIphone15'],
            'iphone max' => ['resizeToIphoneMax'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mobileViewports')]
    public function test_goal_creation_is_reachable_on_mobile(string $viewport): void
    {
        $this->browse(function (Browser $browser) use ($viewport): void {
            $user = User::factory()->create();

            $browser->loginAs($user)
                ->{$viewport}()
                ->visit(route('goals.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@create-goal-btn', 10)
                ->assertVisible('@create-goal-btn')
                ->click('@create-goal-btn')
                // Asserted structurally rather than by text: several labels are
                // uppercased in CSS, and WebDriver reports transformed text.
                ->waitFor('@goal-create-form', 10)
                ->assertVisible('@goal-create-form')
                ->assertNoConsoleExceptions();
        });
    }

    public function test_mobile_header_shows_the_page_title(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            // Without page-title the mobile bar renders empty and the user has no
            // indication of where they are, since the desktop heading is hidden.
            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('goals.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                // Scoped to the sticky mobile bar's own <h1>: asserting against the
                // whole source would also match <Head title> and pass even when the
                // bar renders nothing. Compared case-insensitively because the
                // heading is uppercased in CSS and WebDriver returns rendered text.
                ->assertVisible('header.sticky h1')
                ->with('header.sticky h1', function (Browser $heading): void {
                    $this->assertSame(
                        'mes objectifs',
                        mb_strtolower(trim($heading->text('')))
                    );
                });
        });
    }

    public function test_desktop_still_exposes_the_full_header_action(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            $browser->loginAs($user)
                ->resize(1280, 900)
                ->visit(route('goals.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@create-goal-btn-desktop', 10)
                ->assertVisible('@create-goal-btn-desktop')
                ->click('@create-goal-btn-desktop')
                ->waitFor('@goal-create-form', 10)
                ->assertVisible('@goal-create-form');
        });
    }
}
