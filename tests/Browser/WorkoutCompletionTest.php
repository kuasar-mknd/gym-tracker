<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class WorkoutCompletionTest extends DuskTestCase
{
    use DatabaseTruncation;

    private function setupWorkout(): array
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'email_verified_at' => now(),
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

        return [$user, $workout];
    }

    private function performFinishWorkout(Browser $browser, string $sizeMacro): void
    {
        [$user, $workout] = $this->setupWorkout();

        $browser->loginAs($user->id)
            ->{$sizeMacro}()
            ->visit('/workouts/'.$workout->id)
            ->disableAnimations()
            ->waitFor('@main-content', 30)
            ->assertPathIs('/workouts/'.$workout->id)
            ->waitFor('#finish-workout-mobile', 30)
            ->script("document.getElementById('finish-workout-mobile').scrollIntoView();");

        $browser->script("document.getElementById('finish-workout-mobile').click();");

        $browser->waitFor('@finish-workout-modal-title', 15)
            ->waitFor('#confirm-finish-button', 30)
            ->pause(1000)
            ->script("document.getElementById('confirm-finish-button').click();");

        // Wait for DB sync
        $browser->waitUntil(fn (): bool => \App\Models\Workout::find($workout->id)->ended_at !== null, 15);

        $browser->visit('/dashboard')
            ->waitFor('#dashboard-header', 30)
            ->assertSee('BON RETOUR');
    }

    public function test_user_can_finish_workout_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performFinishWorkout($browser, 'resizeToIphoneMini');
        });
    }

    public function test_user_can_finish_workout_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performFinishWorkout($browser, 'resizeToIphone15');
        });
    }

    public function test_user_can_finish_workout_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performFinishWorkout($browser, 'resizeToIphoneMax');
        });
    }

    public function test_finished_workout_is_immutable_on_iphone_mini(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'name' => 'Immutable Workout',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]);

        $this->browse(function (Browser $browser) use ($user, $workout): void {
            $browser->loginAs($user->id)
                ->resizeToIphoneMini()
                ->visit('/workouts/'.$workout->id)
                ->waitFor('@main-content', 30)
                ->assertNoConsoleExceptions()
                ->assertMissing('#finish-workout-mobile');
        });
    }
}
