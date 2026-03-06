<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class WorkoutSyncRaceTest extends DuskTestCase
{
    use DatabaseTruncation;

    private function performSyncRace(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'email' => 'sync-user-'.time().random_int(0, 9999).'@example.com',
            'email_verified_at' => now(),
        ]);

        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now(),
        ]);

        try {
            $browser->loginAs($user->id)
                ->{$sizeMacro}()
                ->visit('/workouts/'.$workout->id)
                ->disableAnimations()
                ->waitFor('#main-content', 30);

            $browser->pause(1000)
                ->assertPathIs('/workouts/'.$workout->id)
                ->assertNoConsoleExceptions();
        } catch (\Exception $e) {
            $browser->screenshot('sync-failure-'.$sizeMacro);
            throw $e;
        }
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
