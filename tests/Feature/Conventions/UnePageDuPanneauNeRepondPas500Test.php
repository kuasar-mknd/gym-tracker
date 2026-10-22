<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Support\EntetesLus;
use Tests\Support\RequetesMalFormees;
use Tests\Support\SacDEntetesEspionne;

use function Pest\Laravel\actingAs;

/*
 * Aucune page du panneau ne répond 500 à une chaîne de requête mal formée
 * (#2071) : la moitié de `UneRequeteMalFormeeNeRepondPas500Test` pour les
 * pages du panneau, dont les classes vivent dans app/Filament, séparée pour
 * tourner en parallèle (une page du panneau coûte quatre fois une page
 * Inertia). Même portée, mêmes formes de chaîne de requête, mêmes limites.
 * Les tables de Filament lisaient `search` par `strval()` (un tableau
 * répondait 500), Livewire recopie dans l'état du composant chaque nom
 * qu'il lie à la chaîne de requête (un octet hors UTF-8 répondait 500), et
 * la pagination de Livewire prenait toute page : ces noms (`#[Url]`, sous
 * leur alias) s'ajoutent à ceux que la garde essaie. Un administrateur qui
 * ouvre chaque page les visite ; le limiteur du panneau est remis à zéro
 * avant chaque requête. Le corps JSON n'y est pas essayé : Livewire lit
 * l'état lié à l'adresse, page des tables comprise, dans la chaîne de
 * requête seule (`request()->query()`), et l'essai, sans défaut à trouver
 * sur l'ancien maillon, coûtait un quart de la garde.
 *
 * Les en-têtes que l'application lit (#2094) : la part du panneau de
 * `UnEnTeteMalFormeNeRepondPas500Test`, mêmes formes, même recensement.
 * Chaque page du panneau qu'une lecture atteint (aujourd'hui la marque du
 * préchauffage, que lit `OuvreLaSession`, dans la pile du panneau) est
 * demandée sans l'en-tête, puis sous chaque valeur inattendue, sous chaque
 * méthode que l'en-tête déclare (GET et HEAD pour la marque) : aucune 500,
 * aucune exception rapportée, la même réponse que sans l'en-tête, ou le
 * refus que l'en-tête déclare, et la lecture atteinte
 * (`SacDEntetesEspionne`).
 */

it('aucune page du panneau ne répond 500 à une chaîne de requête mal formée', function (): void {
    $routes = array_values(array_filter(RequetesMalFormees::routes(['GET']), RequetesMalFormees::duPanneau(...)));
    $administrateur = RequetesMalFormees::administrateur($routes);

    $visite = RequetesMalFormees::visiterChaquePage(
        $routes,
        RequetesMalFormees::valeursDeRoute(User::factory()->create()),
        static function (Route $route, string $url, array $entetes, ?string $corps) use ($administrateur): TestResponse {
            Exceptions::fake();
            RateLimiter::clear('admin-panel:127.0.0.1');

            return actingAs($administrateur, 'admin')->call('GET', $url, [], [], [], RequetesMalFormees::serveur($entetes, $corps), $corps);
        },
        corpsJson: false,
    );

    expect($visite['defauts'])->toBe([])
        ->and(count($routes))->toBeGreaterThanOrEqual(20)
        ->and($visite['requetes'])->toBe(count(RequetesMalFormees::formesDeChaine()) * count($routes));
});

it('aucune page du panneau ne répond 500 à une valeur inattendue d’un en-tête qu’elle lit', function (): void {
    $lus = new ArrayObject();
    SacDEntetesEspionne::poser(app(), $lus);
    $lisent = EntetesLus::routesQuiLisent();
    $routes = array_values(array_filter(RequetesMalFormees::routes(['GET']), RequetesMalFormees::duPanneau(...)));
    $administrateur = RequetesMalFormees::administrateur($routes);
    $valeurs = RequetesMalFormees::valeursDeRoute(User::factory()->create());
    $compte = User::factory()->create();
    $defauts = [];
    $essais = [];

    foreach ($lisent as $entete => $parRoute) {
        ['attendue' => $attendue, 'refus' => $refus, 'methodes' => $methodesLues] = EntetesLus::recenses()[$entete];

        foreach ($parRoute as ['route' => $route]) {
            if (! RequetesMalFormees::duPanneau($route)) {
                continue;
            }

            $adresse = RequetesMalFormees::adresse($route, $valeurs);
            $formes = EntetesLus::formes($attendue($compte));

            foreach ($methodesLues ?? ['GET'] as $methode) {
                $visiter = static function (?string $forme) use ($administrateur, $adresse, $entete, $formes, $lus, $methode): TestResponse {
                    Exceptions::fake();
                    RateLimiter::clear('admin-panel:127.0.0.1');
                    $entetes = $forme === null ? [] : [$entete => $formes[$forme]];
                    $lus->exchangeArray([]);

                    return actingAs($administrateur, 'admin')->call($methode, $adresse, [], [], [], RequetesMalFormees::serveur($entetes, null));
                };
                $lu = static fn (): bool => in_array(strtolower($entete), $lus->getArrayCopy(), true);
                $cas = "{$methode} {$route->uri()}, {$entete}";
                $temoin = $visiter(null);
                $defauts = [...$defauts, ...RequetesMalFormees::defauts($temoin, "{$cas} (témoin)")];

                if (! RequetesMalFormees::temoinAtteint($route, $temoin->getStatusCode())) {
                    $defauts[] = "{$cas} (témoin) : {$temoin->getStatusCode()}, la page n'est pas atteinte";
                }

                if (! $lu()) {
                    $defauts[] = "{$cas} (témoin) : la lecture de l'en-tête n'est pas atteinte";
                }

                foreach (array_keys($formes) as $forme) {
                    $reponse = $visiter($forme);
                    $essais[$methode] = ($essais[$methode] ?? 0) + 1;
                    $defauts = [...$defauts, ...RequetesMalFormees::defauts($reponse, "{$cas} ({$forme})")];

                    if ($reponse->getStatusCode() !== $temoin->getStatusCode() && $reponse->getStatusCode() !== $refus) {
                        $defauts[] = "{$cas} ({$forme}) : {$reponse->getStatusCode()}, ni comme sans l'en-tête ({$temoin->getStatusCode()}) ni par son refus";
                    }

                    if (! $lu()) {
                        $defauts[] = "{$cas} ({$forme}) : la lecture de l'en-tête n'est pas atteinte";
                    }
                }
            }
        }
    }

    expect($defauts)->toBe([])
        ->and($essais['GET'] ?? 0)->toBeGreaterThanOrEqual(20 * count(EntetesLus::formes('valeur')))
        ->and($essais['HEAD'] ?? 0)->toBe($essais['GET'] ?? 0);
});
