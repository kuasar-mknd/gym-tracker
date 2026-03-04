<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class PageBrowserTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_unauthenticated_users_are_redirected_to_login(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->visit('/dashboard')
                ->assertPathIs('/login');
        });
    }

    public function test_guest_pages_and_registration_flow(): void
    {
        $this->browse(function (Browser $browser): void {
            // 1. Login page
            $browser->logout()
                ->visit('/login')
                ->waitFor('button[type="submit"]', 30)
                ->assertVisible('button[type="submit"]');

            // 2. Registration flow
            $browser->visit('/register')
                ->waitFor('input[name="name"]', 30)
                ->type('input[name="name"]', 'John Doe')
                ->type('input[name="email"]', 'john_reg_'.time().'@example.com')
                ->type('input[name="password"]', 'password')
                ->type('input[name="password_confirmation"]', 'password')
                ->click('[data-testid="register-button"]')
                ->waitForLocation('/verify-email', 30)
                ->assertPathIs('/verify-email');
        });
    }

    private function performSmokeTest(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create();

        $browser->loginAs($user)
            ->{$sizeMacro}()
            ->visit('/dashboard')
            ->waitFor('main', 30)
            ->assertPathIs('/dashboard');

        $pages = [
            '/workouts',
            '/exercises',
            '/stats',
            '/calendar',
            '/goals',
            '/templates',
            '/body-measurements',
            '/daily-journals',
            '/notifications',
            '/achievements',
            '/profile',
            '/tools',
            '/plates',
        ];

        foreach ($pages as $path) {
            $browser->visit($path)
                ->waitFor('main', 15)
                ->assertPathIs($path)
                ->assertNoConsoleExceptions();
        }
    }

    public function test_authenticated_pages_smoke_test_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performSmokeTest($browser, 'resizeToIphoneMini');
        });
    }

    public function test_authenticated_pages_smoke_test_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performSmokeTest($browser, 'resizeToIphone15');
        });
    }

    public function test_authenticated_pages_smoke_test_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performSmokeTest($browser, 'resizeToIphoneMax');
        });
    }

    public function test_mobile_navigation_is_visible_on_iphone_mini(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->resizeToIphoneMini()
                ->visit('/dashboard')
                ->waitFor('main', 30)
                ->assertPresent('.glass-nav')
                ->assertNoConsoleExceptions();
        });
    }
}
