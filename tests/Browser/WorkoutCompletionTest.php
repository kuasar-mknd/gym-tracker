<?php

namespace Tests\Browser;

use App\Models\User;
use App\Models\Workout;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class WorkoutCompletionTest extends DuskTestCase
{
    /**
     * Helper method to manually login a user via the login form.
     * This is more reliable than loginAs() in CI environments.
     */
    private function manualLogin(Browser $browser, User $user, string $password = 'password123'): Browser
    {
        return $browser->logout()
            ->visit('/login')
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
                ->waitForText('Séance Test Browser', 15)
                ->waitForText('TERMINER', 15)
                ->press('Terminer')
                ->waitForText('Terminer la séance ?', 15)
                ->pause(500)
                ->clickLink('Confirmer')
                ->waitForRoute('dashboard', [], 15)
                ->assertSee('Fait');
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
                ->waitForText('Immutable Workout', 15)
                ->assertDontSee('TERMINER')
                ->assertSee('TERMINÉE')
                ->assertMissing('button[aria-label="Ajouter une série"]')
                ->assertMissing('button[aria-label="Ajouter un exercice"]');
        });
    }
}
