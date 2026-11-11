<?php

declare(strict_types=1);

use App\Actions\Diagnostic\CreationDeJetonRefusee;
use App\Actions\Diagnostic\CreeUnJetonDeDiagnosticAction;
use App\Actions\Diagnostic\RevoqueLesJetonsDeDiagnosticAction;
use App\Enums\PorteeDeDiagnostic;
use App\Enums\RaisonDeRevocationDuJeton;
use App\Models\Admin;
use App\Models\JetonDeDiagnostic;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\Support\FilamentAdminPanel;

/**
 * Les jetons de l'accès de diagnostic (#2032), sans route : le stockage, la
 * création et ses refus, la révocation, la purge. Aucun jeton n'est écrit en
 * clair ici : ils se composent à l'exécution (`AucunJetonDeDiagnosticNEstSuiviTest`).
 */
beforeEach(function (): void {
    config()->set('app.diagnostic_enabled', true);
    config()->set('health.notifications.mail.to', 'alertes@example.org');
    Cache::flush();
    RateLimiter::clear('diagnostic:creation:1');
});

/**
 * Un super administrateur, avec la capacité et la double authentification.
 *
 * @return array{0: Admin, 1: string} l'administrateur et son secret TOTP
 */
function jetonsDeDiagnosticSuperAdministrateur(bool $avecLaDoubleAuthentification = true): array
{
    $admin = FilamentAdminPanel::admin(['view-diagnostic']);
    $admin->assignRole(Role::findOrCreate(Admin::roleDuSuperAdministrateur(), 'admin'));
    $secret = new Google2FA()->generateSecretKey();

    if ($avecLaDoubleAuthentification) {
        $admin->forceFill(['app_authentication_secret' => $secret])->save();
    }

    test()->actingAs($admin, 'admin');

    return [$admin->refresh(), $secret];
}

function jetonsDeDiagnosticEstActif(JetonDeDiagnostic $jeton): bool
{
    return JetonDeDiagnostic::query()->actifs(now())->whereKey($jeton->id)->exists();
}

/**
 * @param  list<PorteeDeDiagnostic>  $portees
 * @return array{jeton: JetonDeDiagnostic, en_clair: string}
 */
function jetonsDeDiagnosticCreer(Admin $admin, string $secretTotp, array $portees = [PorteeDeDiagnostic::Sante], int $jours = 7, ?string $code = null): array
{
    return resolve(CreeUnJetonDeDiagnosticAction::class)->execute(
        $admin,
        'Agent de maintenance',
        $portees,
        $jours,
        $code ?? new Google2FA()->getCurrentOtp($secretTotp),
    );
}

it('compose un jeton que le masque de l’expurgation reconnaît', function (): void {
    $jeton = JetonDeDiagnostic::composer(str_repeat('a1', 6), str_repeat('Zq9', 16));

    expect(preg_match(App\Services\Diagnostic\Expurgation\MasqueLesSecrets::MOTIF_DU_JETON, $jeton))->toBe(1)
        ->and(strlen($jeton))->toBe(strlen(JetonDeDiagnostic::PREFIXE) + 12 + 1 + 48);
});

it('ne garde que l’empreinte du secret', function (): void {
    [$admin, $secretTotp] = jetonsDeDiagnosticSuperAdministrateur();
    ['jeton' => $jeton, 'en_clair' => $enClair] = jetonsDeDiagnosticCreer($admin, $secretTotp);
    $secret = substr($enClair, -JetonDeDiagnostic::LONGUEUR_DU_SECRET);

    $ligne = (string) json_encode(DB::table('jetons_de_diagnostic')->where('id', $jeton->id)->first());

    expect($ligne)->not->toContain($secret)
        ->and($ligne)->toContain(JetonDeDiagnostic::empreinteDe($secret))
        ->and($jeton->toArray())->not->toHaveKey('empreinte');
});

