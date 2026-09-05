<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The category filter was kept in localStorage, so it outlived the reason it
 * was set: filter to one muscle group on leg day and the library still opened
 * filtered weeks later, looking like most of the exercises had disappeared —
 * with no clue why once the chips had scrolled out of view.
 *
 * It now lives in the URL, which is where a filter belongs: it survives a
 * reload, it can be linked to, and opening the library fresh shows everything.
 *
 * The cards are read out of the DOM rather than with assertSee, because the
 * name is rendered through `uppercase` and the driver returns the transformed
 * string, not the one in the markup.
 */
class ExerciseFilterScopeTest extends DuskTestCase
{
    /**
     * @return list<string>
     */
    private function renderedExerciseNames(Browser $browser): array
    {
        /** @var list<string> $names */
        $names = $browser->script(
            'return Array.from(document.querySelectorAll(\'[dusk^="exercise-card-"]\'))'
            .'.map(card => card.textContent.trim());'
        )[0];

        return $names;
    }

    public function test_a_filter_from_an_earlier_session_is_not_replayed(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();
            Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Squat', 'category' => 'Jambes']);
            Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Curl biceps', 'category' => 'Bras']);

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('exercises.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@category-pill-Jambes', 15)
                // A filter chosen in some earlier session. It must not come back
                // as a standing preference.
                ->script("localStorage.setItem('gymtracker_active_category', 'Jambes');");

            $browser->visit(route('exercises.index'))
                ->waitFor('#main-content', 30)
                ->waitFor('@category-pill-Jambes', 15)
                ->waitForText('SQUAT', 10);

            $names = implode(' | ', $this->renderedExerciseNames($browser));

            $this->assertStringContainsStringIgnoringCase('Squat', $names);
            $this->assertStringContainsStringIgnoringCase('Curl biceps', $names, 'The stale filter must not hide it.');
        });
    }

    public function test_the_chosen_category_survives_a_reload_through_the_url(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();
            Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Squat', 'category' => 'Jambes']);
            Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Curl biceps', 'category' => 'Bras']);

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('exercises.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@category-pill-Jambes', 15)
                ->click('@category-pill-Jambes')
                ->waitUntil('window.location.search.includes("category=Jambes")', 10, 'the chosen category never reached the URL');

            // Reloading keeps the filter — what localStorage was there to
            // provide, without outliving the session that chose it.
            $browser->refresh()
                ->waitFor('#main-content', 30)
                ->waitFor('@category-pill-Jambes', 15)
                ->waitForText('SQUAT', 10)
                ->waitUntilMissingText('CURL BICEPS', 10);

            $names = implode(' | ', $this->renderedExerciseNames($browser));

            $this->assertStringContainsStringIgnoringCase('Squat', $names);
            $this->assertStringNotContainsStringIgnoringCase('Curl biceps', $names);
        });
    }
}
