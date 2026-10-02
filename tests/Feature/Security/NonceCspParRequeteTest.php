<?php

declare(strict_types=1);

use App\Http\Middleware\NonceCspParRequete;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Laravel\Horizon\Horizon;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\CurrentApplication;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Un nonce neuf à chaque requête, et le même partout dans la réponse (#1904).
 *
 * Le nonce était tiré une fois, dans `AppServiceProvider::boot()`. Sous Octane,
 * `boot()` ne tourne qu'au démarrage du worker, et `Vite` est un singleton que
 * `FlushVite` ne vide pas de son nonce : chaque worker servait donc le même
 * nonce à tous les utilisateurs jusqu'à son recyclage. Or ce nonce est la seule
 * barrière de `script-src` contre un script injecté en ligne ; lu dans le code
 * source de n'importe quelle page, il ouvrait toutes les autres.
 *
 * Deux requêtes dans le même test partagent une seule application, comme deux
 * requêtes servies par le même worker : c'est précisément le cas qui fautait.
 */
beforeEach(function (): void {
    config([
        'csp.enabled' => true,
        'csp.nonce_enabled' => true,
        'app.debug' => false,
    ]);
});

/**
 * Le nonce que la Content-Security-Policy de la réponse autorise.
 */
function nonceCspDeLEnTete(Response $reponse): string
{
    $politique = (string) $reponse->headers->get('Content-Security-Policy');

    if (preg_match("/'nonce-([^']+)'/", $politique, $trouve) !== 1) {
        Assert::fail('Aucun nonce dans la Content-Security-Policy : '.$politique);
    }

    return $trouve[1];
}

/**
 * Tous les nonces distincts que le corps de la réponse porte dans ses balises.
 *
 * @return list<string>
 */
function nonceCspDuCorps(Response $reponse): array
{
    preg_match_all('/\snonce="([^"]*)"/', (string) $reponse->getContent(), $trouves);

    return array_values(array_unique($trouves[1]));
}

/**
 * Le nonce que la CSP de spatie lit dans le conteneur pour écrire l'en-tête.
 */
function nonceCspDuConteneur(): ?string
{
    $nonce = app('csp-nonce');

    return is_string($nonce) ? $nonce : null;
}

it('tire un nonce neuf à chaque requête servie par la même application', function (): void {
    $premiere = get('/login')->assertOk()->baseResponse;
    $seconde = get('/login')->assertOk()->baseResponse;

    expect(nonceCspDeLEnTete($seconde))->not->toBe(nonceCspDeLEnTete($premiere));
});

it('signe chaque page du nonce de son propre en-tête, et de nul autre', function (): void {
    $premiere = get('/login')->assertOk()->baseResponse;
    $seconde = get('/login')->assertOk()->baseResponse;

    foreach ([[$premiere, $seconde], [$seconde, $premiere]] as [$reponse, $autre]) {
        $nonce = nonceCspDeLEnTete($reponse);
        $corps = (string) $reponse->getContent();

        /*
         * La meta que lit l'aide de préchargement de Vite, le script de
         * `@routes`, et le point d'entrée de `@vite` : les trois portent le
         * nonce de l'en-tête, aucun autre n'apparaît dans la page.
         */
        expect($corps)
            ->toContain('<meta property="csp-nonce" nonce="'.$nonce.'">')
            ->toContain('<script type="text/javascript" nonce="'.$nonce.'">')
            ->toMatch('/<script type="module" src="[^"]+" nonce="'.preg_quote($nonce, '/').'"/')
            ->and(nonceCspDuCorps($reponse))->toBe([$nonce]);
        expect($corps)->not->toContain(nonceCspDeLEnTete($autre));
    }
});

/**
 * La meta portait le nonce dans `content`, que rien ne lit : l'aide de
 * préchargement de Vite cherche `meta[property=csp-nonce]` et lit sa propriété
 * `nonce` ou son attribut `nonce`. Dans `content`, le nonce ne servait qu'à
 * être lu par un sélecteur CSS d'attribut, ce que le navigateur évite
 * justement en masquant l'attribut `nonce`.
 */
it('ne laisse pas le nonce dans un attribut content que le navigateur ne masque pas', function (): void {
    expect((string) get('/login')->assertOk()->getContent())->not->toContain('property="csp-nonce" content=');
});

it('tire aussi un nonce neuf à chaque page du panneau Filament', function (): void {
    $premiere = get('/backoffice/login')->assertOk()->baseResponse;
    $seconde = get('/backoffice/login')->assertOk()->baseResponse;

    $noncesDeLaPremiere = nonceCspDuCorps($premiere);
    $noncesDeLaSeconde = nonceCspDuCorps($seconde);

    expect($noncesDeLaPremiere)->toHaveCount(1)
        ->and($noncesDeLaSeconde)->toHaveCount(1)
        ->and($noncesDeLaSeconde)->not->toBe($noncesDeLaPremiere)
        ->and($noncesDeLaPremiere[0])->toHaveLength(40);
});

it('signe le script en ligne de Horizon du nonce de son en-tête', function (): void {
    config(['horizon.allowed_emails' => 'ops@example.org']);
    $autorise = User::factory()->create(['email' => 'ops@example.org']);

    $premiere = actingAs($autorise)->get('/horizon')->assertOk()->baseResponse;
    $seconde = actingAs($autorise)->get('/horizon')->assertOk()->baseResponse;

    foreach ([$premiere, $seconde] as $reponse) {
        expect((string) $reponse->getContent())->toContain('<script type="module" nonce="'.nonceCspDeLEnTete($reponse).'">');
    }

    expect(nonceCspDeLEnTete($seconde))->not->toBe(nonceCspDeLEnTete($premiere));
});

/**
 * Global, et non dans le groupe `web` : le panneau Filament, Pulse, Horizon et
 * les mises à jour de Livewire ont chacun leur pile, et toutes lisent le nonce.
 *
 * Un seul maillon passe devant : celui qu'Inertia ajoute en tête depuis son
 * fournisseur, au démarrage, après `bootstrap/app.php`. Il ne fait que marquer
 * les rappels différés d'une réponse 409, sans rien rendre ni lire de nonce.
 */
it('ouvre la pile globale, donc passe avant toute pile de route', function (): void {
    $noyau = app(\Illuminate\Contracts\Http\Kernel::class);

    expect($noyau)->toBeInstanceOf(\Illuminate\Foundation\Http\Kernel::class);
    /** @var \Illuminate\Foundation\Http\Kernel $noyau */
    $pileGlobale = $noyau->getGlobalMiddleware();
    $rang = array_search(NonceCspParRequete::class, $pileGlobale, true);

    expect($rang)->toBeInt()
        ->and(array_slice($pileGlobale, 0, (int) $rang))->toBe([\Inertia\Middleware\EnsureDeferredCallbacksRun::class]);
});

it('pose le nonce avant le reste de la pile, et le même pour Vite, la CSP et Horizon', function (): void {
    Vite::useCspNonce('nonce-de-la-requete-precedente');
    expect(nonceCspDuConteneur())->toBe('nonce-de-la-requete-precedente');

    $suiteAppelee = false;

    new NonceCspParRequete()->handle(Request::create('/login'), function () use (&$suiteAppelee): Response {
        $nonce = (string) Vite::cspNonce();

        expect($nonce)->toHaveLength(40)
            ->and(nonceCspDuConteneur())->toBe($nonce)
            ->and(Horizon::$nonceAttribute)->toBe(' nonce="'.$nonce.'"');
        expect($nonce)->not->toBe('nonce-de-la-requete-precedente');

        $suiteAppelee = true;

        return new Response();
    });

    expect($suiteAppelee)->toBeTrue();
});

/**
 * Le cas de production, rejoué : un worker Octane, son application démarrée une
 * seule fois, puis deux requêtes clonées depuis elle avec les écouteurs de
 * config/octane.php. Le worker démarre une seconde application dans le
 * processus de test, qui prend le conteneur global et la racine des façades :
 * le `finally` les rend à l'application du test.
 */
it('tire un nonce neuf à chaque requête d un vrai cycle de worker Octane', function (): void {
    $applicationDuTest = app();
    $client = new FakeClient([Request::create('/login'), Request::create('/login')]);
    $worker = new FakeWorker(new ApplicationFactory(base_path()), $client);

    try {
        $worker->boot();
        $worker->run();
    } finally {
        CurrentApplication::set($applicationDuTest);
    }

    $reponses = [];

    foreach (is_array($client->responses) ? $client->responses : [] as $reponse) {
        if ($reponse instanceof Response) {
            $reponses[] = $reponse;
        }
    }

    expect($client->errors)->toBe([])
        ->and($reponses)->toHaveCount(2);

    [$premiere, $seconde] = $reponses;

    expect(nonceCspDeLEnTete($seconde))->not->toBe(nonceCspDeLEnTete($premiere))
        ->and(nonceCspDuCorps($premiere))->toBe([nonceCspDeLEnTete($premiere)])
        ->and(nonceCspDuCorps($seconde))->toBe([nonceCspDeLEnTete($seconde)]);
});
