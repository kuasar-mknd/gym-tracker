<?php

declare(strict_types=1);

use App\Actions\Admin\ReinitialiseLaDoubleAuthentificationAction;
use App\Filament\Connexion\ConnexionDuPanneau;
use App\Models\Admin;
use App\Support\AssuranceDuSecondFacteur;
use App\Support\AuthentificationParApplication;
use App\Support\DefiDeDoubleAuthentification;
use App\Support\MagasinDurable;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;

/*
 * Le second facteur du panneau, durci (audit, notes 5.0, 5.1, 5.2, 5.4) : la
 * régénération des codes exige le code de l'application, le marqueur anti-rejeu
 * vit sur le magasin durable, la session porte la preuve du second facteur et
 * la perd à la réinitialisation, et le défi a un plafond journalier.
 */

function administrateurDuSecondFacteur(): Admin
{
    $administrateur = Admin::factory()->avecCodesDeRecuperation(['abcdefghij-0123456789'])->create();
    $administrateur->assignRole(Role::findOrCreate(Admin::roleDuSuperAdministrateur(), 'admin'));

    return $administrateur;
}

describe('la régénération des codes de récupération (5.0)', function (): void {
    it('refuse le mot de passe seul, sans le code de l’application', function (): void {
        $administrateur = administrateurDuSecondFacteur();
        $anciens = $administrateur->getAppAuthenticationRecoveryCodes();
        Filament::setCurrentPanel('admin');
        $this->actingAs($administrateur, 'admin');

        Livewire::test(EditProfile::class)
            ->callAction(TestAction::make('regenerateAppAuthenticationRecoveryCodes')->schemaComponent('app', schema: 'content'), ['password' => 'password'])
            ->assertHasActionErrors(['code' => 'required']);

        expect($administrateur->refresh()->getAppAuthenticationRecoveryCodes())->toBe($anciens);
    });

    it('régénère avec le mot de passe et le code', function (): void {
        $administrateur = administrateurDuSecondFacteur();
        $anciens = $administrateur->getAppAuthenticationRecoveryCodes();
        Filament::setCurrentPanel('admin');
        $this->actingAs($administrateur, 'admin');

        Livewire::test(EditProfile::class)
            ->callAction(TestAction::make('regenerateAppAuthenticationRecoveryCodes')->schemaComponent('app', schema: 'content'), [
                'password' => 'password',
                'code' => AppAuthentication::make()->getCurrentCode($administrateur),
            ])
            ->assertHasNoActionErrors();

        expect($administrateur->refresh()->getAppAuthenticationRecoveryCodes())->not->toBe($anciens);
    });
});

describe('le marqueur anti-rejeu (5.1)', function (): void {
    it('vit sur le magasin durable, pas sur le magasin par défaut', function (): void {
        config([
            'cache.default' => 'array',
            'cache.limiter' => 'tampon',
            'cache.stores.tampon' => ['driver' => 'array'],
        ]);
        Cache::forgetDriver('array');
        Cache::forgetDriver('tampon');
        $administrateur = administrateurDuSecondFacteur();
        $secret = (string) $administrateur->getAppAuthenticationSecret();
        $this->actingAs($administrateur, 'admin');
        $cle = 'filament:auth:app_authentication:code:'.hash('sha256', $secret);

        $accepte = AuthentificationParApplication::make()->verifyCode(
            new Google2FA()->getCurrentOtp($secret),
            shouldPreventCodeReuse: true,
        );

        expect($accepte)->toBeTrue()
            ->and(MagasinDurable::depot()->has($cle))->toBeTrue()
            ->and(Cache::store('array')->has($cle))->toBeFalse();
    });
});

describe('l’assurance du second facteur portée par la session (5.2)', function (): void {
    it('renvoie à la connexion une session qui n’a jamais passé le défi', function (): void {
        $administrateur = administrateurDuSecondFacteur();

        $this->actingAs($administrateur, 'admin')
            ->withSession([AssuranceDuSecondFacteur::CLE => 'jamais-passe'])
            ->get('/backoffice')
            ->assertRedirect(route('filament.admin.auth.login'));

        expect(auth('admin')->check())->toBeFalse();
    });

    it('pose la preuve à la connexion par le code de l’application', function (): void {
        $administrateur = administrateurDuSecondFacteur();

        Livewire::test(ConnexionDuPanneau::class)
            ->fillForm(['email' => $administrateur->email, 'password' => 'password'])
            ->call('authenticate')
            ->set('data.multiFactor.app.code', AppAuthentication::make()->getCurrentCode($administrateur))
            ->call('authenticate');

        expect(auth('admin')->id())->toBe($administrateur->id)
            ->and(session(AssuranceDuSecondFacteur::CLE))->toBe(AssuranceDuSecondFacteur::empreinte($administrateur));
    });

    it('ferme les sessions ouvertes à la réinitialisation, même quand un nouveau secret est enrôlé', function (): void {
        $administrateur = administrateurDuSecondFacteur();
        $this->actingAs($administrateur, 'admin')->get('/backoffice')->assertOk();
        $empreinteDeLaSession = AssuranceDuSecondFacteur::empreinte($administrateur);

        new ReinitialiseLaDoubleAuthentificationAction()->execute($administrateur);
        $administrateur->forceFill(['app_authentication_secret' => new Google2FA()->generateSecretKey()])->save();

        $this->actingAs($administrateur->refresh(), 'admin')
            ->withSession([AssuranceDuSecondFacteur::CLE => $empreinteDeLaSession])
            ->get('/backoffice')
            ->assertRedirect(route('filament.admin.auth.login'));
    });
});

describe('le plafond journalier du défi (5.4)', function (): void {
    it('compte chaque essai du défi sur la journée', function (): void {
        $administrateur = administrateurDuSecondFacteur();
        $cleDuJour = 'filament:auth:multi_factor_challenge:rate_limit_jour:'.Filament::getUserScopedAuthIdentifier($administrateur);

        Livewire::test(ConnexionDuPanneau::class)
            ->fillForm(['email' => $administrateur->email, 'password' => 'password'])
            ->call('authenticate')
            ->set('data.multiFactor.app.code', '000000')
            ->call('authenticate');

        expect(RateLimiter::attempts($cleDuJour))->toBe(1);
    });

    it('verrouille le défi une fois le plafond atteint, même pour le bon code', function (): void {
        $administrateur = administrateurDuSecondFacteur();
        $cleDuJour = 'filament:auth:multi_factor_challenge:rate_limit_jour:'.Filament::getUserScopedAuthIdentifier($administrateur);
        foreach (range(1, DefiDeDoubleAuthentification::ESSAIS_PAR_JOUR) as $essai) {
            RateLimiter::hit($cleDuJour, 86_400);
        }

        Livewire::test(ConnexionDuPanneau::class)
            ->fillForm(['email' => $administrateur->email, 'password' => 'password'])
            ->call('authenticate')
            ->set('data.multiFactor.app.code', AppAuthentication::make()->getCurrentCode($administrateur))
            ->call('authenticate');

        expect(auth('admin')->check())->toBeFalse();
    });
});
