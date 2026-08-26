<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Controls whose accessible name is empty. They look labelled — a heading sits
 * above the field, a coloured circle stands for a choice — but the association
 * is only visual, so a screen reader announces "edit text" or "button" with
 * nothing to distinguish one from the next.
 *
 * The name is computed in the browser rather than grepped, because that is what
 * decides whether a label actually reaches assistive tech.
 */
class AccessibleNamesTest extends DuskTestCase
{
    /**
     * Every calculator paired a <label> with an <input> by proximity alone: no
     * `for`, no `id`, no aria-label. Tapping the label also missed the field,
     * which costs a real touch target on a phone.
     */
    public function test_every_calculator_control_is_labelled(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            $browser->loginAs($user)->resizeToIphone15();

            $pages = [
                '1RM' => route('tools.1rm'),
                'Plates' => route('plates.index'),
                'Warmup' => route('tools.warmup'),
                'Wilks' => route('tools.wilks'),
                'Macros' => route('tools.macro-calculator'),
            ];

            foreach ($pages as $name => $url) {
                $browser->visit($url)
                    ->disableAnimations()
                    ->waitFor('#main-content', 30);

                $unnamed = $browser->script(
                    'return Array.from(document.querySelectorAll("#main-content input, #main-content select, #main-content textarea"))'
                    .'.filter(el => el.type !== "hidden")'
                    .'.filter(el => {'
                    .'  if ((el.getAttribute("aria-label") || "").trim()) { return false; }'
                    .'  if (el.getAttribute("aria-labelledby")) { return false; }'
                    .'  if (el.closest("label")) { return false; }'
                    .'  return ! (el.id && document.querySelector(`label[for="${CSS.escape(el.id)}"]`));'
                    .'})'
                    .'.map(el => el.outerHTML.slice(0, 90));'
                )[0];

                $this->assertSame([], $unnamed, "{$name} ships form controls with no accessible name.");
            }
        });
    }

    /**
     * The swatches were empty elements: no text, no icon, no label. Sixteen
     * identical "button" announcements, with the only distinguishing
     * information carried by the background colour — which is also the one
     * thing a colour-blind user cannot use (WCAG 1.4.1).
     */
    public function test_the_habit_colour_and_icon_pickers_name_their_options(): void
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
                ->waitFor('dialog[open]', 15)
                ->waitFor('@habit-color-bg-red-500', 15);

            $pickers = $browser->script(
                'const named = (selector) => Array.from(document.querySelectorAll(selector))'
                .'  .map(el => ({label: (el.getAttribute("aria-label") || "").trim(), pressed: el.getAttribute("aria-pressed")}));'
                .'return {'
                .'  colors: named(\'[dusk^="habit-color-"]\'),'
                .'  icons: named(\'[dusk^="habit-icon-"]\'),'
                .'  colorGroup: document.querySelector(\'[aria-labelledby="habit-color-label"]\') !== null,'
                .'};'
            )[0];

            $this->assertNotEmpty($pickers['colors']);
            $this->assertNotEmpty($pickers['icons']);
            $this->assertTrue($pickers['colorGroup'], 'The swatches must belong to a group that says what they set.');

            foreach (['colors', 'icons'] as $picker) {
                foreach ($pickers[$picker] as $index => $option) {
                    $this->assertNotSame('', $option['label'], "Option {$index} of the {$picker} picker has no name.");
                    $this->assertContains(
                        $option['pressed'],
                        ['true', 'false'],
                        "Option {$index} of the {$picker} picker does not expose whether it is selected."
                    );
                }
            }

            $labels = array_column($pickers['colors'], 'label');

            $this->assertSame($labels, array_unique($labels), 'Two swatches sharing a name are indistinguishable.');
        });
    }

    /**
     * A control that is named but invisible is no more usable than an unnamed
     * one. The session's settings button was `bg-white/10 text-white` with no
     * light-mode counterpart, so on the light theme it was a white glyph on a
     * white header — announced perfectly, and impossible to see.
     *
     * Contrast is computed from what the browser actually paints rather than
     * asserted against class names, which would only restate the markup. The
     * bar is WCAG AA for a user interface component, 3:1.
     */
    public function test_the_session_header_controls_can_be_seen_on_the_light_theme(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $workout = \App\Models\Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

        $this->browse(function (Browser $browser) use ($user, $workout): void {
            $browser->loginAs(User::find($user->id))
                ->resizeToIphone15()
                ->visit('/workouts/'.$workout->id)
                ->waitFor('#main-content', 30);

            /**
             * Ask for the light theme through the preference the app reads, and
             * reload so it is applied on mount. Stripping the `dark` class from
             * the root instead does nothing lasting: the app puts it straight
             * back, and the measurement below then quietly reports the dark
             * theme — which is how the first version of this guard passed on the
             * very markup it was written to catch.
             */
            $browser->script("localStorage.setItem('gymtracker-theme', 'light');");

            $browser->visit('/workouts/'.$workout->id)
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@workout-settings-button', 15)
                ->assertScript("document.documentElement.classList.contains('dark')", false);

            $ratios = $browser->script(<<<'MEASURE'
                return (function () {
                    /**
                     * Colours are normalised by painting them, not by reading the
                     * string. Tailwind emits `oklab(0.98 -0.0001 -0.007 / 0.8)`
                     * for anything with an opacity modifier, and a /[\d.]+/ parse
                     * of that silently drops the minus signs and reports a near
                     * black — which is how an earlier version of this guard found
                     * white-on-white to be high contrast and passed on the very
                     * markup it exists to catch.
                     */
                    const surface = document.createElement('canvas').getContext('2d', {
                        willReadFrequently: true,
                    });

                    const paint = (css) => {
                        surface.clearRect(0, 0, 1, 1);
                        surface.fillStyle = '#000';
                        surface.fillStyle = css;
                        surface.fillRect(0, 0, 1, 1);

                        const [r, g, b, a] = surface.getImageData(0, 0, 1, 1).data;

                        return [r, g, b, a / 255];
                    };

                    const over = (top, bottom) =>
                        [0, 1, 2].map((i) => top[i] * top[3] + bottom[i] * (1 - top[3]));

                    const channel = (c) => {
                        const v = c / 255;

                        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
                    };

                    const luminance = (rgb) =>
                        0.2126 * channel(rgb[0]) + 0.7152 * channel(rgb[1]) + 0.0722 * channel(rgb[2]);

                    /**
                     * Everything painted behind the glyph, composited. Stopping at
                     * the first ancestor that looks opaque enough is not the same
                     * thing: these headers are stacks of translucent layers, and
                     * what the eye sees is the result of all of them.
                     */
                    const backdrop = (node) => {
                        const layers = [];

                        for (let el = node; el; el = el.parentElement) {
                            layers.push(paint(getComputedStyle(el).backgroundColor));
                        }

                        return layers.reduceRight((below, layer) => over(layer, below), [255, 255, 255]);
                    };

                    const ratio = (el) => {
                        if (!el) { return null; }

                        const a = luminance(paint(getComputedStyle(el).color));
                        const b = luminance(backdrop(el));

                        return Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 100) / 100;
                    };

                    return JSON.stringify({
                        settings: ratio(document.querySelector('[dusk="workout-settings-button"]')),
                        // Its neighbour, and the pattern the settings button was
                        // brought back into line with.
                        notifications: ratio(document.querySelector('a[href*="notifications"]')),
                    });
                })();
            MEASURE)[0];

            $ratios = json_decode((string) $ratios, true);

            $this->assertNotNull($ratios['settings'], 'the settings button was not on the page');

            foreach (['settings' => 'settings', 'notifications' => 'notifications'] as $key => $name) {
                $this->assertGreaterThanOrEqual(
                    3.0,
                    $ratios[$key],
                    "the session header's {$name} control does not stand out from the header it sits on"
                );
            }
        });
    }
}
