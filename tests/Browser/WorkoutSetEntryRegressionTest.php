<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The three set defects reported against a live session, guarded end to end.
 *
 * Every one of them was invisible to the suite that existed. WorkoutSessionE2ETest
 * types '002530' into the duration field of a cardio exercise and then asserts
 * nothing whatsoever about it — so a field that could not be filled in, and that
 * wrote null over the duration on every keystroke, passed CI for as long as it
 * shipped. These assert against the database, because that is where a phantom
 * value and a lost entry actually show.
 */
class WorkoutSetEntryRegressionTest extends DuskTestCase
{
    use DatabaseTruncation;

    /** @return array{0: User, 1: Workout, 2: WorkoutLine} */
    private function aSessionWith(string $type): array
    {
        $user = User::factory()->create([
            'email' => 'set-entry-'.time().random_int(0, 99999).'@example.com',
            'email_verified_at' => now(),
        ]);

        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now(),
            'ended_at' => null,
        ]);

        $line = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => Exercise::factory()->create(['user_id' => $user->id, 'type' => $type])->id,
        ]);

        return [$user, $workout, $line];
    }

    /**
     * Holds a request open so the window under test is real.
     *
     * Against a local server these answer in tens of milliseconds, long gone
     * before Dusk can click anything, so the overlap these tests exist to cover
     * never happens and they pass on thoroughly broken code. Once the held
     * request has left, `window.__requeteRetenue` says so.
     */
    private function delayFirst(Browser $browser, string $method, string $urlPattern): void
    {
        $browser->script(<<<JS
            (function () {
                const original = window.axios;
                let seen = 0;
                const delayed = function (config) {
                    const matches = String(config.method).toLowerCase() === '{$method}'
                        && {$urlPattern}.test(String(config.url));

                    if (matches && ++seen === 1) {
                        window.__requeteRetenue = true;
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
    }

    /**
     * A time input reports an EMPTY string while its segments are incomplete,
     * and the handler ran `val.split(':').map(Number)` on it regardless. The
     * resulting NaN both re-rendered the field as 00:00:00 over what was being
     * typed — so it could not be filled in — and travelled into the payload,
     * where `JSON.stringify(NaN)` is null and cleared the duration in the row.
     */
    public function test_a_cardio_distance_and_duration_reach_the_database(): void
    {
        [$user, $workout, $line] = $this->aSessionWith('cardio');

        $this->browse(function (Browser $browser) use ($user, $workout): void {
            $browser->loginAs(User::find($user->id))
                ->resizeToIphone15()
                ->visit('/workouts/'.$workout->id)
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@add-set-0', 15)
                ->click('@add-set-0')
                ->waitFor('@distance-input-0-0', 15)
                ->type('@distance-input-0-0', '5.5')
                ->pickDuration('@duration-input-0-0', 0, 25, 30)
                // Leaves the distance field, which is what commits it.
                ->click('#main-content');

            // Debounced write: wait for the row this test asserts on below.
            $this->waitForDatabase(fn (): bool => (int) ($workout->workoutLines()->first()?->sets()->first()?->duration_seconds ?? 0) === 1530);

            $browser->assertNoConsoleExceptions();
        });

        $set = $line->sets()->first();

        $this->assertNotNull($set, 'the set never reached the database');
        $this->assertSame(1530, $set->duration_seconds, 'the duration typed was not the duration saved');
        $this->assertSame(5.5, (float) $set->distance_km, 'the distance typed was not the distance saved');
    }

    /**
     * A new set used to be filled in with all four measurements whatever the
     * exercise measured, so a cardio row was written with `reps: 10` and a
     * weight of 0 — neither of which its row renders, and neither of which
     * anyone typed. That reps pre-fill of 10 is where a 10 nobody entered came
     * from.
     */
    public function test_a_cardio_set_is_not_given_reps_or_a_weight_nobody_entered(): void
    {
        [$user, $workout, $line] = $this->aSessionWith('cardio');

        $this->browse(function (Browser $browser) use ($user, $workout, $line): void {
            $browser->loginAs(User::find($user->id))
                ->resizeToIphone15()
                ->visit('/workouts/'.$workout->id)
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@add-set-0', 15)
                ->click('@add-set-0')
                ->waitFor('@distance-input-0-0', 15);

            $this->waitForDatabase(fn (): bool => $line->sets()->exists());

            $browser->assertNoConsoleExceptions();
        });

        $set = $line->sets()->first();

        $this->assertNotNull($set, 'the set never reached the database');
        $this->assertNull($set->reps, 'a cardio set was given reps the user never entered');
        $this->assertNull($set->weight, 'a cardio set was given a weight the user never entered');
    }

    /**
     * The mirror of the above: a strength set has no distance and no duration.
     */
    public function test_a_strength_set_is_not_given_a_distance_or_a_duration_nobody_entered(): void
    {
        [$user, $workout, $line] = $this->aSessionWith('strength');

        $this->browse(function (Browser $browser) use ($user, $workout, $line): void {
            $browser->loginAs(User::find($user->id))
                ->resizeToIphone15()
                ->visit('/workouts/'.$workout->id)
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@add-set-0', 15)
                ->click('@add-set-0')
                ->waitFor('@weight-input-0-0', 15);

            $this->waitForDatabase(fn (): bool => $line->sets()->exists());

            $browser->assertNoConsoleExceptions();
        });

        $set = $line->sets()->first();

        $this->assertNotNull($set, 'the set never reached the database');
        $this->assertNull($set->distance_km, 'a strength set was given a distance the user never entered');
        $this->assertNull($set->duration_seconds, 'a strength set was given a duration the user never entered');
    }

    /**
     * The same field, on an exercise that only has a duration.
     *
     * Typed while the create that gives the set its real id may still be in
     * flight, so this also covers the row surviving that id swap.
     */
    public function test_a_timed_duration_reaches_the_database(): void
    {
        [$user, $workout, $line] = $this->aSessionWith('timed');

        $this->browse(function (Browser $browser) use ($user, $workout): void {
            $browser->loginAs(User::find($user->id))
                ->resizeToIphone15()
                ->visit('/workouts/'.$workout->id)
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@add-set-0', 15)
                ->click('@add-set-0')
                ->waitFor('@duration-input-0-0', 15);

            /**
             * Marks the node before the id swap so the assertion below can tell
             * that the field typed into is still the field on screen — a row
             * rebuilt around the real id would drop this attribute and take the
             * entry with it.
             *
             * Its own statement: script() returns the evaluated values, not the
             * browser, so chaining onto it calls a Browser method on an array.
             */
            $browser->script('document.querySelector(\'[dusk="duration-input-0-0"]\').dataset.probe = "kept";');

            $browser->pickDuration('@duration-input-0-0', 0, 10, 0)
                ->assertAttribute('@duration-input-0-0', 'data-probe', 'kept')
                ->assertSeeIn('@duration-input-0-0', '00:10:00')
                ->click('#main-content');

            // Debounced write: wait for the row this test asserts on below.
            $this->waitForDatabase(fn (): bool => (int) ($workout->workoutLines()->first()?->sets()->first()?->duration_seconds ?? 0) === 600);

            $browser->assertNoConsoleExceptions();
        });

        $set = $line->sets()->first();

        $this->assertNotNull($set, 'the set never reached the database');
        $this->assertSame(600, $set->duration_seconds, 'the duration typed was not the duration saved');
    }

    /**
     * The same entry, made after the set has finished being created.
     *
     * The test above types into the row while the POST that gives the set its
     * real id may still be in flight; this one waits for that to land first, so
     * the two together cover a duration typed either side of the placeholder-to-
     * real id swap. Both must reach the database.
     */
    public function test_a_timed_duration_typed_once_the_set_exists_reaches_the_database(): void
    {
        [$user, $workout, $line] = $this->aSessionWith('timed');

        $this->browse(function (Browser $browser) use ($user, $workout, $line): void {
            $browser->loginAs(User::find($user->id))
                ->resizeToIphone15()
                ->visit('/workouts/'.$workout->id)
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@add-set-0', 15)
                ->click('@add-set-0')
                ->waitFor('@duration-input-0-0', 15);

            // The one difference from the test above: the create has landed.
            $this->waitForDatabase(fn (): bool => $line->sets()->exists());

            $browser->pickDuration('@duration-input-0-0', 0, 10, 0)
                ->assertSeeIn('@duration-input-0-0', '00:10:00')
                ->click('#main-content');

            // Debounced write: wait for the row this test asserts on below.
            $this->waitForDatabase(fn (): bool => (int) ($workout->workoutLines()->first()?->sets()->first()?->duration_seconds ?? 0) === 600);

            $browser->assertNoConsoleExceptions();
        });

        $set = $line->sets()->first();

        $this->assertNotNull($set, 'the set never reached the database');
        $this->assertSame(600, $set->duration_seconds, 'the duration typed was not the duration saved');
    }

    /**
     * A row carries two ways to delete a set, and only one of them works on any
     * given input. The swipe needs touch — SwipeableRow listens for nothing else
     * — so the button has to stay wherever there is a mouse. On a phone it is
     * redundant clutter beside a gesture that already does the job.
     */
    public function test_a_set_row_offers_the_delete_its_input_can_actually_reach(): void
    {
        [$user, $workout] = $this->aSessionWith('strength');

        $this->browse(function (Browser $browser) use ($user, $workout): void {
            $browser->loginAs(User::find($user->id))
                ->resizeToIphone15()
                ->visit('/workouts/'.$workout->id)
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@add-set-0', 15)
                ->click('@add-set-0')
                ->waitFor('@weight-input-0-0', 15);

            $shown = fn (): bool => (bool) $browser->script(
                'return document.querySelector(\'[dusk="remove-set-0-0"]\').offsetParent !== null;'
            )[0];

            $this->assertFalse($shown(), 'the row keeps a delete button a phone does not need');

            // Wide enough for a pointer, where the swipe is unavailable.
            $browser->resize(900, 900)
                ->waitUsing(10, 100, $shown, 'a pointer is left with no way to delete a set');

            // The swipe action is there either way, and reachable without one.
            $browser->assertPresent('@swipe-remove-set-0-0');
        });
    }

    /**
     * A set has no order of its own: the database hands them back by id, and the
     * id goes to whichever INSERT arrives first. Both creates used to be in
     * flight at once, so tapping twice in quick succession could write the
     * second set first and the two came back swapped on the next load.
     *
     * The delay on the first create is what makes that inversion certain rather
     * than merely possible.
     */
    public function test_two_sets_added_in_quick_succession_keep_the_order_they_were_added(): void
    {
        [$user, $workout, $line] = $this->aSessionWith('strength');

        $this->browse(function (Browser $browser) use ($user, $workout): void {
            $browser->loginAs(User::find($user->id))
                ->resizeToIphone15()
                ->visit('/workouts/'.$workout->id)
                ->disableAnimations()
                ->waitFor('#main-content', 30);

            $this->delayFirst($browser, 'post', '/\/sets(\?|$)/');

            $browser->waitFor('@add-set-0', 15)
                ->click('@add-set-0')
                ->click('@add-set-0')
                ->waitFor('@weight-input-0-1', 15)
                ->type('@weight-input-0-0', '60')
                ->type('@weight-input-0-1', '80')
                ->click('#main-content');

            // The held create, the one queued behind it, and both debounces: wait
            // for both rows rather than for a duration long enough to cover them.
            $this->waitForDatabase(fn (): bool => $workout->workoutLines()->first()?->sets()->get()->map(fn ($s): float => (float) $s->weight)->all() === [60.0, 80.0]);

            $browser->assertNoConsoleExceptions();
        });

        $weights = $line->sets()->get()->map(fn (Set $set): float => (float) $set->weight)->all();

        $this->assertSame(
            [60.0, 80.0],
            $weights,
            'the sets came back in a different order from the one they were added in'
        );
    }

    /**
     * Two writes for one field really do overlap — a flush pushes the first out
     * and the next correction starts another — and the older value landing
     * second was written over the newer one and kept. Guarding which answer to
     * believe fixes the screen; only sending them in order fixes the row.
     */
    public function test_a_corrected_value_is_the_one_the_database_keeps(): void
    {
        [$user, $workout, $line] = $this->aSessionWith('strength');
        $set = Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 5]);

        $this->browse(function (Browser $browser) use ($user, $workout): void {
            $browser->loginAs(User::find($user->id))
                ->resizeToIphone15()
                ->visit('/workouts/'.$workout->id)
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@weight-input-0-0', 15);

            $this->delayFirst($browser, 'patch', '/\/sets\/\d+/');

            $browser->type('@weight-input-0-0', '100')
                ->click('#main-content')
                // The debounce has fired: this write is on the wire, held by delayFirst.
                ->waitUntil('window.__requeteRetenue === true', 10)
                ->type('@weight-input-0-0', '110')
                ->click('#main-content');

            // Wait for the correction to have overtaken the value it replaces —
            // exactly what is asserted below — instead of a duration sized to hope.
            $this->waitForDatabase(fn (): bool => (float) ($workout->workoutLines()->first()?->sets()->first()?->weight ?? 0) === 110.0);

            $browser->assertNoConsoleExceptions()
                ->assertInputValue('@weight-input-0-0', '110');
        });

        $this->assertSame(
            110.0,
            (float) $set->refresh()->weight,
            'the correction was overtaken by the value it replaced'
        );
    }
}
