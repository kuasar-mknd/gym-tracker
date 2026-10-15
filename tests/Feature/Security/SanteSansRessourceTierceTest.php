<?php

declare(strict_types=1);

use App\Http\Controllers\SanteController;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Routing\Route as RouteLaravel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\get;
use function Pest\Laravel\withoutExceptionHandling;

/*
 * `/up` est ce que sondent le `HEALTHCHECK` de l'image et la composition de
 * production (`curl -f`), toutes les trente secondes, et le job `demarrage`
 * de la CI. La route de santé de Laravel y rendait une page HTML qui chargeait
 * une fonte et un script d'autres sites, sans Content-Security-Policy.
 * `SanteController` la remplace : même chemin, même évènement, même statut,
 * du JSON pour tout le monde, et rien d'autre à charger.
 */

/**
 * Le corps et les en-têtes que toute réponse de `/up` porte, saine ou non.
 *
 * @param  TestResponse<Response>  $reponse
 */
function santeSansTiersReponseSansRienACharger(TestResponse $reponse, string $etat): void
{
    $reponse->assertHeader('Content-Type', 'application/json')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; base-uri 'none'; form-action 'none'")
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeaderMissing('Set-Cookie');

    expect($reponse->getContent())->toBe('{"status":"'.$etat.'"}')
        ->and($reponse->headers->getCookies())->toBe([]);
}

/**
 * La réponse de `/up`, demandée avec cet en-tête `Accept`.
 *
 * @return TestResponse<Response>
 */
function santeSansTiersSonde(string $accept = '*/*'): TestResponse
{
    /** @var TestResponse<Response> $reponse */
    $reponse = get('/up', ['Accept' => $accept]);

    return $reponse;
}

/**
 * Un écouteur de `DiagnosingHealth` qui lève, comme le ferait un contrôle
 * branché sur le sondage.
 */
function santeSansTiersUnEcouteurLeve(RuntimeException $erreur): void
{
    Event::listen(DiagnosingHealth::class, static function () use ($erreur): never {
        throw $erreur;
    });
}

/**
 * La maintenance gardée par le pilote du cache, sur le magasin `array` : hors
 * du dossier storage, que les processus d'une suite parallèle partagent.
 */
function santeSansTiersMaintenanceHorsDuDisque(): void
{
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
}

dataset('sondes de santé', [
    'curl, la sonde des conteneurs' => ['*/*'],
    'un navigateur' => ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
    'un client qui demande du JSON' => ['application/json'],
]);

it('répond 200 et {"status":"up"}, sans HTML ni adresse d’un autre site, à toute sonde', function (string $accept): void {
    $reponse = santeSansTiersSonde($accept);
    $corps = (string) $reponse->getContent();

    $reponse->assertOk();

    expect($corps)->not->toContain('cdn.jsdelivr.net')
        ->and($corps)->not->toContain('fonts.bunny.net')
        ->and($corps)->not->toContain('//')
        ->and($corps)->not->toContain('<');

    santeSansTiersReponseSansRienACharger($reponse, 'up');
})->with('sondes de santé');

it('pose une politique qui n’autorise aucun script', function (): void {
    $politique = (string) santeSansTiersSonde()->headers->get('Content-Security-Policy');

    expect($politique)->toBe(SanteController::POLITIQUE)
        ->toStartWith("default-src 'none'")
        ->and($politique)->not->toContain('script-src')
        ->and($politique)->not->toContain('nonce-')
        ->and($politique)->not->toContain('unsafe');
});

it('ne passe par aucun groupe de routes : ni session, ni cookie, ni requête en base', function (): void {
    $route = Route::getRoutes()->match(request()->create('/up'));

    expect($route)->toBeInstanceOf(RouteLaravel::class)
        ->and($route->getActionName())->toBe(SanteController::class)
        ->and($route->gatherMiddleware())->toBe([]);

    $requetes = [];
    DB::listen(static function (QueryExecuted $requete) use (&$requetes): void {
        $requetes[] = $requete->sql;
    });

    santeSansTiersSonde()->assertOk()->assertHeaderMissing('Set-Cookie');
    santeSansTiersSonde()->assertOk()->assertHeaderMissing('Set-Cookie');

    expect($requetes)->toBe([]);
});

it('émet DiagnosingHealth une fois par sondage', function (): void {
    Event::fake([DiagnosingHealth::class]);

    santeSansTiersSonde()->assertOk();

    Event::assertDispatchedTimes(DiagnosingHealth::class, 1);
});

