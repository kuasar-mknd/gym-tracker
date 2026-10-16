<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\RequetesMalFormees;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\json;

/*
 * Aucune page ni aucune écriture de l'application ne répond 500 à une
 * requête mal formée (#2071).
 *
 * `GetStatsDashboardAction` passait `$request->query('period')` tel quel à
 * une méthode qui attend une chaîne : `?period[]=7j` répondait 500. La même
 * famille vivait ailleurs : chez des lecteurs que l'application ne change pas
 * (la pagination, que `OublieLesParametresIllisibles` borne ; ce qui recopie
 * une valeur hors UTF-8 dans du JSON, que `RefuseUneChaineDeRequeteHorsUtf8`
 * refuse), et dans des règles de validation qui laissaient une valeur
 * atteindre ce qui ne sait pas la lire (`current_password`, `after:today`).
 * Chaque lecture de la requête n'admet qu'une forme attendue
 * (`.ai/rules/controllers.md`) ; cette garde le vérifie de l'extérieur. Les
 * pages du panneau ont la leur (`UnePageDuPanneauNeRepondPas500Test`), pour
 * que les deux tournent en parallèle.
 *
 * Portée, pour les pages. Chaque route GET de l'application, hors panneau
 * (celles dont l'action vit hors de vendor/, comme `route:list
 * --except-vendor` les liste : pages, JSON du graphique d'un exercice, sonde)
 * est demandée une fois par forme : un tableau, une chaîne vide, une chaîne
 * énorme, un nombre hors bornes, un octet qui n'est pas de l'UTF-8. Chaque
 * requête porte, sous cette forme, tous les noms qu'elle peut lire : ceux que
 * le code de app/ et routes/ lit par une méthode de la requête (`query()`,
 * `input()`, `string()`…) et des noms courants (`period`, `page`,
 * `search`…). Un compte connecté visite les pages, un invité celles qui lui
 * sont réservées ; le compte a une ligne dans chaque liste paginée. Une page
 * Inertia est redemandée pour ses props différées, qui se calculent dans une
 * seconde requête : une lecture rangée dans leur fermeture y échapperait
 * sinon. Aucune réponse n'est une 500, aucune exception n'est rapportée (une
 * erreur rattrapée puis rapportée ne se voit pas au statut), et le JSON d'une
 * page Inertia se lit (sans lui, une page à 200 est blanche). La forme
 * « chaîne vide » sert de témoin : elle doit atteindre la page, sans quoi la
 * garde ne vérifierait qu'un refus d'accès ; seule une route signée répond
 * 403, sa signature couvrant la chaîne de requête.
 *
 * Portée, pour les écritures. Chaque route POST, PUT, PATCH ou DELETE de
 * l'application reçoit une fois par forme un corps JSON où chaque champ
 * qu'une requête de app/Http/Requests valide prend cette forme, sur des
 * lignes créées pour elle (une suppression ne prive pas la route suivante).
 * Le témoin est la première forme : ni 401, ni 404, ni 405, sans quoi la
 * route n'est pas atteinte (une suppression réussie rend ensuite 404 aux
 * formes suivantes, d'où la première).
 *
 * Limites. Les formes portent tous les noms à la fois : une validation qui
 * refuse l'un d'eux arrête la requête avant le contrôleur, et ce que le
 * contrôleur lit après la validation n'est essayé qu'avec des valeurs
 * validées. Seuls les noms ci-dessus sont essayés : un paramètre lu par une
 * clef calculée, ou d'un tableau reçu entier, ne l'est que s'il est parmi
 * les noms courants. Les rappels des fournisseurs de connexion ne sont pas
 * visités par un invité, qui appellerait le fournisseur. Les pages et les
 * routes des paquets (Pulse, Horizon, journaux, Livewire) ne sont pas
 * visitées.
 */

beforeEach(function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    Notification::fake();
    Mail::fake();
    Queue::fake();
});

it('aucune page de l’application ne répond 500 à une chaîne de requête mal formée', function (): void {
    $compte = User::factory()->create();
    RequetesMalFormees::remplirLesListes($compte);
    $routes = array_values(array_filter(RequetesMalFormees::routes(['GET']), static fn (Route $route): bool => ! RequetesMalFormees::duPanneau($route)));

    $visite = RequetesMalFormees::visiterChaquePage(
        $routes,
        RequetesMalFormees::valeursDeRoute($compte),
        static function (Route $route, string $url, array $entetes) use ($compte): TestResponse {
            Exceptions::fake();
            Auth::forgetGuards();

            return RequetesMalFormees::pourUnInvite($route)
                ? get($url, $entetes)
                : actingAs($compte)->get($url, $entetes);
        },
    );

    expect($visite['defauts'])->toBe([])
        ->and(count($routes))->toBeGreaterThanOrEqual(40)
        ->and($visite['requetes'])->toBeGreaterThanOrEqual(5 * count($routes));
});

it('aucune écriture de l’application ne répond 500 à un corps mal formé', function (): void {
    $compte = User::factory()->create();
    $champs = RequetesMalFormees::champsValides();
    $routes = array_values(array_filter(
        RequetesMalFormees::routes(['POST', 'PUT', 'PATCH', 'DELETE']),
        static fn (Route $route): bool => $route->getName() !== 'social.callback.apple',
    ));
    $defauts = [];

    foreach ($routes as $route) {
        $methode = array_values(array_diff($route->methods(), ['GET', 'HEAD']))[0];
        $adresse = RequetesMalFormees::adresse($route, RequetesMalFormees::valeursDeRoute($compte, neuve: true));
        $temoin = true;

        foreach (RequetesMalFormees::formesDeCorps() as $forme => $valeur) {
            $cas = "{$methode} {$route->uri()} ({$forme})";
            $corps = array_fill_keys($champs, $valeur);
            Exceptions::fake();
            Auth::forgetGuards();

            if (str_starts_with($route->uri(), 'api/')) {
                Sanctum::actingAs($compte, ['*']);
            } elseif (! RequetesMalFormees::pourUnInvite($route)) {
                actingAs($compte);
            }

            $reponse = json($methode, $adresse, $corps);
            $defauts = [...$defauts, ...RequetesMalFormees::defauts($reponse, $cas)];

            if ($temoin && in_array($reponse->getStatusCode(), [401, 404, 405], true)) {
                $defauts[] = "{$cas} : témoin à {$reponse->getStatusCode()}, la route n'est pas atteinte";
            }

            $temoin = false;
        }
    }

    expect($defauts)->toBe([])
        ->and(count($routes))->toBeGreaterThanOrEqual(60);
});
