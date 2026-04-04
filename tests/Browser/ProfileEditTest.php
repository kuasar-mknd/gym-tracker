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

    private function performProfileEditCheck(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'profile-'.time().random_int(0, 999).'@example.com',
            'password' => bcrypt('password123'),
        ]);

        try {
            $browser->loginAs(User::find($user->id))
                ->{$sizeMacro}()
                ->visit('/profile/edit')
                ->disableAnimations()
                ->waitFor('#main-content', 30);

            $browser->assertSee('MON PROFIL');

            // Verify Profile Info Section
            $browser->assertSee('Informations du profil')
                ->assertInputValue('input[type="text"]', 'Original Name')
                ->assertInputValue('input[type="email"]', $user->email);

            $newName = 'Updated Name '.time();
            $browser->clear('input[type="text"]')
                ->type('input[type="text"]', $newName)
                ->press('Enregistrer')
                ->waitForText('Enregistré ✓', 15)
                ->assertInputValue('input[type="text"]', $newName);

            // Verify Password Section
            // We use JS to scroll to password section to avoid elements intercepting clicks on mobile viewports
            $browser->script("document.querySelector('input[autocomplete=\"current-password\"]').scrollIntoView({block: 'center'});");
            $browser->pause(500);

            $browser->assertSee('Mot de passe')
                ->type('input[autocomplete="current-password"]', 'password123')
                ->type('input[autocomplete="new-password"]', 'newpassword123')
                ->type('input[name="password_confirmation"], input[id$="password_confirmation"], input[autocomplete="new-password"]:last-of-type', 'newpassword123')
                ->press('Mettre à jour')
                ->waitForText('Enregistré ✓', 15);

            // Verify Delete Account Section
            $browser->script("document.querySelector('button.bg-red-500, button[class*=\"danger\"]').scrollIntoView({block: 'center'});");
            $browser->pause(500);

            $browser->assertSee('Supprimer le compte')
                ->press('Supprimer mon compte')
                ->waitForText('Confirmer la suppression', 15)
                ->assertSee('Cette action est irréversible.')
                ->press('Annuler')
                ->waitUntilMissing('Confirmer la suppression', 15);

            $browser->assertNoConsoleExceptions();
        } catch (\Exception $e) {
            $browser->screenshot('profile-edit-failure-'.$sizeMacro);
            throw $e;
        }
    }

    public function test_profile_edit_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performProfileEditCheck($browser, 'resizeToIphoneMini');
        });
    }

    public function test_profile_edit_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performProfileEditCheck($browser, 'resizeToIphone15');
        });
    }

    public function test_profile_edit_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performProfileEditCheck($browser, 'resizeToIphoneMax');
        });
    }
}
