<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ExerciseManagementTest extends DuskTestCase
{
    use DatabaseTruncation;

    private function performExerciseManagement(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        try {
            $browser->loginAs($user->id)
                ->{$sizeMacro}()
                ->visit('/exercises')
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('[dusk="create-exercise-btn"]', 30)
                ->click('[dusk="create-exercise-btn"]')
                ->waitFor('[dusk="exercise-modal-title"]', 15)
                ->type('input[name="name"]', 'New Exercise '.time())
                ->select('select[name="type"]', 'strength')
                ->press('CRÉER')
                ->waitForText('Exercice créé avec succès', 15)
                ->assertPathIs('/exercises')
                ->assertNoConsoleExceptions();
        } catch (\Exception $e) {
            $browser->screenshot('exercise-failure-'.$sizeMacro);
            throw $e;
        }
    }

    public function test_exercise_management_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performExerciseManagement($browser, 'resizeToIphoneMini');
        });
    }

    public function test_exercise_management_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performExerciseManagement($browser, 'resizeToIphone15');
        });
    }

    public function test_exercise_management_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performExerciseManagement($browser, 'resizeToIphoneMax');
        });
    }
}
