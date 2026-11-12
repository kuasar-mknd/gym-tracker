<?php

declare(strict_types=1);

use App\Enums\PorteeDeDiagnostic;
use App\Http\Middleware\ExigeUnJetonDeDiagnostic;
use App\Models\Admin;
use App\Models\ErreurNavigateur;
use App\Models\JetonDeDiagnostic;
use App\Models\User;
use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Services\Diagnostic\Projections\LecturesBornees;
use Illuminate\Database\QueryException;
use Illuminate\Http\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\NullHandler;
use Spatie\Permission\Models\Role;
use Tests\Support\FilamentAdminPanel;

use function Pest\Laravel\get;

/*
 * Les routes des erreurs navigateur de l'accès de diagnostic (#2032), sous la
 * portée `ErreursNavigateur` : la liste et le détail d'un groupe, en lecture
 * seule. Les valeurs plantées dans l'URL, le message, la pile et l'agent
 * (adresse, IP, jeton, secret, compte dans l'URL) se composent à
 * l'exécution et ne doivent sortir que par `non_fiable`, expurgées et
 * réduites, sous `TexteBrut` seulement.
 */
const ROUTE_ERREURS_IP = '203.0.113.88';
const ROUTE_ERREURS_ADRESSE = 'camille.canari@example.org';
const ROUTE_ERREURS_SECRET = 'sk_live_canari_0123456789abcdef';
const ROUTE_ERREURS_AGENT = 'AgentCanari/9.9';

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 10:00:00');
    config()->set('app.diagnostic_enabled', true);
    config()->set('health.notifications.mail.to', 'alertes@example.org');
    config()->set('logging.channels.diagnostic', ['driver' => 'monolog', 'handler' => NullHandler::class]);
    config()->set('logging.channels.diagnostic_refus', ['driver' => 'monolog', 'handler' => NullHandler::class]);
    Cache::flush();
    Notification::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Un jeton actif, créé par un super administrateur qui a la capacité.
 *
 * @param  list<string>  $portees
 * @return array{jeton: JetonDeDiagnostic, entete: string}
 */
function routeErreursUnJeton(array $portees = ['erreurs-navigateur']): array
{
    $admin = FilamentAdminPanel::admin(['view-diagnostic']);
    $admin->assignRole(Role::findOrCreate(Admin::roleDuSuperAdministrateur(), 'admin'));
    $identifiant = Str::lower(Str::random(JetonDeDiagnostic::LONGUEUR_DE_L_IDENTIFIANT));
    $secret = Str::random(JetonDeDiagnostic::LONGUEUR_DU_SECRET);

    $jeton = JetonDeDiagnostic::factory()->create([
        'identifiant' => $identifiant,
        'empreinte' => JetonDeDiagnostic::empreinteDe($secret),
        'portees' => $portees,
        'cree_par_admin_id' => $admin->id,
    ]);

    return ['jeton' => $jeton, 'entete' => 'Bearer '.JetonDeDiagnostic::composer($identifiant, $secret)];
}

/**
 * @return TestResponse<Response>
 */
function routeErreursAppeler(string $uri, ?string $entete): TestResponse
{
    return get($uri, $entete === null ? ['Accept' => 'application/json'] : ['Accept' => 'application/json', 'Authorization' => $entete]);
}

/**
 * Une erreur navigateur rapportée par un compte, écrite sans passer par la
 * route de collecte.
 *
 * @param  array<string, mixed>  $colonnes
 */
function routeErreursPlanter(string $graine = 'ordinaire', ?User $compte = null, array $colonnes = []): int
{
    return (int) DB::table('erreurs_navigateur')->insertGetId([
        'user_id' => ($compte ?? User::factory()->create())->id,
        'type' => 'error',
        'message' => 'boum',
        'source' => null,
        'ligne' => 12,
        'colonne' => 3,
        'pile' => "at a (https://gym.example.org/build/assets/app.js:1:2)\nat b",
        'url' => 'https://gym.example.org/seances/3',
        'agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0',
        'empreinte' => md5($graine),
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(3),
        ...$colonnes,
    ]);
}

