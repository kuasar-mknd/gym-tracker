<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

final class WorkoutCompletionTest extends DuskTestCase
{
    use DatabaseMigrations;

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

        $this->browse(function (Browser $browser) use ($user, $workout): void {
            $browser->loginAs($user)
                ->resize(1920, 1080)
                ->visit('/workouts/'.$workout->id)
                ->waitFor('main', 20)
                ->assertPathIs('/workouts/'.$workout->id)
                ->assertNoConsoleExceptions()
                ->waitFor('#finish-workout-desktop', 20)
                ->pause(1000)
                ->click('#finish-workout-desktop');

            $browser->waitForText('Terminer la séance ?', 20)
                ->pause(1000)
                ->click('#confirm-finish-button');

            $browser->waitForLocation('/dashboard', 20);
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

        $this->browse(function (Browser $browser) use ($user, $workout): void {
            $browser->loginAs($user)
                ->resize(1920, 1080)
                ->visit('/workouts/'.$workout->id)
                ->waitFor('main', 15)
                ->assertNoConsoleExceptions()
                ->assertMissing('#finish-workout-desktop')
                ->assertVisible('#workout-status-badge-desktop');
        });
    }
}
