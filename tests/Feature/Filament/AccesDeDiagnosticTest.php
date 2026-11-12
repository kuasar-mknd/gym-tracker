<?php

declare(strict_types=1);

use App\Actions\Diagnostic\CreationDeJetonRefusee;
use App\Enums\RaisonDeRevocationDuJeton;
use App\Filament\Pages\AccesDeDiagnostic;
use App\Models\Admin;
use App\Models\ExceptionEnregistree;
use App\Models\JetonDeDiagnostic;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\Support\FilamentAdminPanel;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * « Système › Accès de diagnostic » (#2032) : la liste des jetons, leur
 * création par un code de la double authentification, leur révocation. Le
 * jeton en clair n'est montré qu'une fois, dans une fenêtre, et n'est écrit
 * nulle part ailleurs.
 */

beforeEach(function (): void {
    config()->set('app.diagnostic_enabled', true);
    config()->set('health.notifications.mail.to', 'alertes@example.org');
    Cache::flush();
    Filament::setCurrentPanel('admin');
});

/**
 * Un super administrateur, avec la double authentification et la capacité.
 *
 * @return array{0: Admin, 1: string} l'administrateur et son secret TOTP
 */
function accesDeDiagnosticSuperAdministrateur(bool $avecLaDoubleAuthentification = true): array
{
    $administrateur = FilamentAdminPanel::admin(['view-diagnostic']);
    $administrateur->assignRole(Role::findOrCreate(Admin::roleDuSuperAdministrateur(), 'admin'));
    $secret = new Google2FA()->generateSecretKey();

    if ($avecLaDoubleAuthentification) {
        $administrateur->forceFill(['app_authentication_secret' => $secret])->save();
    }

    actingAs($administrateur, 'admin');
    RateLimiter::clear('diagnostic:creation:'.$administrateur->id);

    return [$administrateur->refresh(), $secret];
}

/**
 * Les messages écrits dans les journaux pendant l'appel, contexte compris.
 *
 * @return list<string>
 */
function accesDeDiagnosticJournaux(Closure $appel): array
{
    $ecrits = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $message) use (&$ecrits): void {
        $ecrits[] = $message->message.' '.json_encode($message->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
    });

    $appel();

    return $ecrits;
}

/**
 * Le tableau de bord du panneau, dont le menu porte les pages permises.
 */
function accesDeDiagnosticMenu(): string
{
    return (string) get('/backoffice')->getContent();
}