/**
 * Un groupe dont chaque champ de texte porte une valeur sensible : une page
 * qui porte un compte, un courriel et un jeton dans son chemin, sa requête et
 * son fragment, un message et une pile qui citent des adresses à requête.
 */
function routeErreursPlanterLesCanaris(): string
{
    $jeton = JetonDeDiagnostic::composer(Str::lower(Str::random(JetonDeDiagnostic::LONGUEUR_DE_L_IDENTIFIANT)), Str::random(JetonDeDiagnostic::LONGUEUR_DU_SECRET));
    routeErreursPlanter('canari', colonnes: [
        'message' => 'échec pour '.ROUTE_ERREURS_ADRESSE.' depuis '.ROUTE_ERREURS_IP.' avec Bearer '.$jeton.' et Bearer '.ROUTE_ERREURS_SECRET,
        'url' => sprintf('https://%s@gym.example.org:8443/reinit/', 'camille:'.ROUTE_ERREURS_SECRET).rawurlencode(ROUTE_ERREURS_ADRESSE).'/12/'.$jeton.'?token='.ROUTE_ERREURS_SECRET.'&mail='.ROUTE_ERREURS_ADRESSE.'#'.ROUTE_ERREURS_SECRET,
        'pile' => "Error: boum\n    at https://gym.example.org/build/assets/app.js?token=".ROUTE_ERREURS_SECRET.":1:2\n    at fetch(https://gym.example.org/api?mail=".ROUTE_ERREURS_ADRESSE.')',
        'agent' => 'Mozilla/5.0 '.ROUTE_ERREURS_AGENT,
    ]);

    return md5('canari');
}

/**
 * @return list<string>
 */
function routeErreursLesCanaris(): array
{
    return [ROUTE_ERREURS_IP, ROUTE_ERREURS_ADRESSE, ROUTE_ERREURS_SECRET, ROUTE_ERREURS_AGENT, 'canari', 'gtdiag_', 'AgentCanari', '8443', 'camille', 'token=', '#'.ROUTE_ERREURS_SECRET];
}

/**
 * Un tableau lu d'un JSON, vide si la valeur n'en est pas un.
 *
 * @return array<array-key, mixed>
 */
function routeErreursTableau(mixed $valeur): array
{
    return is_array($valeur) ? $valeur : [];
}

/**
 * L'adresse de la route désignée, un groupe planté avec ses canaris.
 */
function routeErreursAdresse(string $route): string
{
    $empreinte = routeErreursPlanterLesCanaris();

    return $route === 'la liste' ? '/diagnostic/v1/erreurs-navigateur' : '/diagnostic/v1/erreurs-navigateur/'.$empreinte;
}

dataset('routes des erreurs navigateur', ['la liste', 'le détail']);

it('rend de la liste exactement ces clés, sans clé non_fiable, ni compte, ni URL, ni agent', function (): void {
    routeErreursPlanterLesCanaris();
    $cree = routeErreursUnJeton(['erreurs-navigateur', 'texte-brut']);

    $reponse = routeErreursAppeler('/diagnostic/v1/erreurs-navigateur', $cree['entete'])->assertOk();

    expect(array_keys(routeErreursTableau($reponse->json())))->toBe(['donnees', 'suivant', 'tronque', 'echantillon', 'genere_le', 'note'])
        ->and(array_keys(routeErreursTableau($reponse->json('donnees.erreurs_navigateur'))))->toBe(['groupes', 'lignes_lues', 'echantillon', 'tronque'])
        ->and(array_keys(routeErreursTableau($reponse->json('donnees.erreurs_navigateur.groupes.0'))))->toBe([
            'empreinte', 'type', 'comptes_touches', 'navigateurs', 'fichier', 'ligne', 'colonne', 'nombre', 'premiere_le', 'derniere_le', 'dernier_id',
        ])
        ->and($reponse->json('donnees.erreurs_navigateur.groupes.0'))->toMatchArray([
            'empreinte' => md5('canari'), 'type' => 'error', 'comptes_touches' => 1, 'navigateurs' => ['autre'], 'nombre' => 1, 'ligne' => 12, 'colonne' => 3,
        ]);
});

