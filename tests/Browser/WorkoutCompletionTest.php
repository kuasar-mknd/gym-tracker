<?php

namespace Tests\Browser;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Group;
use Tests\DuskTestCase;

/**
 * Browser tests for workout completion functionality.
 *
 * Note: These tests are marked as 'skip-ci' because loginAs() has issues
 * with session persistence in the CI environment. The functionality is
 * fully covered by feature tests in WorkoutCompletionTest.php.
 */
#[Group('skip-ci')]
class WorkoutCompletionTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_user_can_finish_workout_and_is_redirected(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'name' => 'Séance Test Browser',
            'started_at' => now()->subHour(),
        ]);

        $this->browse(function (Browser $browser) use ($user, $workout) {
            $browser->loginAs($user)
                ->visit('/dashboard')
                ->waitForRoute('dashboard', [], 10)
                ->resize(1920, 1080)
                ->visitRoute('workouts.show', $workout)
                ->waitForText('Séance Test Browser', 10)
                ->waitForText('TERMINER', 10)
                ->press('Terminer')
                ->waitForText('Terminer la séance ?', 10)
                ->pause(500)
                ->clickLink('Confirmer')
                ->waitForRoute('dashboard', [], 15)
                ->assertSee('Fait');
        });
    }

    public function test_finished_workout_is_immutable_in_ui(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'name' => 'Immutable Workout',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]);

        $this->browse(function (Browser $browser) use ($user, $workout) {
            $browser->loginAs($user)
                ->visit('/dashboard')
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
