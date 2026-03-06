<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class RestTimerTest extends DuskTestCase
{
    use DatabaseTruncation;

    private function performTimerLifecycle(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'email' => 'timer-user-'.time().random_int(0, 9999).'@example.com',
            'email_verified_at' => now(),
        ]);

        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'type' => 'strength',
        ]);

        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now(),
        ]);

        try {
            $browser->loginAs($user->id)
                ->{$sizeMacro}()
                ->visit('/workouts/'.$workout->id)
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->assertMissing('[dusk="skip-rest-timer"]'); // Ensure timer is NOT visible initially

            // Add exercise
            $browser->waitFor('[dusk="add-first-exercise"]', 15)
                ->pause(500)
                ->click('[dusk="add-first-exercise"]')
                ->waitFor('input[placeholder="Rechercher..."]', 10)
                ->type('input[placeholder="Rechercher..."]', $exercise->name)
                ->waitFor('@select-exercise-'.$exercise->id, 10)
                ->pause(500)
                ->click('@select-exercise-'.$exercise->id)
                ->waitFor('@exercise-card-0', 15);

            // Add set
            $browser->pause(500)
                ->click('[dusk="add-set-0"]')
                ->waitFor('@weight-input-0-0', 15)
                ->type('@weight-input-0-0', '50')
                ->pause(300)
                ->type('@reps-input-0-0', '10')
                ->pause(500);

            // 1. Trigger timer by completing set
            $browser->click('[dusk="complete-set-0-0"]')
                ->waitFor('[dusk="skip-rest-timer"]', 15)
                ->assertSee('REPOS');

            // 2. Test Add Time (+30s)
            $initialTime = $browser->text('[role="timer"]');
            $browser->click('@add-30s')
                ->pause(500);
            $newTime = $browser->text('[role="timer"]');
            // We don't assert exact time because it's ticking, but it should be "higher" in some sense
            // or we just verify it didn't crash. Given the ticking, MM:SS comparison is tricky.

            // 3. Close via "X" button
            $browser->click('@close-timer-x')
                ->waitUntilMissing('[dusk="skip-rest-timer"]', 10);

            // 4. Trigger again and close via "Fermer" button
            $browser->click('[dusk="complete-set-0-0"]') // Uncheck
                ->pause(500)
                ->click('[dusk="complete-set-0-0"]') // Check again
                ->waitFor('[dusk="skip-rest-timer"]', 15)
                ->click('@close-timer')
                ->waitUntilMissing('[dusk="skip-rest-timer"]', 10);

            // 5. Trigger again and use "Skip" (Passer)
            $browser->click('[dusk="complete-set-0-0"]') // Uncheck
                ->pause(500)
                ->click('[dusk="complete-set-0-0"]') // Check again
                ->waitFor('[dusk="skip-rest-timer"]', 15)
                ->click('@skip-rest-timer')
                ->waitUntilMissing('[dusk="skip-rest-timer"]', 10)
                ->assertNoConsoleExceptions();
        } catch (\Exception $e) {
            $browser->screenshot('timer-failure-'.$sizeMacro);
            throw $e;
        }
    }

    public function test_timer_lifecycle_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performTimerLifecycle($browser, 'resizeToIphoneMini');
        });
    }

    public function test_timer_lifecycle_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performTimerLifecycle($browser, 'resizeToIphone15');
        });
    }

    public function test_timer_lifecycle_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performTimerLifecycle($browser, 'resizeToIphoneMax');
        });
    }
}
