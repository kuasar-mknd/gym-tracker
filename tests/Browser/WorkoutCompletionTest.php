<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

final class WorkoutCompletionTest extends DuskTestCase
{
    use DatabaseTruncation;

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

        // Add an exercise line so the finish button is visible
        $exercise = Exercise::factory()->create(['user_id' => $user->id]);
        WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        $this->browse(function (Browser $browser) use ($user, $workout): void {
            $browser->loginAs($user)
                ->visit('/workouts/'.$workout->id)
                ->waitFor('main', 30)
                ->assertPathIs('/workouts/'.$workout->id)
                ->assertNoConsoleExceptions()
                ->waitFor('#finish-workout-mobile', 30)
                ->click('#finish-workout-mobile');

            $browser->waitFor('#confirm-finish-button', 30)
                ->pause(1000)
                ->script("document.getElementById('confirm-finish-button').click();");

            $browser->waitForLocation('/dashboard', 30);
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
                ->visit('/workouts/'.$workout->id)
                ->waitFor('main', 30)
                ->assertNoConsoleExceptions()
                ->assertMissing('#finish-workout-mobile');
        });
    }
}
