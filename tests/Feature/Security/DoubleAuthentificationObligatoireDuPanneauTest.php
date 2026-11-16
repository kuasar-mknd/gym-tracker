<?php

declare(strict_types=1);

use App\Models\Admin;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Pages\SetUpRequiredMultiFactorAuthentication;
use Filament\Auth\Pages\Login as ConnexionDuPanneau;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\FilamentAdminPanel;

use function Livewire\trigger;

/*
 * La double authentification est obligatoire au panneau (#2353) : un
 * administrateur qui n'a pas enrôlé son application est conduit à l'enrôlement
 * avant toute page, ceux qui l'ont passent, un code de récupération remplace
 * le code de l'application une fois, et la console efface la double
 * authentification d'un administrateur qui a tout perdu.
 */

/**
 * Un super administrateur, avec ou sans double authentification.
 */
function superAdministrateurDuPanneau(bool $avecLaDoubleAuthentification = true): Admin
{
    Role::findOrCreate(Admin::roleDuSuperAdministrateur(), 'admin');
    $administrateur = $avecLaDoubleAuthentification
        ? Admin::factory()->create()
        : Admin::factory()->sansDoubleAuthentification()->create();
    $administrateur->assignRole(Admin::roleDuSuperAdministrateur());

    return $administrateur;
}

function adresseDeLEnrolement(): string
{
    Filament::setCurrentPanel('admin');

    return (string) Filament::getSetUpRequiredMultiFactorAuthenticationUrl();
}

it('exige la double authentification de chaque page du panneau', function (): void {
    expect(FilamentAdminPanel::panel()->isMultiFactorAuthenticationRequired())->toBeTrue();
});

it('conduit un administrateur sans double authentification à l’enrôlement', function (string $page): void {
    $administrateur = superAdministrateurDuPanneau(avecLaDoubleAuthentification: false);

    $this->actingAs($administrateur, 'admin')
        ->get($page)
        ->assertRedirect(adresseDeLEnrolement());
})->with([
    'le tableau de bord' => '/backoffice',
    'une ressource' => '/backoffice/users',
    'le profil' => '/backoffice/profile',
]);

it('laisse un administrateur qui a enrôlé son application entrer', function (): void {
    $administrateur = superAdministrateurDuPanneau();

    $this->actingAs($administrateur, 'admin')->get('/backoffice')->assertOk();
});

it('ouvre la page d’enrôlement à l’administrateur qui n’y est pas encore passé, sans boucle', function (): void {
    $administrateur = superAdministrateurDuPanneau(avecLaDoubleAuthentification: false);

    $this->actingAs($administrateur, 'admin')
        ->get(adresseDeLEnrolement())
        ->assertOk()
        ->assertSee('setUpAppAuthentication', escape: false);
});

