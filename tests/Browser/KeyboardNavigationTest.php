<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Primary actions kept being built as `<div @click>`: they look right and work
 * with a mouse, but they are invisible to the keyboard and announced as nothing
 * by a screen reader. These pin the semantics rather than the styling.
 */
class KeyboardNavigationTest extends DuskTestCase
{
    public function test_an_exercise_opens_through_a_real_link(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();
            $exercise = Exercise::factory()->create([
                'user_id' => $user->id,
                'name' => 'Développé couché',
            ]);

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('exercises.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor("@open-exercise-{$exercise->id}", 15);

            $element = $browser->script(
                "const el = document.querySelector('[dusk=\"open-exercise-{$exercise->id}\"]');"
                .'return [el.tagName, el.getAttribute("href") || "", el.tabIndex];'
            )[0];

            [$tag, $href, $tabIndex] = $element;

            $this->assertSame('A', $tag, 'The card must be an anchor, not a clickable div.');
            $this->assertStringContainsString("/exercises/{$exercise->id}", $href);
            $this->assertGreaterThanOrEqual(0, $tabIndex, 'The card must be reachable by Tab.');
        });
    }

    public function test_adding_an_exercise_to_a_session_is_a_button(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();
            Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Squat']);

            $workout = \App\Models\Workout::factory()->create([
                'user_id' => $user->id,
                'started_at' => now(),
                'ended_at' => null,
            ]);

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit("/workouts/{$workout->id}")
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('[dusk="add-first-exercise"]', 15)
                ->click('[dusk="add-first-exercise"]')
                ->waitFor('input[placeholder="Rechercher..."]', 15);

            $tags = $browser->script(
                'return Array.from(document.querySelectorAll(\'[dusk^="select-exercise-"]\'))'
                .'.map(el => el.tagName);'
            )[0];

            $this->assertNotEmpty($tags, 'Expected at least one selectable exercise.');
            $this->assertSame(
                array_fill(0, count($tags), 'BUTTON'),
                $tags,
                'Exercise options must be buttons so they can be reached and activated by keyboard.'
            );
        });
    }

    /**
     * Picking a day is the only interaction the calendar offers, and the cells
     * were divs. The workout and journal markers compounded it: coloured dots
     * with no text, so the one piece of information the grid carries was
     * unavailable to anyone not looking at the colours.
     */
    public function test_a_calendar_day_is_a_button_that_announces_its_markers(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('calendar.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30);

            /*
             * « Aujourd'hui » est décidé par le navigateur, dans le fuseau de
             * la machine qui le lance ; la suite vit dans celui de
             * l'application. Lire sa date, et y poser la séance à midi.
             */
            $dateDuNavigateur = $browser->script(
                'const d = new Date();'
                .'return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, "0"), String(d.getDate()).padStart(2, "0")].join("-");'
            )[0];
            assert(is_string($dateDuNavigateur));
            $today = Carbon::parse($dateDuNavigateur.' 12:00:00', config()->string('app.timezone'));

            Workout::factory()->create([
                'user_id' => $user->id,
                'started_at' => $today,
                'ended_at' => $today->copy()->addHour(),
            ]);

            $selector = 'calendar-day-'.$today->format('Y-m-d');

            $browser->visit(route('calendar.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor("@{$selector}", 15);

            $cell = $browser->script(
                "const el = document.querySelector('[dusk=\"{$selector}\"]');"
                .'return {'
                .'  tag: el.tagName,'
                .'  label: el.getAttribute("aria-label"),'
                .'  current: el.getAttribute("aria-current"),'
                .'  pressed: el.getAttribute("aria-pressed"),'
                .'  tabIndex: el.tabIndex,'
                .'};'
            )[0];

            $this->assertSame('BUTTON', $cell['tag'], 'A calendar day must be operable, not a styled div.');
            $this->assertGreaterThanOrEqual(0, $cell['tabIndex']);
            $this->assertStringContainsString('séance', $cell['label'], 'The workout dot must have a text equivalent.');
            $this->assertStringContainsString($today->format('j'), $cell['label']);
            $this->assertSame('date', $cell['current'], "Today's cell must be marked aria-current.");
            $this->assertSame('false', $cell['pressed'], 'An unselected day must expose an unpressed state.');

            $browser->keys("@{$selector}", ['{enter}'])
                ->waitFor('@calendar-day-details', 15);

            $pressed = $browser->attribute("@{$selector}", 'aria-pressed');

            $this->assertSame('true', $pressed, 'Enter must select the day, exactly as a click does.');
        });
    }

    /**
     * SwipeableRow parks its actions behind the row at opacity 0 and only slides
     * them out on a swipe, but they never leave the tab order. On this page the
     * hidden action deletes the session and is the only way to delete one, so a
     * keyboard user could focus — and fire — a destructive control that draws
     * nothing on screen (WCAG 2.4.7).
     */
    public function test_the_swipe_delete_becomes_visible_when_it_takes_focus(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();
            $workout = Workout::factory()->create([
                'user_id' => $user->id,
                'started_at' => now()->subHour(),
                'ended_at' => now(),
            ]);

            $selector = "delete-workout-{$workout->id}";

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('workouts.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30);

            // Not waitFor(): that waits for the element to be *visible*, and being
            // invisible while still focusable is the whole defect under test.
            $browser->waitUsing(15, 100, fn (): bool => (bool) $browser->script(
                'return document.querySelector(\'[dusk="'.$selector.'"]\') !== null;'
            )[0], "The swipe delete action for workout {$workout->id} never rendered.");

            $measure = 'const el = document.querySelector(\'[dusk="'.$selector.'"]\');'
                .'const box = el.getBoundingClientRect();'
                .'return {'
                .'  opacity: Number(getComputedStyle(el.closest("[style*=opacity]")).opacity),'
                .'  onScreen: box.right > 0 && box.left < window.innerWidth && box.width > 0,'
                .'};';

            $closed = $browser->script($measure)[0];

            $this->assertEqualsWithDelta(
                0.0,
                $closed['opacity'],
                0.001,
                'The actions must start invisible — that is the design.'
            );

            $browser->script('document.querySelector(\'[dusk="'.$selector.'"]\').focus();');
            $browser->waitUntil(
                'Number(getComputedStyle(document.querySelector(\'[dusk="'.$selector.'"]\').closest("[style*=opacity]")).opacity) > 0.9',
                10,
                'Focusing the delete action must reveal it instead of leaving focus on an invisible control.'
            );

            $focused = $browser->script($measure)[0];

            $this->assertGreaterThan(
                0.9,
                $focused['opacity'],
                'Focusing the delete action must reveal it instead of leaving focus on an invisible control.'
            );
            $this->assertTrue($focused['onScreen'], 'The revealed action must actually be within the viewport.');
        });
    }
}