it('rend du détail exactement ces clés sans TexteBrut : la clé non_fiable n’existe pas', function (): void {
    $empreinte = routeErreursPlanterLesCanaris();
    $cree = routeErreursUnJeton();

    $reponse = routeErreursAppeler('/diagnostic/v1/erreurs-navigateur/'.$empreinte, $cree['entete'])->assertOk();

    expect(array_keys(routeErreursTableau($reponse->json('donnees.erreur_navigateur'))))->toBe([
        'empreinte', 'type', 'comptes_touches', 'navigateurs', 'fichier', 'ligne', 'colonne', 'nombre', 'premiere_le', 'derniere_le', 'dernier_id', 'echantillon',
    ])
        ->and((string) $reponse->getContent())->not->toContain('"non_fiable":');
});

it('rend sous TexteBrut le message, la page réduite et la pile, expurgés, sans compte, requête ni fragment', function (): void {
    $empreinte = routeErreursPlanterLesCanaris();
    $cree = routeErreursUnJeton(['erreurs-navigateur', 'texte-brut']);

    $reponse = routeErreursAppeler('/diagnostic/v1/erreurs-navigateur/'.$empreinte, $cree['entete'])->assertOk();
    $contenu = (string) $reponse->getContent();

    expect(array_keys(routeErreursTableau($reponse->json('donnees.erreur_navigateur.non_fiable'))))->toBe(['message', 'page', 'pile'])
        ->and($reponse->json('donnees.erreur_navigateur.non_fiable.message'))->toContain('échec pour [courriel]')
        ->and($reponse->json('donnees.erreur_navigateur.non_fiable.page'))->toStartWith('//[hôte]/reinit/[segment]/[n]/')
        ->and($reponse->json('donnees.erreur_navigateur.non_fiable.page'))->not->toContain('?')
        ->and($reponse->json('donnees.erreur_navigateur.non_fiable.pile'))->toContain('?[requête]');

    foreach (routeErreursLesCanaris() as $canari) {
        expect($contenu)->not->toContain($canari);
    }
});

it('ne laisse sortir ni adresse, ni IP, ni jeton, ni secret, ni agent planté, sur aucune route, sans TexteBrut', function (string $route): void {
    $uri = routeErreursAdresse($route);
    $cree = routeErreursUnJeton();

    $contenu = (string) routeErreursAppeler($uri, $cree['entete'])->assertOk()->getContent();

    foreach (routeErreursLesCanaris() as $canari) {
        expect($contenu)->not->toContain($canari);
    }
})->with('routes des erreurs navigateur');

it('ne laisse sortir aucun canari non plus sous TexteBrut, sur aucune route', function (string $route): void {
    $uri = routeErreursAdresse($route);
    $cree = routeErreursUnJeton(['erreurs-navigateur', 'texte-brut']);

    $contenu = (string) routeErreursAppeler($uri, $cree['entete'])->assertOk()->getContent();

    foreach (routeErreursLesCanaris() as $canari) {
        expect($contenu)->not->toContain($canari);
    }
})->with('routes des erreurs navigateur');

it('n’expurge pas la liste d’un texte qu’elle ne lit pas, même sous TexteBrut', function (): void {
    routeErreursPlanterLesCanaris();
    $cree = routeErreursUnJeton(['erreurs-navigateur', 'texte-brut']);

    $contenu = (string) routeErreursAppeler('/diagnostic/v1/erreurs-navigateur', $cree['entete'])->assertOk()->getContent();

    expect($contenu)->not->toContain('"non_fiable":')
        ->and($contenu)->not->toContain('échec pour');
});

