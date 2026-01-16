<?php

namespace Tests\Browser;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class WorkoutCompletionTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_user_can_finish_workout_and_is_redirected()
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'name' => 'Séance Test Browser',
            'started_at' => now()->subHour(),
        ]);

        $this->browse(function (Browser $browser) use ($user, $workout) {
            $browser->visit('/login')
                ->type('input[type="email"]', $user->email)
                ->type('input[type="password"]', 'password')
                ->press('SE CONNECTER') // Or the submit button text/selector
                ->waitForRoute('dashboard', [], 10)
                ->resize(1920, 1080)
                ->visitRoute('workouts.show', $workout)
                ->waitForText('Séance Test Browser', 10)
                // Use a broader selector or text that is definitely there
                ->waitForText('TERMINER', 10)
                ->press('Terminer') // Usually works if button text is this
                ->waitForText('Terminer la séance ?', 10) // Wait for modal
                ->pause(500) // Small pause for animation
                ->clickLinkOrButton('Confirmer')
                ->waitForRoute('dashboard', [], 10)
                ->assertSee('Fait');
        });
    }

    public function test_finished_workout_is_immutable_in_ui()
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'), // Ensure password is known
        ]);
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'name' => 'Immutable Workout',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]);

        $this->browse(function (Browser $browser) use ($user, $workout) {
            $browser->visit('/login')
                ->type('input[type="email"]', $user->email)
                ->type('input[type="password"]', 'password')
                ->press('SE CONNECTER')
                ->waitForRoute('dashboard', [], 10)
                ->resize(1920, 1080)
                ->visitRoute('workouts.show', $workout)
                ->waitForText('Immutable Workout', 10)
                ->assertDontSee('TERMINER')
                ->assertSee('TERMINÉE')
                ->assertMissing('button[aria-label="Ajouter une série"]')
                ->assertMissing('button[aria-label="Ajouter un exercice"]');
        });
    }
}
