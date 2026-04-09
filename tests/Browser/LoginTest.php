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

    public function test_login_flow_on_iphone_devices(): void
    {
        $password = 'password';
        $user = User::factory()->create([
            'email' => 'login-'.time().random_int(0, 999).'@example.com',
            'password' => bcrypt($password),
        ]);

        $viewports = [
            'Mini' => [375, 812],
            'Normal' => [390, 844],
            'Max' => [430, 932],
        ];

        $this->browse(function (Browser $browser) use ($user, $password, $viewports): void {
            foreach ($viewports as $name => [$width, $height]) {
                try {
                    $browser->resize($width, $height)
                        ->visit('/login')
                        ->disableAnimations()
                        ->waitFor('input[type="email"]', 30)
                        ->type('input[type="email"]', $user->email)
                        ->type('input[type="password"]', $password)
                        ->script("document.querySelector('[data-testid=\"login-button\"]').scrollIntoView({block: 'center'});");

                    $browser->click('[data-testid="login-button"]')
                        ->waitForLocation('/dashboard', 15)
                        ->waitFor('#main-content', 15)
                        ->assertSee('RETOUR')
                        ->assertNoConsoleExceptions();

                    // Log out to reset state for the next iteration
                    $browser->visit('/profile')
                        ->waitFor('[data-testid="logout-button"]', 15)
                        ->script("document.querySelector('[data-testid=\"logout-button\"]').scrollIntoView({block: 'center'});");

                    $browser->click('[data-testid="logout-button"]')
                        ->waitForLocation('/login', 15);

                } catch (\Exception $e) {
                    $browser->screenshot('login-failure-iphone-'.strtolower($name));
                    throw $e;
                }
            }
        });
    }
}
