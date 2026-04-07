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

    private function performProfileEdit(Browser $browser, int $width, int $height, string $deviceFormat): void
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'profile-edit-'.time().random_int(0, 999).'@example.com',
            'email_verified_at' => now(),
        ]);

        $newName = 'Updated Name '.time();

        try {
            $browser->loginAs($user)
                ->resize($width, $height)
                ->visit('/profile/edit')
                ->disableAnimations()
                ->waitFor('#main-content', 30);

            $browser->waitFor('@profile-name-input', 15)
                ->clear('@profile-name-input')
                ->type('@profile-name-input', $newName)
                ->script("document.querySelector('[dusk=\"save-profile-btn\"]').scrollIntoView({block: 'center'});");

            $browser->click('@save-profile-btn')
                ->waitForText('Enregistré ✓', 15)
                ->assertNoConsoleExceptions();
        } catch (\Exception $e) {
            $browser->screenshot('failure-iphone-'.$deviceFormat);
            throw $e;
        }
    }

    public function test_profile_edit_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performProfileEdit($browser, 375, 812, 'mini');
        });
    }

    public function test_profile_edit_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            // Using 390x844 as requested for Normal
            $this->performProfileEdit($browser, 390, 844, 'normal');
        });
    }

    public function test_profile_edit_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performProfileEdit($browser, 430, 932, 'max');
        });
    }
}