it('refuse en 403 portee-non-accordee chaque route à un jeton sans la portée ErreursNavigateur, et la sert à qui l’a', function (string $route): void {
    $uri = routeErreursAdresse($route);
    $sans = routeErreursUnJeton(['sante', 'exceptions', 'files', 'texte-brut']);
    $avec = routeErreursUnJeton();

    routeErreursAppeler($uri, $sans['entete'])->assertForbidden()
        ->assertExactJson(['erreur' => 'portee-non-accordee', 'note' => EnveloppeDeDiagnostic::NOTE]);
    routeErreursAppeler($uri, $avec['entete'])->assertOk();
})->with('routes des erreurs navigateur');

it('répond sans jeton, ou au jeton faux, comme à un chemin inconnu, la route existant pour un jeton sans portée', function (string $route): void {
    $uri = routeErreursAdresse($route);
    $sansPortee = routeErreursUnJeton(['exceptions']);
    $cree = routeErreursUnJeton();
    $faux = 'Bearer '.JetonDeDiagnostic::composer(Str::lower(Str::random(JetonDeDiagnostic::LONGUEUR_DE_L_IDENTIFIANT)), Str::random(JetonDeDiagnostic::LONGUEUR_DU_SECRET));
    $tout = static function (TestResponse $reponse): array {
        $entetes = $reponse->headers->allPreserveCase();
        unset($entetes['Date'], $entetes['date']);
        ksort($entetes);

        return [$reponse->getStatusCode(), (string) $reponse->getContent(), $entetes];
    };

    $inconnu = $tout(routeErreursAppeler('/diagnostic/v1/ce-chemin-n-existe-pas', null));

    expect($inconnu[0])->toBe(404)
        ->and($tout(routeErreursAppeler($uri, null)))->toBe($inconnu)
        ->and($tout(routeErreursAppeler($uri, $faux)))->toBe($inconnu)
        ->and(routeErreursAppeler($uri, $sansPortee['entete'])->getStatusCode())->toBe(403);
    expect($cree['jeton']->fresh()?->derniere_utilisation_le)->toBeNull();
})->with('routes des erreurs navigateur');

it('ne route pas une empreinte qui n’a pas la forme : même 404 qu’un chemin inconnu', function (string $empreinte): void {
    $cree = routeErreursUnJeton();

    $inconnu = (string) routeErreursAppeler('/diagnostic/v1/ce-chemin-n-existe-pas', $cree['entete'])->getContent();
    $reponse = routeErreursAppeler('/diagnostic/v1/erreurs-navigateur/'.$empreinte, $cree['entete']);

    expect($reponse->getStatusCode())->toBe(404)
        ->and((string) $reponse->getContent())->toBe($inconnu);
})->with([
    'majuscules' => strtoupper(md5('a')),
    'trop courte' => substr(md5('a'), 0, 31),
    'trop longue' => md5('a').'0',
    'pas de l’hexadécimal' => 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz',
    'un nombre' => '12',
    'un chemin' => '..%2F..%2Fetc',
]);

it('rend 404 introuvable pour une empreinte inconnue, après un jeton valide seulement, le même corps pour toutes', function (): void {
    routeErreursPlanter();
    $cree = routeErreursUnJeton();

    foreach ([md5('inconnue'), str_repeat('0', 32)] as $empreinte) {
        routeErreursAppeler('/diagnostic/v1/erreurs-navigateur/'.$empreinte, $cree['entete'])->assertNotFound()
            ->assertExactJson(['erreur' => 'introuvable', 'note' => EnveloppeDeDiagnostic::NOTE]);
    }

    expect(routeErreursAppeler('/diagnostic/v1/erreurs-navigateur/'.md5('inconnue'), null)->getStatusCode())->toBe(404)
        ->and((string) routeErreursAppeler('/diagnostic/v1/erreurs-navigateur/'.md5('inconnue'), null)->getContent())->not->toContain('introuvable');
});

