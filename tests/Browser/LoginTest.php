<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class LoginTest extends DuskTestCase
{
    use DatabaseTruncation;

    private function performLogin(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'email' => 'login-'.time().random_int(0, 999).'@example.com',
            'password' => bcrypt('password123'),
        ]);

        try {
            $browser->{$sizeMacro}()
                ->visit('/login')
                ->disableAnimations()
                ->waitFor('[data-testid="email-input"]', 30)
                ->type('[data-testid="email-input"]', $user->email)
                ->type('[data-testid="password-input"]', 'password123')
                ->script("document.querySelector('[data-testid=\"login-button\"]').scrollIntoView({block: 'center'});");

            $browser->pause(500)
                ->click('[data-testid="login-button"]')
                ->waitForLocation('/dashboard', 30)
                ->assertPathIs('/dashboard')
                ->assertNoConsoleExceptions();
        } catch (\Exception $e) {
            $browser->screenshot('login-failure-'.$sizeMacro);
            throw $e;
        }
    }

    public function test_login_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performLogin($browser, 'resizeToIphoneMini');
        });
    }

    public function test_login_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performLogin($browser, 'resizeToIphone15');
        });
    }

    public function test_login_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performLogin($browser, 'resizeToIphoneMax');
        });
    }
}
