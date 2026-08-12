<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The window between tapping an exercise and the server acknowledging it.
 *
 * This file was named after that race and never entered it: it created an EMPTY
 * workout, opened the page, paused a second, and asserted the URL had not
 * changed. No exercise, no set, no typing. Six separate data-loss bugs living
 * in exactly this window all passed it — including one where a set stayed on a
 * placeholder id for the life of the page, and one where the create response
 * silently reverted what the user had just typed.
 *
 * A guard that cannot fail is worse than none, because its name tells the next
 * person the ground is covered.
 */
class WorkoutSyncRaceTest extends DuskTestCase
{
    use DatabaseTruncation;

    /** @return array{0: User, 1: Workout, 2: Exercise} */
    private function anEmptySession(): array
    {
        $user = User::factory()->create([
            'email' => 'sync-user-'.time().random_int(0, 9999).'@example.com',
            'email_verified_at' => now(),
        ]);

        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now(),
            'ended_at' => null,
        ]);

        $exercise = Exercise::factory()->create(['type' => 'strength', 'name' => 'Développé Couché']);

        return [$user, $workout, $exercise];
    }

    /**
     * Adding a set the instant the exercise appears is the ordinary way to use
     * this screen, and the optimistic row is on screen before the server has
     * issued an id. Everything the page does next has to survive that.
     */
    public function test_a_set_added_while_the_exercise_is_still_syncing_reaches_the_database(): void
    {
        [$user, $workout, $exercise] = $this->anEmptySession();

        $this->browse(function (Browser $browser) use ($user, $workout, $exercise): void {
            $browser->loginAs(User::find($user->id))
                ->resizeToIphone15()
                ->visit('/workouts/'.$workout->id)
                ->disableAnimations()
                ->waitFor('#main-content', 30);

            /**
             * Holds the race window open.
             *
             * Against a local server the exercise create answers in tens of
             * milliseconds — long gone before Dusk can click anything — so the
             * window this file exists to test never opens, and the test passes
             * on code that is thoroughly broken. Verified: without this delay
             * the whole thing passes against the tree from before any of these
             * bugs were fixed.
             *
             * Three seconds outlasts the clicking and typing below, so the set
             * is genuinely created and edited against an exercise the server has
             * not acknowledged yet.
             */
            $browser->script(<<<'JS'
                    (function () {
                        const original = window.axios;
                        const delayed = function (config) {
                            if (String(config.url).includes('workout-lines') && String(config.method).toLowerCase() === 'post') {
                                return new Promise((resolve, reject) =>
                                    setTimeout(() => original(config).then(resolve, reject), 3000)
                                );
                            }
                            return original(config);
                        };
                        Object.assign(delayed, original);
                        window.axios = delayed;
                    })();
                JS);

            $browser
                ->waitFor('[dusk="add-first-exercise"]', 10)
                ->click('[dusk="add-first-exercise"]')
                ->waitFor('[dusk="select-exercise-'.$exercise->id.'"]', 10)
                ->click('[dusk="select-exercise-'.$exercise->id.'"]')
                /**
                 * No pause here on purpose. The row is optimistic, so it appears
                 * while the create is still in flight — which is the whole point
                 * of this file.
                 */
                ->waitFor('[dusk="add-set-0"]', 10)
                ->click('[dusk="add-set-0"]')
                ->waitFor('[dusk="weight-input-0-0"]', 10)
                ->type('[dusk="weight-input-0-0"]', '92.5')
                ->click('#main-content');

            // The write is debounced: wait for the outcome this test asserts below
            // rather than for a duration guessed to be long enough.
            $this->waitForDatabase(fn (): bool => (float) ($workout->workoutLines()->first()?->sets()->first()?->weight ?? 0) === 92.5);

            $browser->assertNoConsoleExceptions();
        });

        $line = $workout->workoutLines()->first();

        $this->assertNotNull($line, 'the exercise never reached the database');
        $this->assertSame($exercise->id, $line->exercise_id);

        $set = $line->sets()->first();

        $this->assertNotNull($set, 'the set was on screen but never reached the database');
        $this->assertSame(92.5, (float) $set->weight, 'the value typed during the race was not the one saved');
    }

    /**
     * The page renders at the three widths the app is actually used at. This is
     * a smoke test, and is now named like one.
     */
    public function test_the_session_page_renders_without_console_errors(): void
    {
        [$user, $workout] = $this->anEmptySession();

        $this->browse(function (Browser $browser) use ($user, $workout): void {
            foreach (['resizeToIphoneMini', 'resizeToIphone15', 'resizeToIphoneMax'] as $size) {
                $browser->loginAs(User::find($user->id))
                    ->{$size}()
                    ->visit('/workouts/'.$workout->id)
                    ->disableAnimations()
                    ->waitFor('#main-content', 30)
                    ->assertPathIs('/workouts/'.$workout->id)
                    ->assertNoConsoleExceptions();
            }
        });
    }
}
