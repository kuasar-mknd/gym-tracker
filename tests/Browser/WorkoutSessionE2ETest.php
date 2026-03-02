<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

final class WorkoutSessionE2ETest extends DuskTestCase
{
    use DatabaseTruncation;

    /**
     * Test a complete workout session flow on a mobile viewport (iPhone 15 Pro).
     */
    public function test_ultra_complete_workout_session_flow(): void
    {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john'.time().'@example.com',
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

        $this->browse(function (Browser $browser) use ($user, $exercises, $workout): void {
            try {
                $browser->loginAs($user)
                    ->visit("/workouts/{$workout->id}")
                    ->waitFor('main', 30);

                // 1. Add exercise
                $browser->waitFor('@add-first-exercise', 15)
                    ->script("document.querySelector('[dusk=\"add-first-exercise\"]').click();");

                $browser->waitFor('input[placeholder="Rechercher..."]', 15)
                    ->type('input[placeholder="Rechercher..."]', $exercises[0]->name)
                    ->pause(1500)
                    ->waitFor('@select-exercise-'.$exercises[0]->id, 20)
                    ->click('@select-exercise-'.$exercises[0]->id);

                // Wait for card
                $browser->waitFor('@exercise-card-0', 30);

                // 2. Click Add Set
                $browser->script("document.querySelector('[dusk=\"add-set-0\"]').click();");

                // 3. Wait for the new set
                $browser->waitFor('@weight-input-0-0', 30);

                // 4. Fill values
                $browser->type('@weight-input-0-0', '80')
                    ->pause(500)
                    ->type('@reps-input-0-0', '5')
                    ->pause(500);

                // Hide keyboard/unfocus to ensure visibility
                $browser->script('document.activeElement.blur();');
                $browser->pause(1000);

                // 5. Complete set (using JS click for mobile reliability)
                $browser->waitFor('@complete-set-0-0', 15)
                    ->script("document.querySelector('[dusk=\"complete-set-0-0\"]').click();");
                $browser->pause(1000);

                // Skip rest timer
                $browser->script("
                    const skipBtn = document.querySelector('[dusk=\"skip-rest-timer\"]');
                    if (skipBtn) skipBtn.click();
                ");
                $browser->pause(1000);

                // 6. Finish Workout
                $browser->waitFor('#finish-workout-mobile', 15)
                    ->script("document.querySelector('#finish-workout-mobile').click();");

                $browser->waitFor('#confirm-finish-button', 15)
                    ->click('#confirm-finish-button');

                // 7. Verify
                $browser->waitForLocation('/dashboard', 30)
                    ->assertSee('FAIT');

            } catch (\Exception $e) {
                $browser->screenshot('workout-failure-final');
                $logs = $browser->driver->manage()->getLog('browser');
                dump($logs);
                throw $e;
            }
        });
    }
}
