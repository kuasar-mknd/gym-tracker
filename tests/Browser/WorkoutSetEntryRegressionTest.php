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
     * never happens and they pass on thoroughly broken code.
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
                ->type('@duration-input-0-0', '002530')
                // Leaves both fields, which is what commits them.
                ->click('#main-content')
                // Outlasts the one-second debounce with room to spare.
                ->pause(3000)
                ->assertNoConsoleExceptions();
        });

        $set = $line->sets()->first();

        $this->assertNotNull($set, 'the set never reached the database');
        $this->assertSame(1530, $set->duration_seconds, 'the duration typed was not the duration saved');
        $this->assertSame(5.5, (float) $set->distance_km, 'the distance typed was not the distance saved');
    }

    /**
     * The same field, on an exercise that only has a duration.
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
                ->waitFor('@duration-input-0-0', 15)
                ->type('@duration-input-0-0', '001000')
                ->click('#main-content')
                ->pause(3000)
                ->assertNoConsoleExceptions();
        });

        $set = $line->sets()->first();

        $this->assertNotNull($set, 'the set never reached the database');
        $this->assertSame(600, $set->duration_seconds, 'the duration typed was not the duration saved');
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
                ->click('#main-content')
                // The held create, the one queued behind it, and both debounces.
                ->pause(9000)
                ->assertNoConsoleExceptions();
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
                // Long enough for the debounce to fire, so this write is genuinely
                // on the wire and held when the correction below is made.
                ->pause(1500)
                ->type('@weight-input-0-0', '110')
                ->click('#main-content')
                ->pause(7000)
                ->assertNoConsoleExceptions()
                ->assertInputValue('@weight-input-0-0', '110');
        });

        $this->assertSame(
            110.0,
            (float) $set->fresh()->weight,
            'the correction was overtaken by the value it replaced'
        );
    }
}