it('crée un jeton de sept jours, sur les portées demandées, au nom du créateur', function (): void {
    [$admin, $secretTotp] = jetonsDeDiagnosticSuperAdministrateur();

    ['jeton' => $jeton] = jetonsDeDiagnosticCreer($admin, $secretTotp, [PorteeDeDiagnostic::Sante, PorteeDeDiagnostic::Exceptions]);

    expect($jeton->cree_par_admin_id)->toBe($admin->id)
        ->and($jeton->portees)->toBe(['sante', 'exceptions'])
        ->and((int) round(now()->diffInDays($jeton->expire_le, true)))->toBe(7)
        ->and(jetonsDeDiagnosticEstActif($jeton))->toBeTrue();
});

/**
 * @param  array<string, mixed>  $reglages
 * @param  list<PorteeDeDiagnostic>  $portees
 */
it('refuse la création côté serveur, un motif par cause', function (array $reglages, array $portees, int $jours, ?string $code, string $motif): void {
    [$admin, $secretTotp] = jetonsDeDiagnosticSuperAdministrateur();
    config()->set($reglages);

    try {
        jetonsDeDiagnosticCreer($admin, $secretTotp, array_values(array_filter($portees, static fn (mixed $portee): bool => $portee instanceof PorteeDeDiagnostic)), $jours, $code);
        $obtenu = null;
    } catch (CreationDeJetonRefusee $refus) {
        $obtenu = $refus->motif;
    }

    expect($obtenu)->toBe($motif)
        ->and(JetonDeDiagnostic::query()->count())->toBe(0);
})->with([
    'interrupteur coupé' => [['app.diagnostic_enabled' => false], [PorteeDeDiagnostic::Sante], 7, null, CreationDeJetonRefusee::INTERRUPTEUR_COUPE],
    'interrupteur « 1 » lu comme texte' => [['app.diagnostic_enabled' => '1'], [PorteeDeDiagnostic::Sante], 7, null, CreationDeJetonRefusee::INTERRUPTEUR_COUPE],
    'adresse des alertes vide' => [['health.notifications.mail.to' => ''], [PorteeDeDiagnostic::Sante], 7, null, CreationDeJetonRefusee::ADRESSE_DES_ALERTES_VIDE],
    'adresse des alertes absente' => [['health.notifications.mail.to' => null], [PorteeDeDiagnostic::Sante], 7, null, CreationDeJetonRefusee::ADRESSE_DES_ALERTES_VIDE],
    'adresse des alertes en blancs' => [['health.notifications.mail.to' => '  '], [PorteeDeDiagnostic::Sante], 7, null, CreationDeJetonRefusee::ADRESSE_DES_ALERTES_VIDE],
    'liste d’adresses vides' => [['health.notifications.mail.to' => ['', ' ']], [PorteeDeDiagnostic::Sante], 7, null, CreationDeJetonRefusee::ADRESSE_DES_ALERTES_VIDE],
    'code faux' => [[], [PorteeDeDiagnostic::Sante], 7, '000000', CreationDeJetonRefusee::CODE_REFUSE],
    'durée hors liste' => [[], [PorteeDeDiagnostic::Sante], 8, null, CreationDeJetonRefusee::DUREE_INVALIDE],
    'texte brut au-delà d’un jour' => [[], [PorteeDeDiagnostic::Sante, PorteeDeDiagnostic::TexteBrut], 7, null, CreationDeJetonRefusee::DUREE_INVALIDE],
    'aucune portée' => [[], [], 7, null, CreationDeJetonRefusee::PORTEES_INVALIDES],
    'portée en double' => [[], [PorteeDeDiagnostic::Sante, PorteeDeDiagnostic::Sante], 7, null, CreationDeJetonRefusee::PORTEES_INVALIDES],
]);