/*
 * Hors du mode debug, Laravel signalait l'exception et répondait 500 : le
 * conteneur passe pour malade, l'erreur part dans les journaux, et son message
 * ne sort pas dans la réponse.
 */
it('répond 500 et {"status":"down"} quand un écouteur de DiagnosingHealth lève, et signale l’exception', function (): void {
    config(['app.debug' => false]);
    Exceptions::fake();
    santeSansTiersUnEcouteurLeve(new RuntimeException('Redis injoignable'));

    $reponse = santeSansTiersSonde();

    $reponse->assertInternalServerError();
    santeSansTiersReponseSansRienACharger($reponse, 'down');
    expect((string) $reponse->getContent())->not->toContain('Redis injoignable');

    Exceptions::assertReported(static fn (RuntimeException $erreur): bool => $erreur->getMessage() === 'Redis injoignable');
});

/*
 * Laravel prenait le message de l'exception pour savoir si le sondage avait
 * échoué : une exception sans message répondait 200, application en panne.
 */
it('compte comme une panne une exception sans message', function (): void {
    config(['app.debug' => false]);
    Exceptions::fake();
    santeSansTiersUnEcouteurLeve(new RuntimeException());

    $reponse = santeSansTiersSonde();

    $reponse->assertInternalServerError();
    santeSansTiersReponseSansRienACharger($reponse, 'down');
    Exceptions::assertReported(RuntimeException::class);
});

it('relance l’exception en mode debug, comme Laravel', function (): void {
    config(['app.debug' => true]);
    santeSansTiersUnEcouteurLeve(new RuntimeException('Redis injoignable'));
    withoutExceptionHandling();

    expect(static fn (): TestResponse => santeSansTiersSonde())
        ->toThrow(new RuntimeException('Redis injoignable'));
});

/*
 * Laravel exclut sa route de santé de la maintenance : la sonde des
 * conteneurs ne doit pas tenir pour malade une application qu'on a mise en
 * maintenance.
 */
it('répond pendant une maintenance, quand les pages répondent 503', function (): void {
    santeSansTiersMaintenanceHorsDuDisque();
    app()->maintenanceMode()->activate([
        'except' => [],
        'redirect' => null,
        'retry' => null,
        'refresh' => null,
        'secret' => null,
        'status' => 503,
        'template' => null,
    ]);

    try {
        get('/login')->assertServiceUnavailable();
        santeSansTiersSonde()->assertOk()->assertExactJson(['status' => 'up']);
    } finally {
        app()->maintenanceMode()->deactivate();
    }
});

/*
 * `php artisan down --render` écrit une page que `public/index.php` sert avant
 * même de démarrer Laravel, sauf aux chemins que la commande a notés comme
 * exclus, qu'elle lit en console. Laravel notait `/up`, nous `up` : la page
 * écrite d'avance compare les chemins sans leurs barres obliques.
 */
it('reste exclue de la page de maintenance écrite d’avance', function (): void {
    santeSansTiersMaintenanceHorsDuDisque();

    try {
        expect(Artisan::call('down', ['--render' => 'errors::503']))->toBe(0);

        $exclus = app()->maintenanceMode()->data()['except'] ?? null;

        expect($exclus)->toBeArray()
            ->and(is_array($exclus) && (in_array('up', $exclus, true) || in_array('/up', $exclus, true)))->toBeTrue('`php artisan down --render` ne note pas `/up` parmi les chemins exclus.');
    } finally {
        Artisan::call('up');
    }
});

/*
 * La route de Laravel ne doit pas revenir à côté de la nôtre : déclarée avec
 * elle, elle serait enregistrée la première, puis remplacée, et rien ne
 * dirait laquelle sert `/up`. Le texte de bootstrap/app.php est lu sans ses
 * commentaires.
 */
it('ne déclare plus la route de santé de Laravel', function (): void {
    $jetons = array_values(array_filter(
        PhpToken::tokenize((string) file_get_contents(base_path('bootstrap/app.php'))),
        static fn (PhpToken $jeton): bool => ! $jeton->isIgnorable(),
    ));

    $argumentsNommes = [];

    foreach ($jetons as $position => $jeton) {
        if ($jeton->is(T_STRING) && ($jetons[$position + 1] ?? null)?->text === ':') {
            $argumentsNommes[] = $jeton->text;
        }
    }

    expect($argumentsNommes)->toContain('then')
        ->and($argumentsNommes)->not->toContain('health');
});
