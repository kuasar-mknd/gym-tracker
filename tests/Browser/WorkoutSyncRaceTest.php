<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class WorkoutSyncRaceTest extends DuskTestCase
{
    use DatabaseTruncation;

    private function performSyncRace(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);
        $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
        $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
        $set = Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 50, 'reps' => 10]);

        $browser->loginAs($user->id)
            ->{$sizeMacro}()
            ->visit("/workouts/{$workout->id}")
            ->waitFor('@weight-input-0-0', 15)
            ->script("document.querySelector('[dusk=\"weight-input-0-0\"]').scrollIntoView();");

        // 1. On focus l'input et on change la valeur (50 -> 99)
        $browser->click('@weight-input-0-0')
            ->type('@weight-input-0-0', '99');

        // 2. RACE CONDITION: On déclenche un refresh Inertia IMMEDIATEMENT
        $browser->script('window.Inertia.reload()');

        // On attend un peu
        $browser->pause(2000);

        // 3. VERIFICATION: La valeur doit TOUJOURS être 99 dans l'input
        $value = $browser->inputValue('@weight-input-0-0');

        $this->assertEquals('99', $value, 'La valeur locale a été écrasée par les props du serveur lors du refresh !');

        // 4. On quitte le champ (blur)
        $browser->keys('@weight-input-0-0', '{tab}');

        // On attend la fin du debounce et de la synchro réelle
        $browser->pause(1000);

        // On vérifie en DB que le 99 a fini par arriver
        $this->assertEquals(99, $set->fresh()->weight);
    }

    public function test_sync_race_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performSyncRace($browser, 'resizeToIphoneMini');
        });
    }

    public function test_sync_race_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performSyncRace($browser, 'resizeToIphone15');
        });
    }

    public function test_sync_race_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performSyncRace($browser, 'resizeToIphoneMax');
        });
    }
}
