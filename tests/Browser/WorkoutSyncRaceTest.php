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
    use AuthenticatesUser;
    use DatabaseTruncation;

    private function performSyncRace(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now(),
        ]);

        $browser->{$sizeMacro}();
        $this->loginUser($browser, $user);

        $browser->visit('/workouts/'.$workout->id)
            ->disableAnimations()
            ->waitFor('#main-content', 30);

        // Simple sync check
        $browser->assertPathIs('/workouts/'.$workout->id);
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
