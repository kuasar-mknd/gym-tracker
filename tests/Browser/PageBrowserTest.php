<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class PageBrowserTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_unauthenticated_users_are_redirected_to_login(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->visit('/dashboard')
                ->assertPathIs('/login');
        });
    }

    public function test_users_can_see_login_page(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->visit('/login')
                ->waitForText('Se connecter', 10)
                ->assertSee('Se connecter');
        });
    }

    public function test_users_can_register(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->visit('/register')
                ->type('input[name="name"]', 'John Doe')
                ->type('input[name="email"]', 'john'.time().'@example.com')
                ->type('input[name="password"]', 'password')
                ->type('input[name="password_confirmation"]', 'password')
                ->press('Créer mon compte')
                ->waitForLocation('/verify-email', 30)
                ->assertPathIs('/verify-email');
        });
    }

    public function test_authenticated_users_can_see_dashboard(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/dashboard')
                ->waitFor('main', 15)
                ->assertPathIs('/dashboard');
        });
    }

    public function test_workouts_page_renders_correctly(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/workouts')
                ->waitFor('main', 15)
                ->assertPathIs('/workouts')
                ->assertNoConsoleExceptions();
        });
    }

    public function test_exercises_page_works(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/exercises')
                ->waitFor('main', 15)
                ->assertPathIs('/exercises')
                ->assertNoConsoleExceptions();
        });
    }

    public function test_stats_page_works(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/stats')
                ->waitFor('main', 15)
                ->assertPathIs('/stats')
                ->assertNoConsoleExceptions();
        });
    }

    public function test_calendar_page_works(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/calendar')
                ->waitFor('main', 15)
                ->assertPathIs('/calendar')
                ->assertNoConsoleExceptions();
        });
    }

    public function test_goals_page_works(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/goals')
                ->waitFor('main', 15)
                ->assertPathIs('/goals')
                ->assertNoConsoleExceptions();
        });
    }

    public function test_templates_page_works(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/templates')
                ->waitFor('main', 15)
                ->assertPathIs('/templates')
                ->assertNoConsoleExceptions();
        });
    }

    public function test_body_measurements_page_works(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/body-measurements')
                ->waitFor('main', 15)
                ->assertPathIs('/body-measurements')
                ->assertNoConsoleExceptions();
        });
    }

    public function test_daily_journals_page_works(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/daily-journals')
                ->waitFor('main', 15)
                ->assertPathIs('/daily-journals')
                ->assertNoConsoleExceptions();
        });
    }

    public function test_notifications_page_works(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/notifications')
                ->waitFor('main', 15)
                ->assertPathIs('/notifications')
                ->assertNoConsoleExceptions();
        });
    }

    public function test_achievements_page_works(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/achievements')
                ->waitFor('main', 15)
                ->assertPathIs('/achievements')
                ->assertNoConsoleExceptions();
        });
    }

    public function test_profile_page_renders_correctly(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/profile')
                ->waitFor('main', 15)
                ->assertPathIs('/profile')
                ->assertNoConsoleExceptions();
        });
    }

    public function test_tools_page_renders_correctly(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/tools')
                ->waitFor('main', 15)
                ->assertPathIs('/tools')
                ->assertNoConsoleExceptions();
        });
    }

    public function test_plates_calculator_page_renders_correctly(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/plates')
                ->waitFor('main', 15)
                ->assertPathIs('/plates')
                ->assertNoConsoleExceptions();
        });
    }

    public function test_navigation_works_correctly_on_mobile(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->resize(375, 812) // iPhone X dimensions
                ->visit('/dashboard')
                ->waitFor('main', 15)
                ->assertPathIs('/dashboard')
                // Check glass-nav is visible on mobile
                ->assertPresent('.glass-nav')
                ->assertNoConsoleExceptions();
        });
    }
}
