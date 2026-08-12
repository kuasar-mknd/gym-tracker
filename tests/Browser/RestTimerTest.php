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

    /**
     * Remaining rest time, in seconds, read from the "M:SS" timer.
     */
    private function timerSecondsLeft(Browser $browser): int
    {
        [$minutes, $seconds] = array_map(intval(...), explode(':', trim($browser->text('[role="timer"]'))));

        return $minutes * 60 + $seconds;
    }

    /**
     * Uncheck the set, then check it again to raise a fresh rest timer.
     *
     * Both clicks target the same toggle, so the second one must not fire until
     * the first has been applied. Waiting on the button's own aria-label is what
     * makes that deterministic: a fixed pause used to lose the race whenever the
     * CI runner was slower than the delay, which is where this test flaked.
     */
    private function retriggerRestTimer(Browser $browser): Browser
    {
        return $browser->click('[dusk="complete-set-0-0"]')
            ->waitFor('[dusk="complete-set-0-0"][aria-label="Valider la série"]', 10)
            ->click('[dusk="complete-set-0-0"]')
            ->waitFor('[dusk="complete-set-0-0"][aria-label="Annuler la série"]', 10)
            ->waitFor('[dusk="skip-rest-timer"]', 15);
    }

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
            $browser->loginAs(User::find($user->id))
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

            // 2. Add Time (+30s) — the countdown is ticking down, so the only stable
            // fact is that the remaining time went UP. Waiting on that condition also
            // removes the race a fixed pause left open on a slow runner.
            $secondsLeft = fn (): int => $this->timerSecondsLeft($browser);
            $before = $secondsLeft();

            $browser->click('@add-30s')
                ->waitUsing(10, 100, fn (): bool => $secondsLeft() > $before, 'Le minuteur n’a pas augmenté après +30s');

            // 3. Close via "X" button
            $browser->click('@close-timer-x')
                ->waitUntilMissing('[dusk="skip-rest-timer"]', 10);

            // 4. Trigger again and close via "Fermer" button
            $this->retriggerRestTimer($browser)
                ->click('@close-timer')
                ->waitUntilMissing('[dusk="skip-rest-timer"]', 10);

            // 5. Trigger again and use "Skip" (Passer)
            $this->retriggerRestTimer($browser)
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
