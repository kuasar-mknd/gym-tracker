<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ExerciseManagementTest extends DuskTestCase
{
    use AuthenticatesUser;
    use DatabaseTruncation;

    private function performExerciseManagement(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $browser->{$sizeMacro}();
        $this->loginUser($browser, $user);

        $browser->visit('/exercises')
            ->disableAnimations()
            ->waitFor('#main-content', 30)
            ->waitFor('[dusk="create-exercise-btn"]', 30)
            ->click('[dusk="create-exercise-btn"]')
            ->waitFor('[dusk="exercise-modal-title"]', 15)
            ->type('input[name="name"]', 'New Exercise '.time())
            ->select('select[name="type"]', 'strength')
            ->press('CRÉER')
            ->waitForText('Exercice créé avec succès', 15)
            ->assertPathIs('/exercises');
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
