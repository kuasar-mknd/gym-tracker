<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Hash;
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

            // « Enregistré ✓ » est un message, pas une preuve. Sans cette
            // vérification, un formulaire qui affiche le succès sans rien écrire
            // passait au vert.
            $this->waitForDatabase(
                fn (): bool => $user->fresh()?->name === $newName,
                message: 'le profil affichait « Enregistré » sans que le nom atteigne la base'
            );

            // Add padding to bottom of body to allow scrolling elements above the floating nav
            $browser->script("document.body.style.paddingBottom = '500px';");

            // Verify Password Section
            $browser->script("document.querySelector('input[autocomplete=\"current-password\"]').scrollIntoView({block: 'center'});");
            // Les sélecteurs par autocomplete ne suffisaient pas : chaque GlassInput
            // est un composant à part, donc input[autocomplete="new-password"]:last-of-type
            // matche AUSSI le premier champ. Dusk prenait la première occurrence et
            // laissait la confirmation vide, donc la validation échouait — sans que
            // personne ne le voie, faute d'assertion sur la base.
            $browser->assertSee('Mot de passe')
                ->type('@current-password-input', 'password123')
                ->type('@new-password-input', 'newpassword123')
                ->type('@confirm-password-input', 'newpassword123')
                ->click('[data-testid="update-password-button"]');

            // Aucune attente sur « Enregistré ✓ » ici : UpdateProfileInformationForm
            // affiche EXACTEMENT le même texte, encore visible depuis l'étape
            // précédente. C'est ce faux positif qui masquait l'échec — la seule
            // preuve qui vaille est l'état du mot de passe en base, ci-dessous.

            // Le parcours le plus important à vérifier réellement : on assère que
            // le NOUVEAU mot de passe authentifie, et que l'ancien ne le fait plus.
            // Un test qui se contente du message ne distingue pas un changement
            // effectif d'un formulaire qui ne fait rien.
            $this->waitForDatabase(
                fn (): bool => Hash::check('newpassword123', (string) $user->fresh()?->password),
                message: 'le mot de passe affichait « Enregistré » sans être changé'
            );

            $this->assertFalse(
                Hash::check('password123', (string) $user->fresh()?->password),
                'l\'ancien mot de passe authentifie encore'
            );

            // Verify Delete Account Section
            $browser->script("document.querySelector('[data-testid=\"delete-account-button\"]').scrollIntoView({block: 'center'});");
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
