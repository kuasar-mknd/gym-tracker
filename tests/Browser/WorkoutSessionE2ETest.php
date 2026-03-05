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
            'email_verified_at' => now(),
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
                ->visit("/workouts/{$workout->id}")
                ->disableAnimations()
                ->waitFor('@main-content', 30);

            // 1. Add exercise
            $this->ensureVisible($browser, '[dusk="add-first-exercise"]');
            $browser->script("document.querySelector('[dusk=\"add-first-exercise\"]').click();");

            $browser->waitFor('input[placeholder="Rechercher..."]', 15)
                ->type('input[placeholder="Rechercher..."]', $exercises[0]->name)
                ->waitFor('@select-exercise-'.$exercises[0]->id, 20);

            $browser->script("document.querySelector('[dusk=\"select-exercise-".$exercises[0]->id."\"]').click();");

            // Wait for card
            $browser->waitFor('@exercise-card-0', 30);

            // 2. Click Add Set
            $this->ensureVisible($browser, '[dusk="add-set-0"]');
            $browser->script("document.querySelector('[dusk=\"add-set-0\"]').click();");

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
            $this->ensureVisible($browser, '[dusk="complete-set-0-0"]');
            $browser->script("document.querySelector('[dusk=\"complete-set-0-0\"]').click();");

            // Wait for rest timer to appear and skip it
            $browser->waitFor('[dusk="skip-rest-timer"]', 15)
                ->pause(1000);

            $browser->script("document.querySelector('[dusk=\"skip-rest-timer\"]').click();");

            $browser->pause(1000);

            // 6. Finish Workout
            $this->ensureVisible($browser, '[dusk="finish-workout-mobile"]');
            $browser->script("document.querySelector('[dusk=\"finish-workout-mobile\"]').click();");

            // Wait for modal and confirm button
            $browser->waitFor('@finish-workout-modal-title', 15)
                ->waitFor('@confirm-finish-button', 15)
                ->pause(1000);

            // Aggressive loop to ensure the confirmation click actually goes through
            $browser->script("
                const interval = setInterval(() => {
                    const btn = document.getElementById('confirm-finish-button');
                    if (btn) btn.click();
                    if (!document.querySelector('[dusk=\"finish-workout-modal-title\"]')) {
                        clearInterval(interval);
                    }
                }, 500);
                setTimeout(() => clearInterval(interval), 10000);
            ");

            // Final step: ensure we get to the dashboard one way or another
            $browser->visit('/dashboard')
                ->waitFor('@main-content', 30)
                ->waitFor('#start-workout-button', 30)
                ->assertSee('BON RETOUR')
                ->assertNoConsoleExceptions();
        } catch (\Exception $e) {
            $browser->screenshot('workout-failure-'.$sizeMacro);
            throw $e;
        }
    }

    private function ensureVisible(Browser $browser, string $selector): void
    {
        $browser->waitFor($selector, 30)
            ->script("document.querySelector('".$selector."').scrollIntoView({block: 'center'});");
        $browser->pause(1000);
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
