<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Sante\AnnonceDeVersion;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/*
 * La sonde que la page demande quand le serveur ne répondait plus (#2048) :
 * `resources/js/Utils/sondeDuServeur.js` l'interroge au retour du réseau puis
 * de loin en loin, et seule une réponse 2xx réactive ce qui attendait le
 * serveur. Elle ne doit rien coûter ni rien dire d'un compte : hors des
 * groupes `web` et `api`, sans session, sans base, sans cache.
 */

/**
 * `GET /sonde`, comme la page la demande, au nom de `$compte` s'il est donné.
 *
 * @return TestResponse<Response>
 */
function sondeDuServeurDemandee(?User $compte = null): TestResponse
{
    $test = $compte === null ? test() : test()->actingAs($compte);

    /** @var TestResponse<Response> $reponse */
    $reponse = $test->get('/sonde');

    return $reponse;
}

it('répond 204, vide, sans cache et sans cookie', function (): void {
    $reponse = sondeDuServeurDemandee();

    $reponse->assertNoContent();

    expect($reponse->getContent())->toBe('')
        ->and($reponse->headers->get('Cache-Control'))->toContain('no-store')
        ->and($reponse->headers->has('Set-Cookie'))->toBeFalse();
});

it('ne lit ni la base ni le cache, et ne réannonce pas la version comme /up', function (): void {
    $requetes = 0;
    DB::listen(function () use (&$requetes): void {
        $requetes++;
    });
    $operationsDeCache = 0;
    Event::listen('Illuminate\\Cache\\Events\\*', function () use (&$operationsDeCache): void {
        $operationsDeCache++;
    });

    sondeDuServeurDemandee()->assertNoContent();

    expect($requetes)->toBe(0)
        ->and($operationsDeCache)->toBe(0)
        ->and(AnnonceDeVersion::lue('app'))->toBeNull();

    // Le contraste qui a écarté `/up` : chaque sondage y écrit dans le cache.
    $operationsDeCache = 0;
    $this->get('/up')->assertOk();

    expect($operationsDeCache)->toBeGreaterThan(0)
        ->and(AnnonceDeVersion::lue('app'))->toBeArray();
});

it('passe hors des groupes web et api : ni session, ni jeton, ni limite de débit', function (): void {
    $route = Route::getRoutes()->getByName('sonde');

    expect($route)->not->toBeNull()
        ->and($route?->methods())->toContain('GET')
        ->and($route?->gatherMiddleware())->toBe([]);
});

it('répond de même à un compte connecté, sans rien dire de lui', function (): void {
    $compte = User::factory()->create(['name' => 'Titulaire de la sonde']);

    $reponse = sondeDuServeurDemandee($compte);

    $reponse->assertNoContent();

    expect($reponse->getContent())->toBe('')
        ->and($reponse->headers->has('Set-Cookie'))->toBeFalse()
        ->and($reponse->headers->__toString())->not->toContain('Titulaire de la sonde');
});

/*
 * `/up` répond pendant une maintenance, quand aucune page ne répond : la
 * page aurait réactivé des gestes que le serveur aurait refusés.
 *
 * La maintenance reste propre à ce processus (pilote `array`) : le pilote
 * `file` de phpunit.xml écrirait `storage/framework/down`, que tous les
 * processus d'une suite parallèle partagent, et chaque requête d'un test
 * voisin répondrait 503 le temps de ce cas (`.ai/rules/tests.md`).
 */
it('ne répond pas 2xx pendant une maintenance, contrairement à /up', function (): void {
    Config::set('app.maintenance.driver', 'array');
    app()->maintenanceMode()->activate([]);

    try {
        expect(is_file(storage_path('framework/down')))->toBeFalse();

        sondeDuServeurDemandee()->assertServiceUnavailable();
        $this->get('/up')->assertOk();
    } finally {
        app()->maintenanceMode()->deactivate();
    }
});

it('est l’adresse que demande la page', function (): void {
    $source = (string) file_get_contents(resource_path('js/Utils/sondeDuServeur.js'));

    expect(preg_match("/export const ADRESSE_DE_LA_SONDE = '([^']+)'/", $source, $correspondance))
        ->toBe(1, '`ADRESSE_DE_LA_SONDE` est introuvable dans le module de la sonde : si sa forme a changé, ce contrôle doit suivre.');

    expect($correspondance[1] ?? null)->toBe(route('sonde', absolute: false));
});

it('est permise aux pages par connect-src', function (): void {
    Config::set('csp.enabled', true);

    /** @var TestResponse<Response> $page */
    $page = $this->actingAs(User::factory()->create())->get('/dashboard');
    $politique = (string) $page->headers->get('Content-Security-Policy');

    expect(preg_match('/(?:^|;)\s*connect-src ([^;]+)/', $politique, $connect))->toBe(1)
        ->and(explode(' ', trim($connect[1] ?? '')))->toContain("'self'");
});
