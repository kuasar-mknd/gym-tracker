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
            $browser->loginAs(User::findOrFail($user->id))
                ->{$sizeMacro}();

            $pages = [
                '/dashboard' => 'Bon retour',
                '/workouts' => 'Démarrer une séance',
                '/calendar' => 'Calendrier',
                '/exercises' => 'Bibliothèque',
                '/stats' => 'Pas encore de séance',
                '/corps' => 'Corps',
                '/records' => 'Records',
                '/profile' => $user->email,
            ];

            // L'Accueil, Séances, Progrès et le Profil ne montrent pas leur titre : le h1 reste dans le DOM, masqué aux yeux.
            $titresMasques = ['/dashboard' => 'Accueil', '/workouts' => 'Mes séances', '/stats' => 'Progrès', '/profile' => 'Profil'];

            foreach ($pages as $url => $text) {
                $browser->visit($url)
                    ->disableAnimations()
                    ->waitFor('#main-content', 30)
                    ->waitForText($text, 15);

                if (isset($titresMasques[$url])) {
                    $browser->assertScript("document.querySelector('header h1').textContent.trim()", $titresMasques[$url]);
                    // `textContent` ignore le CSS : la boîte du titre doit tenir en un pixel (sr-only), ou ne pas exister.
                    $browser->assertScript(
                        "(() => { const r = document.querySelector('header h1').getBoundingClientRect(); return r.width <= 1 && r.height <= 1; })()",
                        true,
                    );
                }
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
                $browser->loginAs(User::findOrFail($user->id))
                    ->{$sizeMacro}()
                    ->visit('/dashboard')
                    ->waitFor('#main-content', 30);

                $browser->clickWhenSettled('[dusk="nav-workouts"]');
                $browser->waitForLocation('/workouts', 15);
                $browser->clickWhenSettled('[dusk="nav-profile"]');
                $browser->waitForLocation('/profile', 15);
                $browser->clickWhenSettled('[dusk="nav-dashboard"]');
                $browser->waitForLocation('/dashboard', 15)
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
