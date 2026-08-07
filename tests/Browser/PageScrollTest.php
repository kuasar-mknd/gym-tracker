<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The app could not be scrolled by hand.
 *
 * <body> carried `overflow-x: hidden`, and CSS computes the other axis to
 * `auto` as soon as one axis is not `visible` — so <body> was a scroll
 * container. It is as tall as its own content, so there was nothing to scroll
 * inside it: every wheel and every swipe landed on a scroller already at its
 * limit. `overscroll-behavior: none`, on the same element, then forbade handing
 * the leftover gesture up to the viewport, which is what actually scrolls.
 *
 * The page still moved when JavaScript set scrollTop — `overflow: hidden` stops
 * the user, not the program — which is why it read as intermittent rather than
 * broken.
 *
 * This asserts the CSS state rather than synthesizing a swipe on purpose:
 * ChromeDriver's wheel injection goes around the compositor path that honours
 * overscroll-behavior, so a gesture-based test scrolls happily against the
 * broken stylesheet. A test that cannot fail is worse than no test.
 */
class PageScrollTest extends DuskTestCase
{
    public function test_the_page_scrolls_by_hand_on_the_dashboard(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->loginAs(User::factory()->create())
                ->resizeToIphone15()
                ->visit(route('dashboard'))
                ->waitFor('#main-content', 30);

            $this->assertPageIsScrollableByHand($browser, 'dashboard');
        });
    }

    /**
     * The workout page is where a session is actually logged, one long list of
     * sets — the page that has to scroll.
     */
    public function test_the_page_scrolls_by_hand_on_a_workout(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();
            $workout = Workout::factory()->for($user)->create();

            WorkoutLine::factory()
                ->count(6)
                ->for($workout)
                ->for(Exercise::factory())
                ->create();

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('workouts.show', $workout))
                ->waitFor('#main-content', 30);

            $this->assertPageIsScrollableByHand($browser, 'workout');
        });
    }

    private function assertPageIsScrollableByHand(Browser $browser, string $page): void
    {
        $state = $browser->script(
            'const body = getComputedStyle(document.body);'
            .'const html = getComputedStyle(document.documentElement);'
            .'const root = document.documentElement;'
            .'return {'
            .'  bodyOverflowX: body.overflowX,'
            .'  bodyOverflowY: body.overflowY,'
            .'  bodyOverscrollY: body.overscrollBehaviorY,'
            .'  bodyHasSlackToScroll: document.body.scrollHeight > document.body.clientHeight,'
            .'  htmlOverflowX: html.overflowX,'
            .'  pageIsTallerThanViewport: root.scrollHeight > root.clientHeight,'
            .'  pageOverflowsSideways: root.scrollWidth > root.clientWidth,'
            .'};'
        )[0];

        $this->assertTrue(
            $state['pageIsTallerThanViewport'],
            "The {$page} page must be taller than the viewport for this to prove anything."
        );

        // The trap: a scroll container with nothing to scroll that also refuses
        // to pass the gesture on. Any one of the three alone is harmless.
        $bodyIsAScrollContainer = $state['bodyOverflowX'] !== 'visible' || $state['bodyOverflowY'] !== 'visible';

        $this->assertFalse(
            $bodyIsAScrollContainer && ! $state['bodyHasSlackToScroll'] && $state['bodyOverscrollY'] === 'none',
            "On {$page}, <body> is a scroll container with nothing to scroll and no scroll chaining: "
            .'every swipe and wheel is swallowed and the page cannot be scrolled by hand. '
            .'Put overflow and overscroll-behavior on <html>, which is what the viewport reads.'
        );

        // What the body rule was there for in the first place, still true.
        $this->assertSame('hidden', $state['htmlOverflowX'], 'The root must still clip sideways drift.');
        $this->assertFalse($state['pageOverflowsSideways'], "The {$page} page must not scroll sideways.");
    }
}