it('refuse sans double authentification, et à qui n’est pas super administrateur', function (): void {
    [$sansDouble] = jetonsDeDiagnosticSuperAdministrateur(avecLaDoubleAuthentification: false);

    expect(fn (): array => jetonsDeDiagnosticCreer($sansDouble, 'AAAA', code: '123456'))
        ->toThrow(new CreationDeJetonRefusee(CreationDeJetonRefusee::DOUBLE_AUTHENTIFICATION_ABSENTE));

    $simple = FilamentAdminPanel::admin(['view-diagnostic']);

    expect(fn (): array => jetonsDeDiagnosticCreer($simple, 'AAAA', code: '123456'))
        ->toThrow(new CreationDeJetonRefusee(CreationDeJetonRefusee::CAPACITE_ABSENTE));
});

it('refuse un code déjà accepté', function (): void {
    [$admin, $secretTotp] = jetonsDeDiagnosticSuperAdministrateur();
    $code = new Google2FA()->getCurrentOtp($secretTotp);

    jetonsDeDiagnosticCreer($admin, $secretTotp, code: $code);

    expect(fn (): array => jetonsDeDiagnosticCreer($admin, $secretTotp, code: $code))
        ->toThrow(new CreationDeJetonRefusee(CreationDeJetonRefusee::CODE_REFUSE));
});

it('coupe après cinq essais de code dans la minute', function (): void {
    [$admin, $secretTotp] = jetonsDeDiagnosticSuperAdministrateur();
    RateLimiter::clear('diagnostic:creation:'.$admin->id);

    foreach (range(1, CreeUnJetonDeDiagnosticAction::ESSAIS_PAR_MINUTE) as $essai) {
        expect(fn (): array => jetonsDeDiagnosticCreer($admin, $secretTotp, code: '000000'))
            ->toThrow(new CreationDeJetonRefusee(CreationDeJetonRefusee::CODE_REFUSE));
    }

    expect(fn (): array => jetonsDeDiagnosticCreer($admin, $secretTotp, code: '000000'))
        ->toThrow(new CreationDeJetonRefusee(CreationDeJetonRefusee::TROP_D_ESSAIS));
});

it('ne consomme pas le code quand deux jetons sont déjà actifs', function (): void {
    [$admin, $secretTotp] = jetonsDeDiagnosticSuperAdministrateur();
    JetonDeDiagnostic::factory()->count(2)->create();
    $code = new Google2FA()->getCurrentOtp($secretTotp);

    expect(fn (): array => jetonsDeDiagnosticCreer($admin, $secretTotp, code: $code))
        ->toThrow(new CreationDeJetonRefusee(CreationDeJetonRefusee::JETONS_ACTIFS_AU_MAXIMUM))
        ->and(RateLimiter::attempts('diagnostic:creation:'.$admin->id))->toBe(0);

    JetonDeDiagnostic::query()->update(['revoque_le' => now()]);

    expect(jetonsDeDiagnosticCreer($admin, $secretTotp, code: $code)['jeton'])->toBeInstanceOf(JetonDeDiagnostic::class);
});

it('refuse un troisième jeton actif', function (): void {
    [$admin, $secretTotp] = jetonsDeDiagnosticSuperAdministrateur();
    JetonDeDiagnostic::factory()->count(2)->create();

    expect(fn (): array => jetonsDeDiagnosticCreer($admin, $secretTotp))
        ->toThrow(new CreationDeJetonRefusee(CreationDeJetonRefusee::JETONS_ACTIFS_AU_MAXIMUM));
});

it('crée un jeton quand les deux autres sont expirés ou révoqués', function (): void {
    [$admin, $secretTotp] = jetonsDeDiagnosticSuperAdministrateur();
    JetonDeDiagnostic::factory()->expire()->create();
    JetonDeDiagnostic::factory()->revoque()->create();

    expect(jetonsDeDiagnosticCreer($admin, $secretTotp)['jeton']->exists)->toBeTrue();
});

