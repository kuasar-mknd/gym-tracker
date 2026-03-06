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

    private function performAuthenticatedPages(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'email' => 'page-'.time().random_int(0, 999).'@example.com',
            'email_verified_at' => now(),
        ]);

        try {
            $browser->loginAs($user->id)
                ->{$sizeMacro}();

            $pages = [
                '/dashboard' => 'RETOUR',
                '/workouts' => 'SÉANCES',
                '/calendar' => 'CALENDRIER',
                '/exercises' => 'BIBLIOTHÈQUE',
                '/stats' => 'ÉVOLUTION',
                '/profile' => 'PLUS',
            ];

            foreach ($pages as $url => $text) {
                $browser->visit($url)
                    ->disableAnimations()
                    ->waitFor('#main-content', 30)
                    ->pause(1000)
                    ->assertSee($text);
            }
            $browser->assertNoConsoleExceptions();
        } catch (\Exception $e) {
            $browser->screenshot('page-failure-'.$sizeMacro);
            throw $e;
        }
    }

    public function test_authenticated_pages_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performAuthenticatedPages($browser, 'resizeToIphoneMini');
        });
    }

    public function test_authenticated_pages_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performAuthenticatedPages($browser, 'resizeToIphone15');
        });
    }

    public function test_authenticated_pages_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performAuthenticatedPages($browser, 'resizeToIphoneMax');
        });
    }

    private function performMobileNavigation(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'email' => 'nav-'.time().random_int(0, 999).'@example.com',
            'email_verified_at' => now(),
        ]);

        $this->browse(function (Browser $browser) use ($user, $sizeMacro): void {
            try {
                $browser->loginAs($user->id)
                    ->{$sizeMacro}()
                    ->visit('/dashboard')
                    ->waitFor('#main-content', 30)
                    ->pause(1000);

                $browser->waitFor('[dusk="nav-workouts"]', 15)
                    ->click('[dusk="nav-workouts"]')
                    ->waitForLocation('/workouts', 15)
                    ->pause(500)
                    ->waitFor('[dusk="nav-profile"]', 15)
                    ->click('[dusk="nav-profile"]')
                    ->waitForLocation('/profile', 15)
                    ->pause(500)
                    ->waitFor('[dusk="nav-dashboard"]', 15)
                    ->click('[dusk="nav-dashboard"]')
                    ->waitForLocation('/dashboard', 15)
                    ->assertNoConsoleExceptions();
            } catch (\Exception $e) {
                $browser->screenshot('nav-failure-'.$sizeMacro);
                throw $e;
            }
        });
    }

    public function test_mobile_navigation_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performMobileNavigation($browser, 'resizeToIphoneMini');
        });
    }

    public function test_mobile_navigation_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performMobileNavigation($browser, 'resizeToIphone15');
        });
    }

    public function test_mobile_navigation_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performMobileNavigation($browser, 'resizeToIphoneMax');
        });
    }
}
