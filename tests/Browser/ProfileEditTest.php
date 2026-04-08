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

    private function performProfileEditCheck(Browser $browser, string $sizeMacro, string $deviceFormat): void
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'profile-edit-'.time().random_int(0, 999).'@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password123'),
        ]);

        try {
            $browser->loginAs($user)
                ->{$sizeMacro}()
                ->visit('/profile/edit')
                ->disableAnimations()
                ->waitFor('#main-content', 30);

            $browser->assertSee('MON PROFIL');

            // Verify Profile Info Section
            $browser->assertSee('Informations du profil')
                ->waitFor('@profile-name-input', 15)
                ->assertInputValue('@profile-name-input', 'Original Name')
                ->assertInputValue('input[autocomplete="username"]', $user->email);

            $newName = 'Updated Name '.time();
            $browser->clear('@profile-name-input')
                ->type('@profile-name-input', $newName)
                ->script("document.querySelector('[dusk=\"save-profile-btn\"]').scrollIntoView({block: 'center'});");

            $browser->click('@save-profile-btn')
                ->waitForText('Enregistré ✓', 15)
                ->assertInputValue('@profile-name-input', $newName);

            // Add padding to bottom of body to allow scrolling elements above the floating nav
            $browser->script("document.body.style.paddingBottom = '500px';");

            // Verify Password Section
            $browser->script("document.querySelector('input[autocomplete=\"current-password\"]').scrollIntoView({block: 'center'});");
            $browser->pause(500);

            $browser->assertSee('Mot de passe')
                ->type('input[autocomplete="current-password"]', 'password123')
                ->type('input[autocomplete="new-password"]', 'newpassword123')
                ->type('input[autocomplete="new-password"]:last-of-type', 'newpassword123')
                ->click('[data-testid="update-password-button"]')
                ->waitForText('Enregistré ✓', 15);

            // Verify Delete Account Section
            $browser->script("document.querySelector('[data-testid=\"delete-account-button\"]').scrollIntoView({block: 'center'});");
            $browser->pause(500);

            $browser->assertSee('Supprimer le compte')
                ->script("document.querySelector('[data-testid=\"delete-account-button\"]').click();");

            $browser->waitForText('Confirmer la suppression', 15)
                ->assertSee('Cette action est irréversible.')
                ->script("document.querySelector('[data-testid=\"cancel-delete-button\"]').click();");

            $browser->waitUntilMissing('Confirmer la suppression', 15);

            $browser->assertNoConsoleExceptions();
        } catch (\Exception $e) {
            $browser->screenshot('failure-iphone-'.$deviceFormat);
            throw $e;
        }
    }

    public function test_profile_edit_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performProfileEditCheck($browser, 'resizeToIphoneMini', 'mini');
        });
    }

    public function test_profile_edit_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performProfileEditCheck($browser, 'resizeToIphone15', 'normal');
        });
    }

    public function test_profile_edit_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performProfileEditCheck($browser, 'resizeToIphoneMax', 'max');
        });
    }
}
