<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class LoginMobileE2ETest extends DuskTestCase
{
    use DatabaseTruncation;

    private function performLoginFlow(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'email' => 'login-test-'.time().random_int(0, 9999).'@example.com',
            'password' => bcrypt('password123'),
        ]);

        try {
            $browser->{$sizeMacro}()
                ->visit('/login')
                ->disableAnimations()
                ->waitFor('[data-testid="email-input"]', 15)
                ->assertVisible('[data-testid="email-input"]')
                ->assertVisible('[data-testid="password-input"]')
                ->assertVisible('[data-testid="login-button"]')
                // Check that elements are accessible and don't cause side-scroll
                // Type in email
                ->type('[data-testid="email-input"]', $user->email)
                ->pause(500)
                // Type in password
                ->type('[data-testid="password-input"]', 'password123')
                ->pause(500)
                // Submit form
                ->click('[data-testid="login-button"]')
                // Wait for redirect to dashboard
                ->waitFor('#dashboard-header', 30)
                ->assertSee('BON RETOUR')
                ->assertNoConsoleExceptions();
        } catch (\Exception $e) {
            $browser->screenshot('login-failure-'.$sizeMacro);
            throw $e;
        }
    }

    public function test_login_flow_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performLoginFlow($browser, 'resizeToIphoneMini');
        });
    }

    public function test_login_flow_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performLoginFlow($browser, 'resizeToIphone15');
        });
    }

    public function test_login_flow_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performLoginFlow($browser, 'resizeToIphoneMax');
        });
    }
}