it('enrôle l’application et ses codes de récupération depuis la page d’enrôlement, puis ouvre le panneau', function (): void {
    $administrateur = superAdministrateurDuPanneau(avecLaDoubleAuthentification: false);
    Filament::setCurrentPanel('admin');
    $this->actingAs($administrateur, 'admin');

    $page = Livewire::test(SetUpRequiredMultiFactorAuthentication::class)
        ->mountAction(TestAction::make('setUpAppAuthentication')->schemaComponent('app'));

    /** @var array<int, array{arguments: array{encrypted: string}}> $actionsMontees */
    $actionsMontees = $page->get('mountedActions');
    /** @var array{secret: string, recoveryCodes: list<string>} $enrolement */
    $enrolement = decrypt($actionsMontees[0]['arguments']['encrypted']);

    $page->setActionData(['code' => new Google2FA()->getCurrentOtp($enrolement['secret']), 'password' => 'password'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $administrateur->refresh();
    expect($administrateur->aLaDoubleAuthentification())->toBeTrue()
        ->and($administrateur->getAppAuthenticationSecret())->toBe($enrolement['secret'])
        ->and($administrateur->getAppAuthenticationRecoveryCodes())->toHaveCount(count($enrolement['recoveryCodes']))
        ->and(Hash::check($enrolement['recoveryCodes'][0], $administrateur->getAppAuthenticationRecoveryCodes()[0] ?? ''))->toBeTrue();

    $this->get('/backoffice')->assertOk();
});

it('refuse aussi les mises à jour Livewire d’un composant du panneau ouvert avant la perte de la double authentification', function (): void {
    $administrateur = superAdministrateurDuPanneau();
    /** @var string $page */
    $page = $this->actingAs($administrateur, 'admin')->get('/backoffice')->assertOk()->getContent();
    expect(preg_match('/wire:snapshot="([^"]+)"/', $page, $trouvaille))->toBe(1);
    $instantane = html_entity_decode($trouvaille[1] ?? '', ENT_QUOTES);

    $envoyer = function () use ($instantane) {
        trigger('flush-state');

        return $this->withSession(['_token' => 'jeton'])->postJson(
            route('default-livewire.update', absolute: false),
            ['components' => [['snapshot' => $instantane, 'updates' => [], 'calls' => []]]],
            ['X-Livewire' => 'true', 'X-CSRF-TOKEN' => 'jeton'],
        );
    };

    $envoyer()->assertOk();

    $administrateur->forceFill(['app_authentication_secret' => null])->save();

    $envoyer()->assertRedirect(adresseDeLEnrolement());
});

it('laisse passer les mises à jour Livewire de la page d’enrôlement elle-même', function (): void {
    $administrateur = superAdministrateurDuPanneau(avecLaDoubleAuthentification: false);
    /** @var string $page */
    $page = $this->actingAs($administrateur, 'admin')->get(adresseDeLEnrolement())->assertOk()->getContent();
    expect(preg_match('/wire:snapshot="([^"]+)"/', $page, $trouvaille))->toBe(1);

    trigger('flush-state');

    $this->withSession(['_token' => 'jeton'])->postJson(
        route('default-livewire.update', absolute: false),
        ['components' => [['snapshot' => html_entity_decode($trouvaille[1] ?? '', ENT_QUOTES), 'updates' => [], 'calls' => []]]],
        ['X-Livewire' => 'true', 'X-CSRF-TOKEN' => 'jeton'],
    )->assertOk();
});

it('renvoie de la page d’enrôlement celui qui l’a déjà fait', function (): void {
    $administrateur = superAdministrateurDuPanneau();
    Filament::setCurrentPanel('admin');
    $this->actingAs($administrateur, 'admin');

    Livewire::test(SetUpRequiredMultiFactorAuthentication::class)->assertRedirect();
});

it('garde la liste blanche d’adresses devant l’enrôlement', function (): void {
    config(['app.admin_allowed_ips' => ['203.0.113.5']]);
    $this->app->detectEnvironment(fn (): string => 'production');
    $administrateur = superAdministrateurDuPanneau(avecLaDoubleAuthentification: false);

    $this->actingAs($administrateur, 'admin')
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->get('/backoffice')
        ->assertNotFound();

    $this->actingAs($administrateur, 'admin')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->get('/backoffice')
        ->assertRedirect(adresseDeLEnrolement());
});

it('ferme Pulse, le lecteur de journaux et Horizon à qui n’a pas enrôlé son application', function (string $porte): void {
    $sans = superAdministrateurDuPanneau(avecLaDoubleAuthentification: false);
    $avec = superAdministrateurDuPanneau();

    expect(Gate::forUser($sans)->allows($porte))->toBeFalse()
        ->and(Gate::forUser($avec)->allows($porte))->toBeTrue();
})->with(['viewPulse', 'view-outils', 'view-logs', 'viewLogViewer']);

it('ferme aussi ces portes à qui les tient d’une permission Shield, sans double authentification', function (string $porte): void {
    $role = Role::findOrCreate('exploitation', 'admin');
    foreach (['viewPulse', 'view-outils', 'view-logs'] as $capacite) {
        $role->givePermissionTo(Permission::findOrCreate($capacite, 'admin'));
    }

    $sans = Admin::factory()->sansDoubleAuthentification()->create();
    $sans->assignRole($role);
    $avec = Admin::factory()->create();
    $avec->assignRole($role);

    expect(Gate::forUser($sans->fresh())->allows($porte))->toBeFalse()
        ->and(Gate::forUser($avec->fresh())->allows($porte))->toBeTrue();
})->with(['viewPulse', 'view-outils', 'view-logs', 'viewLogViewer']);

describe('à la connexion', function (): void {
    it('demande le code de l’application après le mot de passe', function (): void {
        $administrateur = superAdministrateurDuPanneau();
        $application = AppAuthentication::make();

        $connexion = Livewire::test(ConnexionDuPanneau::class)
            ->fillForm(['email' => $administrateur->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        expect(auth('admin')->check())->toBeFalse();

        $connexion->set('data.multiFactor.app.code', $application->getCurrentCode($administrateur))->call('authenticate');

        expect(auth('admin')->id())->toBe($administrateur->id);
    });

    it('accepte un code de récupération, une seule fois', function (): void {
        $administrateur = Admin::factory()
            ->avecCodesDeRecuperation(['abcdefghij-0123456789', 'klmnopqrst-9876543210'])
            ->create();
        $administrateur->assignRole(Role::findOrCreate(Admin::roleDuSuperAdministrateur(), 'admin'));

        Livewire::test(ConnexionDuPanneau::class)
            ->fillForm(['email' => $administrateur->email, 'password' => 'password'])
            ->call('authenticate')
            ->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', 'abcdefghij-0123456789')
            ->call('authenticate')
            ->assertHasNoErrors();

        $restants = $administrateur->refresh()->getAppAuthenticationRecoveryCodes() ?? [];

        expect(auth('admin')->id())->toBe($administrateur->id)
            ->and($restants)->toHaveCount(1)
            ->and(Hash::check('abcdefghij-0123456789', $restants[0] ?? ''))->toBeFalse()
            ->and(Hash::check('klmnopqrst-9876543210', $restants[0] ?? ''))->toBeTrue();

        auth('admin')->logout();

        Livewire::test(ConnexionDuPanneau::class)
            ->fillForm(['email' => $administrateur->email, 'password' => 'password'])
            ->call('authenticate')
            ->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', 'abcdefghij-0123456789')
            ->call('authenticate')
            ->assertHasErrors();

        expect(auth('admin')->check())->toBeFalse();
    });

    it('refuse proprement un code de récupération à qui a un secret mais n’a pas encore de codes (compte enrôlé avant leur activation)', function (): void {
        $administrateur = superAdministrateurDuPanneau();

        Livewire::test(ConnexionDuPanneau::class)
            ->fillForm(['email' => $administrateur->email, 'password' => 'password'])
            ->call('authenticate')
            ->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', 'abcdefghij-0123456789')
            ->call('authenticate')
            ->assertHasErrors();

        expect(auth('admin')->check())->toBeFalse();
    });

    it('laisse entrer par son mot de passe seul celui qui n’a pas enrôlé, vers l’enrôlement', function (): void {
        $administrateur = superAdministrateurDuPanneau(avecLaDoubleAuthentification: false);

        Livewire::test(ConnexionDuPanneau::class)
            ->fillForm(['email' => $administrateur->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        expect(auth('admin')->id())->toBe($administrateur->id);

        $this->get('/backoffice')->assertRedirect(adresseDeLEnrolement());
    });
});

describe('admin:reinitialiser-mfa', function (): void {
    it('efface le secret et les codes, et renvoie l’administrateur à l’enrôlement', function (): void {
        $administrateur = Admin::factory()->avecCodesDeRecuperation(['abcdefghij-0123456789'])->create();
        $administrateur->assignRole(Role::findOrCreate(Admin::roleDuSuperAdministrateur(), 'admin'));

        $this->artisan('admin:reinitialiser-mfa', ['email' => $administrateur->email])
            ->expectsOutputToContain('Double authentification effacée')
            ->assertSuccessful();

        $administrateur->refresh();
        expect($administrateur->aLaDoubleAuthentification())->toBeFalse()
            ->and($administrateur->getAppAuthenticationRecoveryCodes())->toBeNull();

        $this->actingAs($administrateur, 'admin')->get('/backoffice')->assertRedirect(adresseDeLEnrolement());
    });

    it('ne touche que l’administrateur désigné', function (): void {
        $vise = Admin::factory()->create();
        $autre = Admin::factory()->create();

        $this->artisan('admin:reinitialiser-mfa', ['email' => $vise->email])->assertSuccessful();

        expect($autre->refresh()->aLaDoubleAuthentification())->toBeTrue();
    });

    it('le dit quand il n’y avait rien à effacer', function (): void {
        $administrateur = Admin::factory()->sansDoubleAuthentification()->create();

        $this->artisan('admin:reinitialiser-mfa', ['email' => $administrateur->email])
            ->expectsOutputToContain('rien à effacer')
            ->assertSuccessful();
    });

    it('refuse une adresse que nul administrateur ne porte', function (): void {
        $this->artisan('admin:reinitialiser-mfa', ['email' => 'inconnu@example.org'])
            ->expectsOutputToContain('Aucun administrateur')
            ->assertFailed();
    });
});