it('s’ouvre au super administrateur doté de la double authentification, et le menu la range sous Système', function (): void {
    accesDeDiagnosticSuperAdministrateur();

    get(AccesDeDiagnostic::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('Accès de diagnostic');

    expect(AccesDeDiagnostic::getUrl(panel: 'admin'))->toEndWith('/backoffice/acces-de-diagnostic')
        ->and(AccesDeDiagnostic::getNavigationGroup())->toBe('Système')
        ->and(AccesDeDiagnostic::canAccess())->toBeTrue()
        ->and(accesDeDiagnosticMenu())->toContain('/backoffice/acces-de-diagnostic');
});

it('se refuse, invisible et en 403, sans double authentification', function (): void {
    accesDeDiagnosticSuperAdministrateur(avecLaDoubleAuthentification: false);

    get(AccesDeDiagnostic::getUrl(panel: 'admin'))->assertForbidden();
    Livewire::test(AccesDeDiagnostic::class)->assertForbidden();

    expect(AccesDeDiagnostic::canAccess())->toBeFalse()
        ->and(accesDeDiagnosticMenu())->not->toContain('/backoffice/acces-de-diagnostic');
});

it('se refuse à qui porte la capacité sans être super administrateur', function (): void {
    $administrateur = FilamentAdminPanel::admin(['view-diagnostic']);
    $administrateur->forceFill(['app_authentication_secret' => new Google2FA()->generateSecretKey()])->save();

    actingAs($administrateur->refresh(), 'admin')
        ->get(AccesDeDiagnostic::getUrl(panel: 'admin'))
        ->assertForbidden();

    expect(AccesDeDiagnostic::canAccess())->toBeFalse();
});

it('renvoie un visiteur et un compte de l’application vers la connexion du panneau', function (): void {
    get(AccesDeDiagnostic::getUrl(panel: 'admin'))->assertRedirect(route('filament.admin.auth.login'));

    actingAs(User::factory()->create(), 'web')
        ->get(AccesDeDiagnostic::getUrl(panel: 'admin'))
        ->assertRedirect(route('filament.admin.auth.login'));
});

it('liste les jetons, leur état et leur dernière utilisation, sans jamais lire l’empreinte', function (): void {
    [$administrateur] = accesDeDiagnosticSuperAdministrateur();
    $actif = JetonDeDiagnostic::factory()->create(['nom' => 'Jeton actif', 'cree_par_admin_id' => $administrateur->id, 'derniere_utilisation_le' => now()->subHour()]);
    $expire = JetonDeDiagnostic::factory()->create(['nom' => 'Jeton expiré', 'expire_le' => now()->subDay()]);
    $revoque = JetonDeDiagnostic::factory()->create(['nom' => 'Jeton révoqué']);
    $revoque->revoquer(RaisonDeRevocationDuJeton::Manuelle, $administrateur, now());

    $page = Livewire::test(AccesDeDiagnostic::class)
        ->assertCanSeeTableRecords([$actif, $expire, $revoque])
        ->assertSee([$actif->identifiant, 'Jeton actif', 'Jeton expiré', 'Jeton révoqué', 'actif', 'expiré', 'révoqué', 'Jamais'])
        ->assertDontSee([$actif->empreinte, $expire->empreinte, $revoque->empreinte]);

    expect($page->html())->not->toContain('empreinte');
});

it('crée un jeton par un code de la double authentification, et ne le montre qu’une fois', function (): void {
    [$administrateur, $secret] = accesDeDiagnosticSuperAdministrateur();

    $page = Livewire::test(AccesDeDiagnostic::class);
    $page->callAction('creer', [
        'nom' => 'Agent de maintenance',
        'portees' => ['sante', 'exceptions'],
        'jours' => 7,
        'code' => new Google2FA()->getCurrentOtp($secret),
    ])
        ->assertActionMounted('afficherLeJeton')
        ->assertMountedActionModalSee('Le jeton, affiché une seule fois')
        ->assertMountedActionModalSee(JetonDeDiagnostic::PREFIXE);

    $jeton = JetonDeDiagnostic::query()->sole();

    expect($jeton->nom)->toBe('Agent de maintenance')
        ->and($jeton->portees)->toBe(['sante', 'exceptions'])
        ->and($jeton->cree_par_admin_id)->toBe($administrateur->id)
        ->and($jeton->expire_le->toDateString())->toBe(now()->addDays(7)->toDateString())
        ->and($page->getMountedActionModalHtml())->toContain($jeton->identifiant);

    $affichage = accesDeDiagnosticAffichage($page);
    $page->unmountAction();

    expect($page->html())->not->toContain(JetonDeDiagnostic::PREFIXE.$jeton->identifiant.'_')
        ->and(Cache::get('diagnostic:jeton-affiche:'.$affichage))->toBeNull();
});

/**
 * L'état du composant, tel qu'il part au navigateur.
 *
 * @param  Testable<AccesDeDiagnostic>  $page
 */
function accesDeDiagnosticEtat(Testable $page): string
{
    return (string) json_encode($page->__get('snapshot'));
}

/**
 * La clef que la fenêtre ouverte porte dans ses arguments.
 *
 * @param  Testable<AccesDeDiagnostic>  $page
 */
function accesDeDiagnosticAffichage(Testable $page): string
{
    preg_match('/"affichage":"([A-Za-z0-9]{40})"/', accesDeDiagnosticEtat($page), $trouve);

    return $trouve[1] ?? '';
}

/**
 * Crée un jeton par la page et rend le jeton en clair qu'elle a montré.
 *
 * @param  Testable<AccesDeDiagnostic>  $page
 */
function accesDeDiagnosticCreerEtLire(Testable $page, string $secret): string
{
    $page->callAction('creer', [
        'nom' => 'Agent',
        'portees' => ['sante'],
        'jours' => 7,
        'code' => new Google2FA()->getCurrentOtp($secret),
    ]);

    preg_match('/gtdiag_[a-z0-9]{12}_[A-Za-z0-9]{48}/', $page->getMountedActionModalHtml(), $trouve);

    return $trouve[0] ?? throw new RuntimeException('Le jeton n’est pas dans la fenêtre.');
}

it('ne garde le jeton en clair ni dans la session, ni dans les journaux, ni dans l’état du composant, ni dans une notification', function (): void {
    [, $secret] = accesDeDiagnosticSuperAdministrateur();
    $page = Livewire::test(AccesDeDiagnostic::class);
    $enClair = '';

    $journaux = accesDeDiagnosticJournaux(function () use ($page, $secret, &$enClair): void {
        $enClair = accesDeDiagnosticCreerEtLire($page, $secret);
        $page->call('$refresh');
    });
    $secretDuJeton = substr($enClair, -48);
    expect($enClair)->not->toBe('')
        ->and(json_encode(session()->all()))->not->toContain($secretDuJeton)
        ->and(accesDeDiagnosticEtat($page))->not->toContain($secretDuJeton)
        ->and(json_encode(session()->get('filament.notifications')))->not->toContain($secretDuJeton)
        ->and(implode("\n", $journaux))->not->toContain($secretDuJeton)
        ->and(accesDeDiagnosticEtat($page))->not->toContain('jetonChiffre')
        ->and(JetonDeDiagnostic::query()->sole()->empreinte)->toBe(JetonDeDiagnostic::empreinteDe($secretDuJeton));
});

it('ne laisse pas le jeton en clair dans l’état ni dans la table des exceptions quand une requête lève, fenêtre ouverte', function (): void {
    [, $secret] = accesDeDiagnosticSuperAdministrateur();
    $page = Livewire::test(AccesDeDiagnostic::class);
    $enClair = accesDeDiagnosticCreerEtLire($page, $secret);
    $secretDuJeton = substr($enClair, -48);
    $range = Cache::get('diagnostic:jeton-affiche:'.accesDeDiagnosticAffichage($page));
    $chiffre = is_string($range) ? substr($range, 0, 60) : '';

    $arme = new stdClass();
    $arme->actif = true;
    JetonDeDiagnostic::retrieved(static function () use ($arme): void {
        if ($arme->actif) {
            throw new RuntimeException('lecture impossible');
        }
    });

    $journaux = accesDeDiagnosticJournaux(function () use ($page, $arme): void {
        try {
            $page->call('mountAction', 'toutRevoquer')->call('callMountedAction');
        } catch (Throwable $exception) {
            request()->merge(['components' => [['snapshot' => accesDeDiagnosticEtat($page), 'updates' => [], 'calls' => []]]]);
            report($exception);
        } finally {
            $arme->actif = false;
        }
    });

    $ecrites = json_encode(ExceptionEnregistree::query()->get()->toArray(), JSON_PARTIAL_OUTPUT_ON_ERROR);

    expect($chiffre)->not->toBe('')
        ->and($ecrites)->toContain('lecture impossible')
        ->and($ecrites)->toContain('components')
        ->and(accesDeDiagnosticEtat($page))->not->toContain($secretDuJeton)
        ->and(implode("\n", $journaux))->not->toContain($secretDuJeton)
        ->and($ecrites)->not->toContain($secretDuJeton)
        ->and($ecrites)->not->toContain($chiffre)
        ->and(accesDeDiagnosticEtat($page))->not->toContain($chiffre);
});

it('ne rend plus le jeton à un état rejoué après la fermeture de la fenêtre', function (): void {
    [, $secret] = accesDeDiagnosticSuperAdministrateur();
    $page = Livewire::test(AccesDeDiagnostic::class);
    $enClair = accesDeDiagnosticCreerEtLire($page, $secret);
    $ouvert = accesDeDiagnosticEtat($page);

    $rejoueFenetreOuverte = Livewire::update(json_decode($ouvert, true), [], []);

    expect(json_encode($rejoueFenetreOuverte))->toContain(substr($enClair, -48));

    $page->unmountAction();

    $rejoue = Livewire::update(json_decode($ouvert, true), [], []);

    expect(json_encode($rejoue))->not->toContain(substr($enClair, -48))
        ->and(accesDeDiagnosticEtat($page))->not->toContain(substr($enClair, -48));
});

it('range le jeton chiffré, pour cinq minutes, pour le seul administrateur qui l’a créé', function (): void {
    [$administrateur, $secret] = accesDeDiagnosticSuperAdministrateur();
    $page = Livewire::test(AccesDeDiagnostic::class);
    $enClair = accesDeDiagnosticCreerEtLire($page, $secret);
    $etat = json_decode(accesDeDiagnosticEtat($page), true);
    $affichage = accesDeDiagnosticAffichage($page);
    $cle = 'diagnostic:jeton-affiche:'.$affichage;

    expect($affichage)->toBeString()->toHaveLength(40)
        ->and(is_string(Cache::get($cle)))->toBeTrue()
        ->and(str_contains(is_string($valeur = Cache::get($cle)) ? $valeur : '', substr($enClair, -48)))->toBeFalse();

    $autre = FilamentAdminPanel::admin(['view-diagnostic']);
    $autre->assignRole(Role::findOrCreate(Admin::roleDuSuperAdministrateur(), 'admin'));
    $autre->forceFill(['app_authentication_secret' => new Google2FA()->generateSecretKey()])->save();
    actingAs($autre->refresh(), 'admin');

    expect(json_encode(Livewire::update($etat, [], [])))->not->toContain(substr($enClair, -48));

    actingAs($administrateur, 'admin');
    Carbon\Carbon::setTestNow(now()->addMinutes(6));

    expect(Cache::get($cle))->toBeNull();

    Carbon\Carbon::setTestNow();
});

it('refuse un code faux sans créer de jeton, par une phrase écrite ici', function (): void {
    accesDeDiagnosticSuperAdministrateur();

    Livewire::test(AccesDeDiagnostic::class)
        ->callAction('creer', ['nom' => 'Agent <b>x</b>', 'portees' => ['sante'], 'jours' => 7, 'code' => '000000'])
        ->assertNotified(
            Notification::make()->danger()->title('Jeton non créé')->body('Le code est refusé.'),
        )
        ->assertActionNotMounted('afficherLeJeton');

    expect(JetonDeDiagnostic::query()->count())->toBe(0);
});

it('traduit chaque refus de la création en phrase fermée, sans texte venu de l’extérieur', function (): void {
    [, $secret] = accesDeDiagnosticSuperAdministrateur();
    config()->set('health.notifications.mail.to', '');

    Livewire::test(AccesDeDiagnostic::class)
        ->callAction('creer', ['nom' => 'Agent', 'portees' => ['sante'], 'jours' => 7, 'code' => new Google2FA()->getCurrentOtp($secret)])
        ->assertNotified(
            Notification::make()->danger()->title('Jeton non créé')->body('Aucune adresse n’est prévue pour les alertes.'),
        );

    $motifs = new ReflectionClass(CreationDeJetonRefusee::class)->getConstants();
    $traduits = new ReflectionClassConstant(AccesDeDiagnostic::class, 'REFUS')->getValue();
    $traduits = is_array($traduits) ? $traduits : [];

    expect(array_values(array_filter($motifs, is_string(...))))->toEqualCanonicalizing(array_keys($traduits))
        ->and(JetonDeDiagnostic::query()->count())->toBe(0);
});

it('dit qu’une création est en cours quand le verrou reste pris, sans créer de jeton', function (): void {
    [, $secret] = accesDeDiagnosticSuperAdministrateur();
    $verrou = Cache::lock('diagnostic:creation', 30);
    $verrou->get();

    Livewire::test(AccesDeDiagnostic::class)
        ->callAction('creer', ['nom' => 'Agent', 'portees' => ['sante'], 'jours' => 7, 'code' => new Google2FA()->getCurrentOtp($secret)])
        ->assertNotified(
            Notification::make()->danger()->title('Jeton non créé')->body('Une création est déjà en cours. Réessayez.'),
        );

    $verrou->release();

    expect(JetonDeDiagnostic::query()->count())->toBe(0);
});

it('refuse une durée de plus d’un jour avec le texte brut', function (): void {
    [, $secret] = accesDeDiagnosticSuperAdministrateur();

    Livewire::test(AccesDeDiagnostic::class)
        ->callAction('creer', ['nom' => 'Agent', 'portees' => ['sante', 'texte-brut'], 'jours' => 7, 'code' => new Google2FA()->getCurrentOtp($secret)])
        ->assertHasActionErrors(['jours']);

    expect(JetonDeDiagnostic::query()->count())->toBe(0);

    Livewire::test(AccesDeDiagnostic::class)
        ->callAction('creer', ['nom' => 'Agent', 'portees' => ['sante', 'texte-brut'], 'jours' => 1, 'code' => new Google2FA()->getCurrentOtp($secret)])
        ->assertActionMounted('afficherLeJeton');

    expect(JetonDeDiagnostic::query()->sole()->portees)->toBe(['sante', 'texte-brut']);
});

it('propose sept jours par défaut et un jour seulement avec le texte brut', function (): void {
    accesDeDiagnosticSuperAdministrateur();

    Livewire::test(AccesDeDiagnostic::class)
        ->mountAction('creer')
        ->assertSchemaStateSet(['jours' => 7])
        ->fillForm(['portees' => ['sante', 'texte-brut']])
        ->assertSchemaStateSet(['jours' => 1]);
});

it('révoque un jeton par sa ligne, et tous par « Tout révoquer »', function (): void {
    [$administrateur] = accesDeDiagnosticSuperAdministrateur();
    $premier = JetonDeDiagnostic::factory()->create();
    $second = JetonDeDiagnostic::factory()->create();
    $expire = JetonDeDiagnostic::factory()->create(['expire_le' => now()->subDay()]);

    $page = Livewire::test(AccesDeDiagnostic::class)
        ->assertTableActionHidden('revoquer', $expire)
        ->callTableAction('revoquer', $premier);

    expect($premier->refresh()->revoque_le)->not->toBeNull()
        ->and($premier->raison_de_revocation)->toBe(RaisonDeRevocationDuJeton::Manuelle->value)
        ->and($premier->revoque_par_admin_id)->toBe($administrateur->id)
        ->and($second->refresh()->revoque_le)->toBeNull();

    $page->callAction('toutRevoquer');

    expect($second->refresh()->revoque_le)->not->toBeNull()
        ->and(JetonDeDiagnostic::query()->actifs(now())->count())->toBe(0);
});

it('refuse chaque action à qui perd l’accès en cours de route', function (): void {
    [$administrateur] = accesDeDiagnosticSuperAdministrateur();
    $jeton = JetonDeDiagnostic::factory()->create();
    $page = Livewire::test(AccesDeDiagnostic::class);

    $administrateur->forceFill(['app_authentication_secret' => null])->save();
    actingAs($administrateur->refresh(), 'admin');

    $page->call('mountAction', 'toutRevoquer')->assertForbidden();

    expect($jeton->refresh()->revoque_le)->toBeNull();
});

it('autorise chaque action de la page à l’administrateur qui a l’accès, et à lui seul', function (): void {
    [$administrateur] = accesDeDiagnosticSuperAdministrateur();
    $jeton = JetonDeDiagnostic::factory()->create();
    $page = Livewire::test(AccesDeDiagnostic::class)->instance();
    assert($page instanceof AccesDeDiagnostic);
    $actions = fn (): array => [
        $page->creerAction(),
        $page->afficherLeJetonAction(),
        $page->toutRevoquerAction(),
        ...array_values($page->getTable()->getFlatActions()),
    ];

    foreach ($actions() as $action) {
        $action->record($jeton);

        expect($action->isAuthorized())->toBeTrue();
    }

    $administrateur->forceFill(['app_authentication_secret' => null])->save();
    actingAs($administrateur->refresh(), 'admin');

    foreach ($actions() as $action) {
        $action->record($jeton);

        expect($action->isAuthorized())->toBeFalse();
    }
});
