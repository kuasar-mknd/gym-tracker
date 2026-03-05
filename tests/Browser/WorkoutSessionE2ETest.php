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
                ->waitFor('@main-content', 30);

            // 1. Add exercise
            $browser->waitFor('@add-first-exercise', 15)
                ->script("document.querySelector('[dusk=\"add-first-exercise\"]').click();");

            $browser->waitFor('input[placeholder="Rechercher..."]', 15)
                ->type('input[placeholder="Rechercher..."]', $exercises[0]->name)
                ->pause(1500)
                ->script("
                    const items = document.querySelectorAll('[dusk^=\"select-exercise-\"]');
                    const target = Array.from(items).find(i => i.textContent.includes('".$exercises[0]->name."'));
                    if (target) target.click();
                ");

            // Wait for card
            $browser->waitFor('@exercise-card-0', 30);

            // 2. Click Add Set
            $browser->script("
                const addSetBtn = document.querySelector('[dusk=\"add-set-0\"]');
                if (addSetBtn) {
                    addSetBtn.scrollIntoView();
                    addSetBtn.click();
                }
            ");

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
            $browser->script("
                const completeBtn = document.querySelector('[dusk=\"complete-set-0-0\"]');
                if (completeBtn) {
                    completeBtn.scrollIntoView();
                    completeBtn.click();
                }
            ");

            // Wait for rest timer to appear and skip it
            $browser->waitFor('[dusk="skip-rest-timer"]', 15)
                ->pause(1000);

            $browser->script("document.querySelector('[dusk=\"skip-rest-timer\"]').click();");

            $browser->pause(1000);

            // 6. Finish Workout
            $browser->waitFor('#finish-workout-mobile', 15)
                ->pause(1000)
                ->script("document.getElementById('finish-workout-mobile').scrollIntoView();");

            $browser->script("document.getElementById('finish-workout-mobile').click();");

            // Wait for modal and confirm button
            $browser->waitFor('@finish-workout-modal-title', 15)
                ->waitFor('@confirm-finish-button', 15)
                ->pause(2000);

            // Aggressive retry loop for the final click to defeat flakiness
            $browser->script("
                const clickFinish = () => {
                    const btn = document.querySelector('[dusk=\"confirm-finish-button\"]');
                    if (btn) btn.click();
                };
                const interval = setInterval(() => {
                    clickFinish();
                    if (!document.querySelector('[dusk=\"finish-workout-modal-title\"]')) {
                        clearInterval(interval);
                    }
                }, 500);
                setTimeout(() => clearInterval(interval), 10000);
            ");

            $browser->waitForLocation('/dashboard', 120)
                ->waitFor('@start-workout-button', 60)
                ->assertSee('BON RETOUR')
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
