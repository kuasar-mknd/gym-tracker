<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Achievement;
use App\Models\User;
use App\Notifications\AchievementUnlocked;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * CelebrationModal bails out early on the shared `is_testing` prop, which was
 * app()->environment('testing') alone. CI writes its Dusk env with
 * APP_ENV=testing, so it held there — but .env.dusk.local keeps APP_ENV=local,
 * because the CSP policy and the social login callback both branch on
 * environment('local'). Locally the check was therefore always false and the
 * overlay fired mid-test, which is the worse failure of the two: the guard
 * behaved differently on a developer machine than it did on CI.
 *
 * The overlay covers the viewport and swallows clicks, making it a flake source
 * for any test whose user happens to hold an unread achievement. It cost a real
 * failure while building the notifications pagination test.
 */
class CelebrationSuppressionTest extends DuskTestCase
{
    public function test_an_unread_achievement_does_not_pop_the_celebration_during_browser_tests(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();
            $user->notify(new AchievementUnlocked(Achievement::factory()->create()));

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('dashboard'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitForStableLayout();

            $celebrationVisible = $browser->script(
                'return document.querySelector("#achievement-title") !== null;'
            )[0];

            $this->assertFalse(
                $celebrationVisible,
                'The celebration overlay must stay suppressed during browser tests.'
            );
        });
    }

    public function test_the_shared_testing_flag_is_true_under_dusk(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            $browser->loginAs($user)
                ->visit(route('dashboard'))
                ->waitFor('#main-content', 30);

            /**
             * Inertia 3 moved the initial page off the app element's `data-page`
             * attribute and into a `<script data-page="app" type="application/json">`
             * whose text IS the payload — the old `future.useScriptElementForInitialPage`,
             * now always on. Read as an attribute it yields the string "app",
             * and JSON.parse throws.
             */
            $isTesting = $browser->script(
                'return JSON.parse(document.querySelector("script[data-page]").textContent).props.is_testing;'
            )[0];

            $this->assertTrue(
                $isTesting,
                'is_testing must be true under Dusk, otherwise every guard behind it silently does nothing.'
            );
        });
    }
}
