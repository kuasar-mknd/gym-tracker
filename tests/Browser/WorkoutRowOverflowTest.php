<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The set row packs a check button, an index badge, two fixed-width inputs, two
 * unit labels and a delete button onto one line. On a 375pt screen that exceeds
 * the available width, and SwipeableRow clips the overflow — so the controls at
 * the end of the row are simply unreachable on the narrowest iPhones.
 */
class WorkoutRowOverflowTest extends DuskTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function mobileViewports(): array
    {
        return [
            'iphone mini' => ['resizeToIphoneMini'],
            'iphone 15' => ['resizeToIphone15'],
            'iphone max' => ['resizeToIphoneMax'],
        ];
    }

    private function workoutWithSets(User $user): Workout
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'type' => 'strength',
            'name' => 'Développé couché',
        ]);

        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now(),
            'ended_at' => null,
        ]);

        $line = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        Set::factory()->count(3)->create([
            'workout_line_id' => $line->id,
            'weight' => 100,
            'reps' => 10,
        ]);

        return $workout;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mobileViewports')]
    public function test_the_set_row_fits_the_screen(string $viewport): void
    {
        $this->browse(function (Browser $browser) use ($viewport): void {
            $user = User::factory()->create();
            $workout = $this->workoutWithSets($user);

            $browser->loginAs($user)
                ->{$viewport}()
                ->visit("/workouts/{$workout->id}")
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@weight-input-0-0', 15);

            // scrollWidth beyond clientWidth on the clipping ancestor means content
            // is being cut off rather than merely wrapped.
            $overflow = $browser->script(
                'const input = document.querySelector(\'[dusk="weight-input-0-0"]\');'
                .'const row = input.closest(".relative.overflow-hidden") || input.parentElement;'
                .'return row.scrollWidth - row.clientWidth;'
            )[0];

            $this->assertLessThanOrEqual(
                1,
                $overflow,
                "The set row overflows its container by {$overflow}px, clipping the controls at its end."
            );
        });
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mobileViewports')]
    public function test_the_set_controls_keep_their_touch_size(string $viewport): void
    {
        $this->browse(function (Browser $browser) use ($viewport): void {
            $user = User::factory()->create();
            $workout = $this->workoutWithSets($user);

            $browser->loginAs($user)
                ->{$viewport}()
                ->visit("/workouts/{$workout->id}")
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@complete-set-0-0', 15);

            // Making the row fit must not come out of the controls: letting the
            // check button shrink squeezes it to ~28px, well under a usable target.
            $box = $browser->script(
                'const b = document.querySelector(\'[dusk="complete-set-0-0"]\').getBoundingClientRect();'
                .'return [Math.round(b.width), Math.round(b.height)];'
            )[0];

            [$width, $height] = $box;

            $this->assertGreaterThanOrEqual(40, $width, "Check button is only {$width}px wide.");
            $this->assertGreaterThanOrEqual(40, $height, "Check button is only {$height}px tall.");
        });
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mobileViewports')]
    public function test_the_page_never_scrolls_sideways(string $viewport): void
    {
        $this->browse(function (Browser $browser) use ($viewport): void {
            $user = User::factory()->create();
            $workout = $this->workoutWithSets($user);

            $browser->loginAs($user)
                ->{$viewport}()
                ->visit("/workouts/{$workout->id}")
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@weight-input-0-0', 15);

            $overflow = $browser->script(
                'return document.documentElement.scrollWidth - document.documentElement.clientWidth;'
            )[0];

            $this->assertLessThanOrEqual(1, $overflow, "The page scrolls sideways by {$overflow}px.");
        });
    }
}
