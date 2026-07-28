<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
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
}
