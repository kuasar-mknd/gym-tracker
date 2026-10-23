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
            // Le champ d'adresse porte la nouvelle adresse (`email`) ;
            // l'identifiant du compte, pour les gestionnaires de mots de passe,
            // est à part, caché (#2076).
            $browser->assertSee('INFORMATIONS DU PROFIL')
                ->waitFor('@profile-name-input', 15)
                ->assertInputValue('@profile-name-input', 'Original Name')
                ->assertInputValue('input[type="email"][autocomplete="email"]', $user->email)
                ->assertAttribute('[data-testid="identifiant-du-changement-d-adresse"]', 'autocomplete', 'username')
                ->assertInputValue('[data-testid="identifiant-du-changement-d-adresse"]', $user->email)
                ->assertMissing('[data-testid="identifiant-du-changement-d-adresse"]');

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
            // Le formulaire du mot de passe porte l'adresse du compte pour les
            // gestionnaires de mots de passe (#2076), dans un champ que personne
            // ne voit : présent, à la bonne valeur, et caché.
            $browser->assertSee('MOT DE PASSE')
                ->assertAttribute('[data-testid="identifiant-du-compte"]', 'autocomplete', 'username')
                ->assertInputValue('[data-testid="identifiant-du-compte"]', $user->email)
                ->assertMissing('[data-testid="identifiant-du-compte"]')
                ->type('@current-password-input', 'password123')
                ->type('@new-password-input', 'newpassword123')
                ->type('@confirm-password-input', 'newpassword123')
                ->clickWhenSettled('[data-testid="update-password-button"]');

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
            $browser->assertSee('SUPPRIMER LE COMPTE')
                ->script("document.querySelector('[data-testid=\"delete-account-button\"]').click();");

            $browser->waitForText('CONFIRMER LA SUPPRESSION', 15)
                ->assertSee('Cette action est irréversible.');

            // La fenêtre ouverte retient le défilement de la page. Sans ce
            // relevé, le défilement « rendu » vérifié plus bas passerait aussi
            // pour une fenêtre qui ne l'a jamais retenu. Il se lit sur <html>,
            // comme dans ModalSemanticsTest : c'est lui que lit la fenêtre du
            // navigateur.
            $this->assertSame(
                'hidden',
                $browser->script('return getComputedStyle(document.documentElement).overflowY;')[0],
                'la fenêtre de suppression ouverte laissait la page défiler derrière elle'
            );

            $browser->script("document.querySelector('[data-testid=\"cancel-delete-button\"]').click();");

            // `waitUntilMissing()` prend un sélecteur, pas un texte : il
            // cherchait des éléments <confirmer>, <la> et <suppression>, qui
            // n'existent pas, et passait au premier essai, que la fenêtre se
            // ferme ou non (#2124). Le texte disparu ne suffit pas non plus :
            // le panneau se cache aussitôt, mais le <dialog> ne se ferme que
            // 200 ms plus tard, et un dialogue modal resté ouvert, même vide,
            // rend toute la page inerte.
            $browser->waitUntilMissingText('CONFIRMER LA SUPPRESSION', 15)
                ->waitUntil(
                    '! document.querySelector("dialog[open]")',
                    15,
                    '« Annuler » laissait le dialogue de suppression ouvert après %s secondes'
                );

            $this->assertNotSame(
                'hidden',
                $browser->script('return getComputedStyle(document.documentElement).overflowY;')[0],
                '« Annuler » fermait la fenêtre de suppression sans rendre le défilement de la page'
            );

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
