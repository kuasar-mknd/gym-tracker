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

    public function test_login_flow_on_mobile_viewports(): void
    {
        $viewports = [
            'Mini' => [375, 812],
            'Normal' => [390, 844],
            'Max' => [430, 932],
        ];

        foreach ($viewports as $format => [$width, $height]) {
            $this->browse(function (Browser $browser) use ($format, $width, $height): void {
                $user = User::factory()->create([
                    'email' => 'login-'.time().random_int(0, 999).'@example.com',
                    'password' => bcrypt('password123'),
                ]);

                try {
                    $browser->resize($width, $height)
                        ->visit('/login')
                        ->waitFor('@email-input', 30)
                        ->type('@email-input', $user->email)
                        ->type('@password-input', 'password123')
                        ->script("document.querySelector('[data-testid=\"login-button\"]').scrollIntoView({block: 'center'});");

                    $browser->pause(500)
                        ->click('@login-button')
                        ->waitForLocation('/dashboard', 30)
                        ->assertPathIs('/dashboard');
                } catch (\Exception $e) {
                    $browser->screenshot('failure-iphone-'.strtolower($format));
                    throw $e;
                }
            });
        }
    }
}
