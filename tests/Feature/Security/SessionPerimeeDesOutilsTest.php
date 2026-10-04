<?php

declare(strict_types=1);

use App\Models\Admin;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withServerVariables;
use function Pest\Laravel\withSession;

/**
 * Une session du panneau que le changement du mot de passe a invalidée
 * n'ouvre plus aucun outil d'administration.
 *
 * Le panneau passe par `Filament\Http\Middleware\AuthenticateSession` : la
 * session porte l'empreinte du mot de passe (`password_hash_admin`), que la
 * connexion pose et que la page de profil réécrit ; quand l'administrateur
 * change son mot de passe, toute autre session est déconnectée à sa requête
 * suivante. Pulse, le lecteur de journaux (page et API) et la voie
 * administrateur d'Horizon ne la vérifiaient pas : une session volée, que le
 * panneau rejetait, ouvrait encore les tâches d'Horizon, que l'on peut relire
 * et relancer, les journaux et Pulse. Seule la liste d'adresses du panneau
 * bornait l'attaque.
 */

/**
 * La production, une adresse admise, Horizon sans Redis, et la session qu'un
 * super administrateur a ouverte au panneau avec le mot de passe qu'il avait
 * alors — changé depuis, ou non.
 */
function sessionPerimeeOuverteAuPanneau(bool $motDePasseChangeDepuis): void
{
    config(['app.admin_allowed_ips' => ['203.0.113.5']]);
    app()->detectEnvironment(static fn (): string => 'production');
    withServerVariables(['REMOTE_ADDR' => '203.0.113.5']);
    test()->mock(MasterSupervisorRepository::class)->allows('all')->andReturn([]);
    test()->mock(SupervisorRepository::class)->allows('all')->andReturn([]);

    $superAdministrateur = Admin::factory()->create();
    $superAdministrateur->assignRole(Role::findOrCreate('super_admin', 'admin'));
    $garde = Auth::guard('admin');

    if (! $garde instanceof SessionGuard) {
        throw new LogicException('La garde du panneau tient sa connexion en session.');
    }

    withSession([
        $garde->getName() => $superAdministrateur->getAuthIdentifier(),
        'password_hash_admin' => $garde->hashPasswordForCookie($superAdministrateur->getAuthPassword()),
    ]);

    if ($motDePasseChangeDepuis) {
        $superAdministrateur->forceFill(['password' => 'Un-autre-mot-de-passe-2026!'])->save();
    }
}

/**
 * Chaque outil, comme le navigateur l'appelle (les API en JSON), avec la
 * réponse due à la session périmée : renvoi vers la connexion du panneau pour
 * une page sous `/backoffice`, 401 pour l'API du lecteur, 403 pour Horizon,
 * qui ne renvoie nulle part. Le panneau sert de témoin.
 *
 * @return array<string, array{string, bool, int, string|null}>
 */
function sessionPerimeeOutils(): array
{
    return [
        'le panneau (témoin)' => ['/backoffice', false, 302, '/backoffice/login'],
        'Pulse' => ['/backoffice/pulse', false, 302, '/backoffice/login'],
        'le lecteur de journaux' => ['/backoffice/journaux', false, 302, '/backoffice/login'],
        'l’API du lecteur de journaux' => ['/backoffice/journaux/api/folders', true, 401, null],
        'Horizon' => ['/horizon', false, 403, null],
        'l’API d’Horizon' => ['/horizon/api/masters', true, 403, null],
    ];
}

it('refuse la session dont le mot de passe a changé depuis', function (string $chemin, bool $json, int $statut, ?string $redirection): void {
    sessionPerimeeOuverteAuPanneau(motDePasseChangeDepuis: true);

    $reponse = $json ? getJson($chemin) : get($chemin);
    $destination = $reponse->headers->get('Location');

    expect($reponse->getStatusCode())->toBe($statut)
        ->and($destination === null ? null : parse_url($destination, PHP_URL_PATH))->toBe($redirection);
})->with(sessionPerimeeOutils());

it('ouvre chaque outil à la même session tant que le mot de passe n’a pas changé', function (string $chemin, bool $json): void {
    sessionPerimeeOuverteAuPanneau(motDePasseChangeDepuis: false);

    ($json ? getJson($chemin) : get($chemin))->assertOk();
})->with(sessionPerimeeOutils());
