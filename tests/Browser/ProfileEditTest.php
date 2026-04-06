<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ProfileEditTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_profile_edit_flow_on_mobile_devices(): void
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'profile-'.time().random_int(0, 999).'@example.com',
            'email_verified_at' => now(),
        ]);

        $viewports = [
            'Mini' => [375, 812],
            'Normal' => [390, 844],
            'Max' => [430, 932],
        ];

        $this->browse(function (Browser $browser) use ($user, $viewports): void {
            // Login once, since we just edit the profile multiple times
            $browser->loginAs($user);

            foreach ($viewports as $name => [$width, $height]) {
                try {
                    $newName = 'Updated Name '.$name.' '.time();

                    $browser->resize($width, $height)
                        ->visit('/profile/edit')
                        ->disableAnimations()
                        ->waitFor('#main-content', 30)
                        ->waitFor('input[autocomplete="name"]', 15)
                        ->clear('input[autocomplete="name"]')
                        ->type('input[autocomplete="name"]', $newName)
                        ->keys('input[autocomplete="name"]', ['{escape}']) // Trigger blur for Vue reactivity
                        ->script("document.querySelector('form button[type=\"submit\"]').scrollIntoView({block: 'center'});");

                    $browser->click('form button[type="submit"]')
                        ->waitForText('Enregistré ✓', 15)
                        ->assertSee('Enregistré ✓')
                        ->assertNoConsoleExceptions();

                } catch (\Exception $e) {
                    $browser->screenshot('profile-edit-failure-iphone-'.strtolower($name));
                    throw $e;
                }
            }
        });
    }
}