it('ne rend jamais plus de 50 groupes, et le dit', function (): void {
    $compte = User::factory()->create();
    foreach (range(1, 55) as $numero) {
        routeErreursPlanter('groupe-'.$numero, $compte);
    }
    $cree = routeErreursUnJeton();

    $reponse = routeErreursAppeler('/diagnostic/v1/erreurs-navigateur', $cree['entete'])->assertOk();

    expect($reponse->json('donnees.erreurs_navigateur.groupes'))->toHaveCount(50)
        ->and($reponse->json('tronque'))->toBeTrue()
        ->and($reponse->json('donnees.erreurs_navigateur.tronque'))->toBeTrue();
});

it('ne lit que les 5 000 dernières lignes, signale l’échantillon, à la liste comme au détail', function (): void {
    $compte = User::factory()->create();
    $maintenant = now()->subHour();
    $ligne = [
        'user_id' => $compte->id, 'type' => 'error', 'message' => 'm', 'url' => '/', 'empreinte' => md5('debordement'),
        'created_at' => $maintenant, 'updated_at' => $maintenant,
    ];
    foreach (array_chunk(array_fill(0, 5_001, $ligne), 1_000) as $lot) {
        DB::table('erreurs_navigateur')->insert($lot);
    }
    $cree = routeErreursUnJeton();

    $liste = routeErreursAppeler('/diagnostic/v1/erreurs-navigateur', $cree['entete'])->assertOk();
    $detail = routeErreursAppeler('/diagnostic/v1/erreurs-navigateur/'.md5('debordement'), $cree['entete'])->assertOk();

    expect($liste->json('echantillon'))->toBeTrue()
        ->and($liste->json('donnees.erreurs_navigateur.lignes_lues'))->toBe(5_000)
        ->and($liste->json('donnees.erreurs_navigateur.groupes.0.nombre'))->toBe(5_000)
        ->and($detail->json('echantillon'))->toBeTrue()
        ->and($detail->json('donnees.erreur_navigateur.echantillon'))->toBeTrue()
        ->and($detail->json('donnees.erreur_navigateur.nombre'))->toBe(5_000);
});

