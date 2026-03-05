<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class RestTimerTest extends DuskTestCase
{
    use DatabaseTruncation;

    private function performTimerLifecycle(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now(),
        ]);

        $browser->loginAs($user->id)
            ->{$sizeMacro}()
            ->visit('/workouts/'.$workout->id)
            ->disableAnimations()
            ->waitFor('#main-content', 30);
    }

    private function performAddTime(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $browser->loginAs($user->id)
            ->{$sizeMacro}()
            ->visit('/dashboard')
            ->waitFor('#main-content', 30);
    }

    public function test_timer_lifecycle_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performTimerLifecycle($browser, 'resizeToIphoneMini');
        });
    }

    public function test_timer_lifecycle_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performTimerLifecycle($browser, 'resizeToIphone15');
        });
    }

    public function test_timer_lifecycle_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performTimerLifecycle($browser, 'resizeToIphoneMax');
        });
    }

    public function test_timer_add_time_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performAddTime($browser, 'resizeToIphoneMini');
        });
    }
}
