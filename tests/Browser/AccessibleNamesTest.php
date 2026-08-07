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
}