it('révoque une seule fois : la première raison reste', function (): void {
    $jeton = JetonDeDiagnostic::factory()->create();

    expect($jeton->revoquer(RaisonDeRevocationDuJeton::Manuelle, null, now()))->toBeTrue()
        ->and($jeton->revoquer(RaisonDeRevocationDuJeton::Interrupteur, null, now()))->toBeFalse()
        ->and($jeton->refresh()->raison_de_revocation)->toBe('manuelle')
        ->and(jetonsDeDiagnosticEstActif($jeton))->toBeFalse();
});

it('révoque par identifiant, par créateur ou tout, sans toucher les jetons terminés', function (): void {
    $admin = Admin::factory()->create();
    $a = JetonDeDiagnostic::factory()->for($admin, 'createur')->create();
    $b = JetonDeDiagnostic::factory()->create();
    $revocation = resolve(RevoqueLesJetonsDeDiagnosticAction::class);

    expect($revocation->execute(RaisonDeRevocationDuJeton::Manuelle, null, $a->identifiant))->toHaveCount(1)
        ->and(jetonsDeDiagnosticEstActif($b))->toBeTrue()
        ->and($revocation->execute(RaisonDeRevocationDuJeton::Manuelle))->toHaveCount(1)
        ->and($revocation->execute(RaisonDeRevocationDuJeton::Manuelle))->toBe([]);
});

it('révoque les jetons d’un administrateur quand il change de mot de passe', function (): void {
    $admin = Admin::factory()->create();
    $sien = JetonDeDiagnostic::factory()->for($admin, 'createur')->create();
    $autre = JetonDeDiagnostic::factory()->create();

    $admin->update(['name' => 'Autre nom']);

    expect($sien->refresh()->revoque_le)->toBeNull();

    $admin->update(['password' => Hash::make('un-autre-mot-de-passe-solide')]);

    expect($sien->refresh()->raison_de_revocation)->toBe('mot-de-passe-change')
        ->and($autre->refresh()->revoque_le)->toBeNull();
});

it('révoque les jetons d’un administrateur dont la double authentification est retirée', function (): void {
    $admin = Admin::factory()->create();
    $admin->forceFill(['app_authentication_secret' => 'SECRETDETEST'])->save();
    $jeton = JetonDeDiagnostic::factory()->for($admin, 'createur')->create();

    $admin->forceFill(['app_authentication_secret' => null])->save();

    expect($jeton->refresh()->raison_de_revocation)->toBe('double-authentification-retiree');
});

it('révoque à la suppression de l’administrateur et garde la ligne du jeton', function (): void {
    $admin = Admin::factory()->create();
    $jeton = JetonDeDiagnostic::factory()->for($admin, 'createur')->create();

    $admin->delete();

    $relu = $jeton->refresh();

    expect($relu)->not->toBeNull()
        ->and($relu->raison_de_revocation)->toBe('administrateur-supprime')
        ->and($relu->cree_par_admin_id)->toBeNull();
});

it('purge un jeton 90 jours après sa fin, expiré ou révoqué, et pas avant', function (): void {
    $recent = JetonDeDiagnostic::factory()->expire()->create();
    $ancienExpire = JetonDeDiagnostic::factory()->create(['expire_le' => now()->subDays(91)]);
    $ancienRevoque = JetonDeDiagnostic::factory()->create(['revoque_le' => now()->subDays(91)]);
    $actif = JetonDeDiagnostic::factory()->create();

    Artisan::call('model:prune', ['--model' => [JetonDeDiagnostic::class]]);

    expect(JetonDeDiagnostic::query()->whereKey([$ancienExpire->id, $ancienRevoque->id])->count())->toBe(0)
        ->and(JetonDeDiagnostic::query()->whereKey([$recent->id, $actif->id])->count())->toBe(2);
});

