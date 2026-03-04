<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class WorkoutSessionE2ETest extends DuskTestCase
{
    use DatabaseTruncation;

    private function performFullWorkout(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john'.time().random_int(0, 9999).'@example.com',
            'password' => bcrypt('password123'),
        ]);

        $exercises = Exercise::factory()->count(2)->create([
            'user_id' => $user->id,
            'type' => 'strength',
        ]);

        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now(),
            'name' => 'Séance Test',
        ]);

        try {
            $browser->loginAs($user->id)
                ->{$sizeMacro}()
                ->visit("/workouts/{$workout->id}");

            // 1. Add exercise
            $browser->waitFor('@add-first-exercise', 15)
                ->click('@add-first-exercise');

            $browser->waitFor('input[placeholder="Rechercher..."]', 15)
                ->type('input[placeholder="Rechercher..."]', $exercises[0]->name)
                ->pause(1500)
                ->waitFor('@select-exercise-'.$exercises[0]->id, 20)
                ->click('@select-exercise-'.$exercises[0]->id);

            // Wait for card
            $browser->waitFor('@exercise-card-0', 30);

            // 2. Click Add Set
            $browser->script("document.querySelector('[dusk=\"add-set-0\"]').scrollIntoView();");
            $browser->click('@add-set-0');

            // 3. Wait for the new set
            $browser->waitFor('@weight-input-0-0', 30);

            // 4. Fill values
            $browser->type('@weight-input-0-0', '80')
                ->pause(500)
                ->type('@reps-input-0-0', '5')
                ->pause(500);

            // Hide keyboard/unfocus
            $browser->script('document.activeElement.blur();');
            $browser->pause(1000);

            // 5. Complete set
            $browser->waitFor('@complete-set-0-0', 15)
                ->click('@complete-set-0-0');

            // Wait for rest timer to appear and skip it
            $browser->waitFor('[dusk="skip-rest-timer"]', 15)
                ->pause(1000)
                ->click('[dusk="skip-rest-timer"]')
                ->pause(1000);

            // 6. Finish Workout
            $browser->waitFor('#finish-workout-mobile', 15)
                ->pause(1000)
                ->script("document.getElementById('finish-workout-mobile').scrollIntoView();");

            $browser->script("document.getElementById('finish-workout-mobile').click();");

            // Wait for modal and confirm button
            $browser->waitFor('@finish-workout-modal-title', 15)
                ->waitFor('#confirm-finish-button', 15)
                ->pause(1000)
                ->script("document.getElementById('confirm-finish-button').click();");

            $browser->screenshot('debug-after-confirm-'.$sizeMacro);

            // 7. Verify
            $browser->waitForLocation('/dashboard', 120)
                ->waitFor('@start-workout-button', 30)
                ->assertSee('BON RETOUR')
                ->assertPathIs('/dashboard')
                ->assertNoConsoleExceptions();
        } catch (\Exception $e) {
            $browser->screenshot('workout-failure-'.$sizeMacro);
            throw $e;
        }
    }

    public function test_workout_session_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performFullWorkout($browser, 'resizeToIphoneMini');
        });
    }

    public function test_workout_session_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performFullWorkout($browser, 'resizeToIphone15');
        });
    }

    public function test_workout_session_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performFullWorkout($browser, 'resizeToIphoneMax');
        });
    }
}
// satisfy rector