it('lit vingt-quatre heures à la liste comme au détail, et jamais les comptes non vérifiés', function (): void {
    $verifie = User::factory()->create();
    $nonVerifie = User::factory()->unverified()->create();
    routeErreursPlanter('ancienne', $verifie, ['created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)]);
    routeErreursPlanter('recente', $verifie);
    routeErreursPlanter('fantome', $nonVerifie);
    $cree = routeErreursUnJeton();

    $liste = routeErreursAppeler('/diagnostic/v1/erreurs-navigateur', $cree['entete'])->assertOk();

    expect(array_column(routeErreursTableau($liste->json('donnees.erreurs_navigateur.groupes')), 'empreinte'))->toBe([md5('recente')]);
    routeErreursAppeler('/diagnostic/v1/erreurs-navigateur/'.md5('ancienne'), $cree['entete'])->assertNotFound();
    routeErreursAppeler('/diagnostic/v1/erreurs-navigateur/'.md5('recente'), $cree['entete'])->assertOk();
    routeErreursAppeler('/diagnostic/v1/erreurs-navigateur/'.md5('fantome'), $cree['entete'])->assertNotFound();
});

it('tient la frontière des vingt-quatre heures, à la liste comme au détail', function (): void {
    $compte = User::factory()->create();
    routeErreursPlanter('vingt-cinq', $compte, ['created_at' => now()->subHours(25), 'updated_at' => now()->subHours(25)]);
    routeErreursPlanter('vingt-trois', $compte, ['created_at' => now()->subHours(23), 'updated_at' => now()->subHours(23)]);
    $cree = routeErreursUnJeton();

    $liste = routeErreursAppeler('/diagnostic/v1/erreurs-navigateur', $cree['entete'])->assertOk();

    expect(array_column(routeErreursTableau($liste->json('donnees.erreurs_navigateur.groupes')), 'empreinte'))->toBe([md5('vingt-trois')]);
    routeErreursAppeler('/diagnostic/v1/erreurs-navigateur/'.md5('vingt-cinq'), $cree['entete'])->assertNotFound();
    routeErreursAppeler('/diagnostic/v1/erreurs-navigateur/'.md5('vingt-trois'), $cree['entete'])->assertOk();
});

it('rend « [chemin][n] », une fois expurgée, une page sans hôte', function (): void {
    routeErreursPlanter('sans-hote', colonnes: ['url' => '/seances/3?x=1']);
    $cree = routeErreursUnJeton(['erreurs-navigateur', 'texte-brut']);

    $reponse = routeErreursAppeler('/diagnostic/v1/erreurs-navigateur/'.md5('sans-hote'), $cree['entete'])->assertOk();

    expect($reponse->json('donnees.erreur_navigateur.non_fiable.page'))->toBe('[chemin][n]');
});

it('n’écrit rien en base pour une lecture, hors la trace d’usage du jeton, et borne chaque lecture de la table', function (string $route): void {
    $uri = routeErreursAdresse($route);
    $cree = routeErreursUnJeton(['erreurs-navigateur', 'texte-brut']);
    $requetes = [];
    DB::listen(static function ($requete) use (&$requetes): void {
        $requetes[] = $requete->sql;
    });

    routeErreursAppeler($uri, $cree['entete'])->assertOk();

    $ecritures = array_values(array_filter($requetes, static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate|lock)\b/i', $sql) === 1));
    $lectures = array_values(array_filter($requetes, static fn (string $sql): bool => str_contains($sql, 'erreurs_navigateur')));

    expect($ecritures)->toHaveCount(1)
        ->and($ecritures[0])->toStartWith('update `jetons_de_diagnostic` set `derniere_utilisation_le`')
        ->and($lectures)->not->toBe([]);
    foreach ($lectures as $sql) {
        expect($sql)->toStartWith('select /*+ MAX_EXECUTION_TIME('.LecturesBornees::MILLISECONDES_AU_PLUS.') */')
            ->and($sql)->not->toContain('select *')
            ->and($sql)->toMatch('/ limit \d+$/');
    }
})->with('routes des erreurs navigateur');

it('rend 503 lecture-trop-longue quand la base coupe une lecture à la borne de temps, après un jeton valide', function (string $uri): void {
    routeErreursPlanter();
    $cree = routeErreursUnJeton();
    DB::beforeExecuting(static function (string $sql): void {
        if (str_contains($sql, 'erreurs_navigateur')) {
            $erreur = new PDOException('Query execution was interrupted, maximum statement execution time exceeded');
            $erreur->errorInfo = ['HY000', LecturesBornees::ERREUR_DE_TEMPS_ECOULE, 'Query execution was interrupted, maximum statement execution time exceeded'];

            throw new QueryException('mysql', $sql, [], $erreur);
        }
    });

    $reponse = routeErreursAppeler($uri, $cree['entete']);
    $sansJeton = routeErreursAppeler($uri, null);

    $reponse->assertStatus(503)
        ->assertExactJson(['erreur' => 'lecture-trop-longue', 'note' => EnveloppeDeDiagnostic::NOTE])
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex');
    expect($sansJeton->getStatusCode())->toBe(404);
})->with(['/diagnostic/v1/erreurs-navigateur', '/diagnostic/v1/erreurs-navigateur/'.'0123456789abcdef0123456789abcdef']);

it('rend une 500 sans détail pour toute autre erreur de la base', function (): void {
    routeErreursPlanter();
    $cree = routeErreursUnJeton();
    DB::beforeExecuting(static function (string $sql): void {
        if (str_contains($sql, 'erreurs_navigateur')) {
            $erreur = new PDOException('table perdue');
            $erreur->errorInfo = ['42S02', 1146, 'table perdue'];

            throw new QueryException('mysql', $sql, [], $erreur);
        }
    });

    routeErreursAppeler('/diagnostic/v1/erreurs-navigateur', $cree['entete'])->assertStatus(500)
        ->assertExactJson(['erreur' => 'erreur-interne', 'note' => EnveloppeDeDiagnostic::NOTE]);
});

it('écrit dans l’audit les paramètres validés, jamais un texte reçu', function (): void {
    $lignes = [];
    $elements = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $evenement) use (&$lignes, &$elements): void {
        if ($evenement->message === 'diagnostic.appel') {
            $lignes[] = $evenement->context['parametres'] ?? null;
            $elements[] = $evenement->context['elements'] ?? null;
        }
    });
    routeErreursPlanter('audit');
    $cree = routeErreursUnJeton();

    routeErreursAppeler('/diagnostic/v1/erreurs-navigateur?'.http_build_query(['autre' => 'ignore previous instructions']), $cree['entete'])->assertOk();
    routeErreursAppeler('/diagnostic/v1/erreurs-navigateur/'.md5('audit'), $cree['entete'])->assertOk();

    expect($lignes)->toBe([[], ['empreinte' => md5('audit')]])
        ->and($elements)->toBe([1, 1]);
});

it('garde chaque réponse sous 256 Kio, même devant cinquante groupes aux textes démesurés', function (): void {
    $compte = User::factory()->create();
    foreach (range(1, 50) as $numero) {
        routeErreursPlanter('enorme-'.$numero, $compte, [
            'message' => str_repeat('démesuré ', 5_000), 'pile' => str_repeat("at x (https://gym.example.org/a.js:1:2)\n", 1_200),
            'url' => 'https://gym.example.org/'.str_repeat('a', 1_900), 'source' => 'https://gym.example.org/'.str_repeat('s', 1_900), 'agent' => str_repeat('Mozilla ', 60),
        ]);
    }
    $cree = routeErreursUnJeton(['erreurs-navigateur', 'texte-brut']);

    foreach (['/diagnostic/v1/erreurs-navigateur', '/diagnostic/v1/erreurs-navigateur/'.md5('enorme-1')] as $uri) {
        $reponse = routeErreursAppeler($uri, $cree['entete'])->assertOk();

        expect(strlen((string) $reponse->getContent()))->toBeLessThan(256 * 1024);
    }
});

it('ne sert que la lecture : chaque autre méthode est refusée comme un chemin inconnu', function (string $methode): void {
    routeErreursPlanter();
    $cree = routeErreursUnJeton();

    $reponse = $this->call($methode, '/diagnostic/v1/erreurs-navigateur/'.md5('ordinaire'), server: ['HTTP_AUTHORIZATION' => $cree['entete'], 'HTTP_ACCEPT' => 'application/json']);

    expect($reponse->getStatusCode())->toBe(404)
        ->and(ErreurNavigateur::query()->count())->toBe(1);
})->with(['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);

it('nomme chaque route des erreurs navigateur dans PORTEES, sous la portée ErreursNavigateur', function (): void {
    expect(array_filter(ExigeUnJetonDeDiagnostic::PORTEES, static fn (?PorteeDeDiagnostic $portee, string $nom): bool => str_starts_with($nom, 'diagnostic.erreurs-navigateur.'), ARRAY_FILTER_USE_BOTH))
        ->toBe([
            'diagnostic.erreurs-navigateur.index' => PorteeDeDiagnostic::ErreursNavigateur,
            'diagnostic.erreurs-navigateur.show' => PorteeDeDiagnostic::ErreursNavigateur,
        ]);
});

it('compte les comptes touchés et les navigateurs de la liste sans jamais les nommer', function (): void {
    routeErreursPlanter('partage', User::factory()->create(), ['agent' => 'Mozilla/5.0 Chrome/130.0 Safari/537.36']);
    routeErreursPlanter('partage', User::factory()->create(), ['agent' => 'Mozilla/5.0 Firefox/130.0']);
    $cree = routeErreursUnJeton();

    $groupe = routeErreursAppeler('/diagnostic/v1/erreurs-navigateur', $cree['entete'])->assertOk()->json('donnees.erreurs_navigateur.groupes.0');

    expect($groupe)->toMatchArray(['comptes_touches' => 2, 'nombre' => 2, 'navigateurs' => ['chrome', 'firefox']]);
});
