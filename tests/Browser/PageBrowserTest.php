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

    public function test_guest_pages(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->visit('/')
                ->waitFor('#main-content', 15)
                ->assertSee('GymTracker')
                ->visit('/login')
                ->waitFor('#main-content', 15)
                ->assertSee('Se connecter')
                ->visit('/register')
                ->waitFor('#main-content', 15)
                ->assertSee('Créer un compte');
        });
    }

    private function performAuthenticatedPages(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $browser->loginAs($user->id)
            ->{$sizeMacro}();

        $pages = [
            '/dashboard' => 'BON RETOUR',
            '/workouts' => 'Séances',
            '/calendar' => 'Calendrier',
            '/exercises' => 'Exercices',
            '/stats' => 'Statistiques',
            '/profile' => 'Profil',
        ];

        foreach ($pages as $url => $text) {
            $browser->visit($url)
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->assertPathIs($url);
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

    public function test_mobile_navigation_on_iphone_mini(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user->id)
                ->resizeToIphoneMini()
                ->visit('/dashboard')
                ->waitFor('#main-content', 30)
                ->click('[dusk="nav-workouts"]')
                ->waitForLocation('/workouts', 15)
                ->click('[dusk="nav-profile"]')
                ->waitForLocation('/profile', 15)
                ->click('[dusk="nav-dashboard"]')
                ->waitForLocation('/dashboard', 15);
        });
    }
}
