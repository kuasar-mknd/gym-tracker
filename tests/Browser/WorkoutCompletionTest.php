<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class WorkoutCompletionTest extends DuskTestCase
{
    use DatabaseTruncation;

    private function performFinishWorkout(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'email' => 'completion-'.time().random_int(0, 999).'@example.com',
            'email_verified_at' => now(),
        ]);
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'name' => 'Test Workout',
            'started_at' => now()->subHour(),
        ]);
        $exercise = \App\Models\Exercise::factory()->create(['user_id' => $user->id]);
        $line = \App\Models\WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);
        \App\Models\Set::factory()->create([
            'workout_line_id' => $line->id,
            'is_completed' => true,
        ]);

        try {
            $browser->loginAs(User::find($user->id))
                ->{$sizeMacro}()
                ->visit('/workouts/'.$workout->id)
                ->disableAnimations()
                ->waitFor('#main-content', 30);

            // Ensure visibility by waiting for the element
            $browser->waitFor('@finish-workout-mobile', 30);

            $browser->pause(1000)
                ->click('@finish-workout-mobile');

            $browser->waitFor('@finish-workout-modal-title', 15)
                ->waitFor('#confirm-finish-button', 30)
                ->pause(1000)
                ->click('#confirm-finish-button');

            $browser->waitUsing(15, 500, fn (): bool => \App\Models\Workout::find($workout->id)->ended_at !== null);

            $browser->visit('/dashboard')
                ->waitFor('#dashboard-header', 30)
                ->assertSee('RETOUR')
                ->assertNoConsoleExceptions();
        } catch (\Exception $e) {
            $browser->screenshot('completion-failure-'.$sizeMacro);
            throw $e;
        }
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
        $user = User::factory()->create([
            'email' => 'immutable-'.time().random_int(0, 999).'@example.com',
            'email_verified_at' => now(),
        ]);
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'name' => 'Immutable Workout',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]);

        $this->browse(function (Browser $browser) use ($user, $workout): void {
            $browser->loginAs(User::find($user->id))
                ->resizeToIphoneMini()
                ->visit('/workouts/'.$workout->id)
                ->waitFor('#main-content', 30)
                ->assertMissing('@finish-workout-mobile')
                ->assertNoConsoleExceptions();
        });
    }
}