it('révoque en console : tous, par identifiant, et rien sans désignation', function (): void {
    $a = JetonDeDiagnostic::factory()->create();
    $b = JetonDeDiagnostic::factory()->create();

    $this->artisan('diagnostic:revoquer')->assertExitCode(2);
    $this->artisan('diagnostic:revoquer', ['--tous' => true, '--identifiant' => $a->identifiant])->assertExitCode(2);
    $this->artisan('diagnostic:revoquer', ['--si-coupe' => true, '--identifiant' => $a->identifiant])->assertExitCode(2);
    $this->artisan('diagnostic:revoquer', ['--si-coupe' => true, '--tous' => true])->assertExitCode(2);

    expect(jetonsDeDiagnosticEstActif($a))->toBeTrue()
        ->and(jetonsDeDiagnosticEstActif($b))->toBeTrue();

    $this->artisan('diagnostic:revoquer', ['--identifiant' => $a->identifiant])->assertSuccessful();

    expect(jetonsDeDiagnosticEstActif($a))->toBeFalse()
        ->and(jetonsDeDiagnosticEstActif($b))->toBeTrue();

    $this->artisan('diagnostic:revoquer', ['--tous' => true])->assertSuccessful();

    expect($b->refresh()->raison_de_revocation)->toBe('manuelle');
});

it('ne révoque au démarrage que si l’interrupteur est coupé, avec la raison « interrupteur »', function (): void {
    $jeton = JetonDeDiagnostic::factory()->create();

    $this->artisan('diagnostic:revoquer', ['--si-coupe' => true])->assertSuccessful();

    expect($jeton->refresh()->revoque_le)->toBeNull();

    config()->set('app.diagnostic_enabled', false);
    $this->artisan('diagnostic:revoquer', ['--si-coupe' => true])->assertSuccessful();

    expect($jeton->refresh()->raison_de_revocation)->toBe('interrupteur');
});

it('lit l’interrupteur strictement dans l’environnement', function (?string $valeur, bool $attendu): void {
    $envAvant = $_ENV['DIAGNOSTIC_ENABLED'] ?? null;
    $serveurAvant = $_SERVER['DIAGNOSTIC_ENABLED'] ?? null;

    if ($valeur === null) {
        unset($_ENV['DIAGNOSTIC_ENABLED'], $_SERVER['DIAGNOSTIC_ENABLED']);
    } else {
        $_ENV['DIAGNOSTIC_ENABLED'] = $_SERVER['DIAGNOSTIC_ENABLED'] = $valeur;
    }

    $reglages = require config_path('app.php');

    unset($_ENV['DIAGNOSTIC_ENABLED'], $_SERVER['DIAGNOSTIC_ENABLED']);

    if ($envAvant !== null) {
        $_ENV['DIAGNOSTIC_ENABLED'] = $envAvant;
    }

    if ($serveurAvant !== null) {
        $_SERVER['DIAGNOSTIC_ENABLED'] = $serveurAvant;
    }

    expect(is_array($reglages) ? ($reglages['diagnostic_enabled'] ?? null) : null)->toBe($attendu);
})->with([
    'vrai' => ['true', true],
    '« 1 » n’est pas vrai' => ['1', false],
    'faux' => ['false', false],
    'absent' => [null, false],
]);

it('tient le verrou de création le temps d’une création, puis le rend', function (): void {
    [$admin, $secretTotp] = jetonsDeDiagnosticSuperAdministrateur();

    jetonsDeDiagnosticCreer($admin, $secretTotp);

    $verrou = Cache::lock('diagnostic:creation', CreeUnJetonDeDiagnosticAction::VIE_DU_VERROU);

    expect($verrou->get())->toBeTrue();

    $verrou->release();
});

it('purge les jetons de diagnostic par la tâche planifiée', function (): void {
    $planifiees = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($evenement): bool => str_contains((string) $evenement->command, 'model:prune') && str_contains((string) $evenement->command, 'JetonDeDiagnostic'));

    expect($planifiees)->toHaveCount(1);
});
