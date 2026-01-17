<?php

namespace Tests\Browser;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class WorkoutCompletionTest extends DuskTestCase
{
    use DatabaseTruncation;

    /**
     * Helper method to manually login a user via the login form.
     * This is more reliable than loginAs() in CI environments.
     */
    private function manualLogin(Browser $browser, User $user, string $password = 'password123'): Browser
    {
        return $browser->logout()
            ->visit('/login')
            ->resize(1920, 1080)
            ->waitFor('input[type="email"]', 15)
            ->type('input[type="email"]', $user->email)
            ->type('input[type="password"]', $password)
            ->click('button[type="submit"]')
            ->waitForLocation('/dashboard', 15);
    }

    public function test_user_can_finish_workout_and_is_redirected(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'name' => 'Séance Test Browser',
            'started_at' => now()->subHour(),
        ]);

        $this->browse(function (Browser $browser) use ($user, $workout) {
            $this->manualLogin($browser, $user)
                ->resize(1920, 1080)
                ->visit('/workouts/'.$workout->id)
                ->waitFor('main', 15)
                ->assertPathIs('/workouts/'.$workout->id)
                ->assertNoConsoleExceptions()
                ->waitForText('SÉANCE TEST BROWSER', 15)
                ->waitFor('#finish-workout-desktop', 15)
                ->click('#finish-workout-desktop')
                ->waitForText('TERMINER LA SÉANCE ?', 15)
                ->pause(500)
                ->click('#confirm-finish-button')
                ->waitForRoute('dashboard', [], 15)
                ->assertSee('FAIT');
        });
    }

    public function test_finished_workout_is_immutable_in_ui(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'name' => 'Immutable Workout',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]);

        $this->browse(function (Browser $browser) use ($user, $workout) {
            $this->manualLogin($browser, $user)
                ->resize(1920, 1080)
                ->visit('/workouts/'.$workout->id)
                ->waitFor('main', 15)
                ->assertNoConsoleExceptions()
                ->waitForText('IMMUTABLE WORKOUT', 15)
                ->assertMissing('#finish-workout-desktop')
                ->assertVisible('#workout-status-badge-desktop')
                ->assertSee('TERMINÉE')
                ->assertMissing('button[aria-label="Ajouter une série"]')
                ->assertMissing('button[aria-label="Ajouter un exercice"]');
        });
    }
}
