<?php

namespace Tests\Browser;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class DebugDashboardTest extends DuskTestCase
{
    use DatabaseMigrations;

    /**
     * A basic browser test example.
     */
    public function test_dashboard_source(): void
    {
        $user = User::factory()->create();
        Workout::factory()->count(3)->create(['user_id' => $user->id]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/dashboard')
                ->pause(2000)
                ->screenshot('debug_dashboard_loading')
                ->storeSource('debug_dashboard_source');

            error_log('DASHBOARD SOURCE SAVED TO tests/Browser/source/debug_dashboard_source.txt');
        });
    }
}
