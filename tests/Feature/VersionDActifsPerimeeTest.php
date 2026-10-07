<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withHeaders;

/*
 * Ce que le serveur répond à une page qui se sait périmée (#1967).
 *
 * Quand un nouveau worker a pris la main, la page ouverte tourne encore sur
 * l'ancienne version. Sa prochaine visite qui quitte la page annonce alors la
 * version d'actifs `perimee` (`VERSION_PERIMEE`, resources/js/Utils/miseAJourDuWorker.js) :
 * le serveur répond 409 avec l'adresse demandée, et Inertia en fait une
 * navigation complète, sur la nouvelle version. Sans réseau, aucune réponse :
 * la visite échoue et la page reste.
 *
 * Ce fichier garde le contrat sur lequel compte le client, et ne prouve pas le
 * correctif : le 409 est celui que le middleware d'Inertia rend déjà à toute
 * version différente, et #1967 ne change rien côté serveur. Il passe donc sans
 * le correctif. La contre-épreuve de #1967 est portée par
 * tests/js/utils/miseAJourDuWorkerAvecInertia.test.js, qui joue la visite
 * avec le vrai client d'Inertia, et par tests/js/utils/miseAJourDuWorker.test.js,
 * qui exécute le `register.js` du paquet.
 */

/**
 * Les en-têtes d'une visite Inertia qui annonce la version donnée.
 *
 * @return array<string, string>
 */
function versionPerimeeEntetes(string $version): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ];
}

it('répond par une navigation complète vers l’adresse demandée', function (string $chemin, bool $connecte): void {
    if ($connecte) {
        actingAs(User::factory()->create());
    }

    $reponse = withHeaders(versionPerimeeEntetes('perimee'))->get('https://gym.example.org'.$chemin);

    $reponse->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://gym.example.org'.$chemin);
})->with([
    'une page de compte, avec sa requête' => ['/daily-journals?page=2', true],
    'une page publique' => ['/login', false],
]);

it('sert la page à une visite qui annonce la version du serveur', function (): void {
    actingAs(User::factory()->create());

    $version = (string) app(HandleInertiaRequests::class)->version(request());

    $reponse = withHeaders(versionPerimeeEntetes($version))->get('https://gym.example.org/daily-journals');

    $reponse->assertOk()
        ->assertHeader('X-Inertia', 'true');

    expect($reponse->json('component'))->toBe('Journal/Index');
});
