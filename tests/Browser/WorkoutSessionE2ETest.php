<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

final class WorkoutSessionE2ETest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_ultra_complete_workout_session_flow(): void
    {
        // 1. Setup the user and 6 strength exercises
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $exercises = Exercise::factory()->count(6)->create([
            'user_id' => $user->id,
            'type' => 'strength', // Keep it simple to just test reps and weights
        ]);

        $this->browse(function (Browser $browser) use ($user, $exercises): void {
            // Screen size big enough to see everything optimally
            $browser->loginAs($user)
                ->resize(1920, 1080)
                ->visit('/dashboard')
                ->waitFor('@start-workout-button', 30)
                ->assertPathIs('/dashboard');

            // 2. Start a workout session from dashboard
            $browser->click('@start-workout-button')
                ->waitUntil('window.location.pathname.startsWith("/workouts/")', 15); // It redirects to the newly created workout page

            // Allow the JS to render Show.vue
            $browser->waitFor('main', 10)
                ->waitForText('Séance', 10);

            // 3. Add 6 exercises and 4 sets each sequentially
            foreach ($exercises as $index => $exercise) {
                // Determine line index (it will match the outer loop index)
                $lineIndex = $index;

                // Click 'Ajouter un exercice'
                if ($index === 0) {
                    // First time, the button has the add-first-exercise dusk attribute
                    $browser->waitFor('@add-first-exercise', 30)
                        ->click('@add-first-exercise');
                } else {
                    // Subsequent times, the button has a different dusk tag at the bottom
                    $browser->waitFor('@add-exercise-existing', 30)
                        ->click('@add-exercise-existing');
                }

                // Wait for the modal and select the specific exercise
                $browser->waitFor('@select-exercise-'.$exercise->id, 20)
                    ->click('@select-exercise-'.$exercise->id);

                // Wait for the modal to close and the exercise card to appear
                $browser->waitFor('@exercise-card-'.$lineIndex, 30);

                // There is automatically 1 empty set for a newly added exercise
                // We want 4 sets in total, so we add 3 more sets.
                for ($setIndex = 0; $setIndex < 4; $setIndex++) {
                    $browser->waitFor('@add-set-'.$lineIndex, 20)
                        ->script("document.querySelector('[dusk=\"add-set-{$lineIndex}\"]').click();");
                    $browser->pause(200); // Give a bit of time for DOM
                }

                $logs = $browser->driver->manage()->getLog('browser');
                if (! empty($logs)) {
                    dump($logs);
                }

                // Now fill the weights and reps for the 4 sets
                for ($setIndex = 0; $setIndex < 4; $setIndex++) {
                    // Base weight that increases across sets to trigger PR intentionally
                    $weight = 50 + ($index * 10) + ($setIndex * 5); // Ex: 50, 55, 60, 65
                    $reps = 10 - $setIndex;                         // Ex: 10, 9,  8,  7

                    try {
                        $browser->waitFor('@weight-input-'.$lineIndex.'-'.$setIndex, 20)
                            ->clear('@weight-input-'.$lineIndex.'-'.$setIndex)
                            ->type('@weight-input-'.$lineIndex.'-'.$setIndex, (string) $weight);

                        $browser->waitFor('@reps-input-'.$lineIndex.'-'.$setIndex, 20)
                            ->clear('@reps-input-'.$lineIndex.'-'.$setIndex)
                            ->type('@reps-input-'.$lineIndex.'-'.$setIndex, (string) $reps);
                    } catch (\Exception $e) {
                        dump("Exception on setIndex $setIndex. Dumping logs:");
                        dump($browser->driver->manage()->getLog('browser'));
                        throw $e;
                    }

                    // Validate (Complete) the set
                    $browser->waitFor('@complete-set-'.$lineIndex.'-'.$setIndex, 10)
                        ->click('@complete-set-'.$lineIndex.'-'.$setIndex);

                    $browser->assertNoConsoleExceptions();

                    if (count($browser->elements('@skip-rest-timer')) > 0) {
                        $browser->click('@skip-rest-timer');
                    }
                }
            }

            // 4. Terminer la séance
            $browser->waitFor('#finish-workout-desktop', 10)
                ->script("document.querySelector('#finish-workout-desktop').click();");

            // Handle confirm modal
            // (Assumes id was left on confirm button or a generic OK)
            $browser->waitFor('#confirm-finish-button', 10)
                ->pause(500)
                ->script("document.getElementById('confirm-finish-button').click();");

            // 5. Redirection to Dashboard correctly handled
            $browser->waitForLocation('/dashboard', 20)
                ->assertPathIs('/dashboard')
                ->storeSource('dashboard-source')
                ->waitForText('FAIT', 10); // Recent activity should show 'Terminée' or 'FAIT'
        });
    }
}
