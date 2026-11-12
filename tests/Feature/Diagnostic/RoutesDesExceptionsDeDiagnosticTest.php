<?php

declare(strict_types=1);

use App\Enums\PorteeDeDiagnostic;
use App\Models\Admin;
use App\Models\JetonDeDiagnostic;
use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Services\Diagnostic\Projections\LecturesBornees;
use App\Services\Diagnostic\Projections\ProjectionDesExceptions;
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
 * Les routes de la portée `Exceptions` de l'accès de diagnostic (#2032) :
 * la liste des groupes, le détail et les occurrences, en lecture seule. Les
 * valeurs plantées dans la ligne (adresse, IP, jeton, secret) se composent à
 * l'exécution et ne doivent sortir que par `non_fiable`, expurgées, et sous
 * `TexteBrut` seulement.
 */
const ROUTE_EXCEPTIONS_IP = '203.0.113.77';
const ROUTE_EXCEPTIONS_ADRESSE = 'camille.canari@example.org';
const ROUTE_EXCEPTIONS_SECRET = 'sk_live_canari_0123456789abcdef';

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
function routeExceptionsUnJeton(array $portees = ['exceptions']): array
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
function routeExceptionsAppeler(string $uri, ?string $entete): TestResponse
{
    return get($uri, $entete === null ? ['Accept' => 'application/json'] : ['Accept' => 'application/json', 'Authorization' => $entete]);
}

/**
 * Une exception écrite sans passer par le modèle, qui masquerait les
 * valeurs plantées : la ligne telle qu'un paquet ou une version plus
 * ancienne l'aurait laissée.
 *
 * @param  array<string, mixed>  $colonnes
 */
function routeExceptionsPlanter(array $colonnes = []): int
{
    return (int) DB::table('filament_exceptions_table')->insertGetId([
        'type' => RuntimeException::class,
        'code' => '0',
        'message' => 'boum',
        'file' => base_path('app/Models/User.php'),
        'line' => 12,
        'trace' => '[]',
        'method' => 'GET',
        'path' => '/seances/1',
        'created_at' => now()->subMinutes(5),
        'updated_at' => now(),
        ...$colonnes,
    ]);
}

/**
 * Une ligne qui porte une valeur sensible dans chaque colonne.
 *
 * @param  array<string, mixed>  $colonnes
 */
function routeExceptionsPlanterLesCanaris(array $colonnes = []): int
{
    $jeton = JetonDeDiagnostic::composer(Str::lower(Str::random(JetonDeDiagnostic::LONGUEUR_DE_L_IDENTIFIANT)), Str::random(JetonDeDiagnostic::LONGUEUR_DU_SECRET));

    return routeExceptionsPlanter([
        'message' => 'échec pour '.ROUTE_EXCEPTIONS_ADRESSE.' depuis '.ROUTE_EXCEPTIONS_IP.' avec Bearer '.$jeton.' et '.$jeton,
        'path' => '/reset/'.ROUTE_EXCEPTIONS_ADRESSE.'?ip='.ROUTE_EXCEPTIONS_IP,
        'ip' => ROUTE_EXCEPTIONS_IP,
        'headers' => json_encode(['authorization' => ['Bearer '.ROUTE_EXCEPTIONS_SECRET], 'x-forwarded-for' => [ROUTE_EXCEPTIONS_IP]]),
        'cookies' => json_encode(['session' => ROUTE_EXCEPTIONS_SECRET]),
        'body' => json_encode(['email' => ROUTE_EXCEPTIONS_ADRESSE, 'token' => ROUTE_EXCEPTIONS_SECRET]),
        'query' => json_encode(['select * from users where email = \''.ROUTE_EXCEPTIONS_ADRESSE.'\'']),
        'route_parameters' => json_encode(['token' => ROUTE_EXCEPTIONS_SECRET]),
        'markdown' => 'Bearer '.ROUTE_EXCEPTIONS_SECRET.' '.ROUTE_EXCEPTIONS_ADRESSE,
        'trace' => json_encode([[
            'file' => base_path('app/Models/User.php'),
            'line' => 30,
            'class' => \App\Models\User::class,
            'type' => '->',
            'function' => 'connecter',
            'args' => [ROUTE_EXCEPTIONS_SECRET, ROUTE_EXCEPTIONS_ADRESSE, ROUTE_EXCEPTIONS_IP],
        ]]),
        'route_context' => json_encode(['route name' => 'workouts.show', 'controller' => ROUTE_EXCEPTIONS_SECRET]),
        ...$colonnes,
    ]);
}

/**
 * @return list<string>
 */
function routeExceptionsLesCanaris(): array
{
    return [ROUTE_EXCEPTIONS_IP, ROUTE_EXCEPTIONS_ADRESSE, ROUTE_EXCEPTIONS_SECRET, 'canari', 'gtdiag_'];
}

/**
 * Un tableau lu d'un JSON, vide si la valeur n'en est pas un.
 *
 * @return array<array-key, mixed>
 */
function routeExceptionsTableau(mixed $valeur): array
{
    return is_array($valeur) ? $valeur : [];
}

/**
 * Les clés de chaque niveau d'un tableau, triées, comme un chemin pointé.
 *
 * @param  array<array-key, mixed>  $valeurs
 * @return list<string>
 */
function routeExceptionsLesCles(array $valeurs, string $prefixe = ''): array
{
    $cles = [];

    foreach ($valeurs as $cle => $valeur) {
        $chemin = $prefixe.(is_int($cle) ? '*' : $cle);
        $cles[] = $chemin;

        if (is_array($valeur)) {
            $cles = [...$cles, ...routeExceptionsLesCles($valeur, $chemin.'.')];
        }
    }

    return array_values(array_unique($cles));
}

/**
 * L'adresse de la route désignée, une exception plantée pour les deux
 * dernières.
 */
function routeExceptionsAdresse(string $route): string
{
    return match ($route) {
        'la liste' => '/diagnostic/v1/exceptions',
        'le détail' => '/diagnostic/v1/exceptions/'.routeExceptionsPlanterLesCanaris(),
        default => '/diagnostic/v1/exceptions/'.routeExceptionsPlanterLesCanaris().'/occurrences',
    };
}

dataset('routes des exceptions', ['la liste', 'le détail', 'les occurrences']);

it('rend de la liste exactement ces clés, et aucune clé non_fiable', function (): void {
    routeExceptionsPlanterLesCanaris(['route_context' => json_encode(['route name' => 'workouts.show'])]);
    $cree = routeExceptionsUnJeton(['exceptions', 'texte-brut']);

    $reponse = routeExceptionsAppeler('/diagnostic/v1/exceptions', $cree['entete'])->assertOk();

    expect(routeExceptionsLesCles(routeExceptionsTableau($reponse->json())))->toBe([
        'donnees', 'donnees.exceptions', 'donnees.exceptions.groupes', 'donnees.exceptions.groupes.*',
        'donnees.exceptions.groupes.*.type', 'donnees.exceptions.groupes.*.fichier', 'donnees.exceptions.groupes.*.ligne',
        'donnees.exceptions.groupes.*.nombre', 'donnees.exceptions.groupes.*.premiere_le', 'donnees.exceptions.groupes.*.derniere_le',
        'donnees.exceptions.groupes.*.dernier_id', 'donnees.exceptions.groupes.*.route',
        'donnees.exceptions.lignes_lues', 'donnees.exceptions.echantillon', 'donnees.exceptions.tronque',
        'suivant', 'tronque', 'echantillon', 'genere_le', 'note',
    ]);
    expect($reponse->json('donnees.exceptions.groupes.0'))->toMatchArray([
        'type' => RuntimeException::class,
        'fichier' => 'app/Models/User.php',
        'ligne' => 12,
        'nombre' => 1,
        'route' => 'workouts.show',
    ])->and((string) $reponse->getContent())->not->toContain('"non_fiable":');
});

it('rend du détail exactement ces clés sans TexteBrut : la clé non_fiable n’existe pas', function (): void {
    $identifiant = routeExceptionsPlanterLesCanaris();
    $cree = routeExceptionsUnJeton(['exceptions']);

    $reponse = routeExceptionsAppeler('/diagnostic/v1/exceptions/'.$identifiant, $cree['entete'])->assertOk();

    expect(array_keys(routeExceptionsTableau($reponse->json('donnees.exception'))))->toBe(['id', 'type', 'code', 'fichier', 'ligne', 'methode', 'route', 'gabarit', 'le', 'pile'])
        ->and(array_keys(routeExceptionsTableau($reponse->json())))->toBe(['donnees', 'suivant', 'tronque', 'echantillon', 'genere_le', 'note'])
        ->and($reponse->json('donnees.exception'))->toMatchArray([
            'id' => $identifiant,
            'type' => RuntimeException::class,
            'code' => '0',
            'fichier' => 'app/Models/User.php',
            'ligne' => 12,
            'methode' => 'GET',
            'route' => 'workouts.show',
            'gabarit' => 'workouts/{workout}',
        ])
        ->and($reponse->json('donnees.exception.pile'))->toBe([['fichier' => 'app/Models/User.php', 'ligne' => 30, 'appel' => 'App\\Models\\User->connecter']])
        ->and((string) $reponse->getContent())->not->toContain('"non_fiable":');
    $reponse->assertJsonMissingPath('donnees.exception.non_fiable');
});

it('rend la clé non_fiable, avec le message et le chemin, sous TexteBrut seulement', function (): void {
    $identifiant = routeExceptionsPlanterLesCanaris();
    $cree = routeExceptionsUnJeton(['exceptions', 'texte-brut']);

    $reponse = routeExceptionsAppeler('/diagnostic/v1/exceptions/'.$identifiant, $cree['entete'])->assertOk();

    expect(array_keys(routeExceptionsTableau($reponse->json('donnees.exception'))))->toBe(['id', 'type', 'code', 'fichier', 'ligne', 'methode', 'route', 'gabarit', 'le', 'pile', 'non_fiable'])
        ->and(array_keys(routeExceptionsTableau($reponse->json('donnees.exception.non_fiable'))))->toBe(['message', 'chemin'])
        ->and($reponse->json('donnees.exception.non_fiable.message'))->toContain('[courriel]')
        ->and($reponse->json('donnees.exception.non_fiable.message'))->toContain('échec pour');
});

it('ne laisse sortir ni adresse, ni IP, ni jeton, ni secret planté, sur aucune route, sans TexteBrut', function (string $route): void {
    $uri = routeExceptionsAdresse($route);
    $cree = routeExceptionsUnJeton(['exceptions']);

    $contenu = (string) routeExceptionsAppeler($uri, $cree['entete'])->assertOk()->getContent();

    foreach (routeExceptionsLesCanaris() as $canari) {
        expect($contenu)->not->toContain($canari);
    }
})->with('routes des exceptions');

it('expurge le message et le chemin sous TexteBrut, et ne lit toujours ni les en-têtes, ni la pile, ni l’IP', function (): void {
    $identifiant = routeExceptionsPlanterLesCanaris();
    $cree = routeExceptionsUnJeton(['exceptions', 'texte-brut']);

    $contenu = (string) routeExceptionsAppeler('/diagnostic/v1/exceptions/'.$identifiant, $cree['entete'])->assertOk()->getContent();

    foreach (routeExceptionsLesCanaris() as $canari) {
        expect($contenu)->not->toContain($canari);
    }

    expect($contenu)->toContain('[courriel]');
});

it('refuse en 403 portee-non-accordee chaque route à un jeton sans la portée Exceptions, et la sert à qui l’a', function (string $route): void {
    $uri = routeExceptionsAdresse($route);
    $sans = routeExceptionsUnJeton(['sante', 'texte-brut']);
    $avec = routeExceptionsUnJeton(['exceptions']);

    routeExceptionsAppeler($uri, $sans['entete'])->assertForbidden()
        ->assertExactJson(['erreur' => 'portee-non-accordee', 'note' => EnveloppeDeDiagnostic::NOTE]);
    routeExceptionsAppeler($uri, $avec['entete'])->assertOk();
})->with('routes des exceptions');

it('répond sans jeton, ou au jeton faux, comme à un chemin inconnu', function (string $route): void {
    $uri = routeExceptionsAdresse($route);
    $cree = routeExceptionsUnJeton();
    $faux = 'Bearer '.JetonDeDiagnostic::composer(Str::lower(Str::random(JetonDeDiagnostic::LONGUEUR_DE_L_IDENTIFIANT)), Str::random(JetonDeDiagnostic::LONGUEUR_DU_SECRET));
    $tout = static function (TestResponse $reponse): array {
        $entetes = $reponse->headers->allPreserveCase();
        unset($entetes['Date'], $entetes['date']);
        ksort($entetes);

        return [$reponse->getStatusCode(), (string) $reponse->getContent(), $entetes];
    };

    $inconnu = $tout(routeExceptionsAppeler('/diagnostic/v1/ce-chemin-n-existe-pas', null));

    expect($inconnu[0])->toBe(404)
        ->and($tout(routeExceptionsAppeler($uri, null)))->toBe($inconnu)
        ->and($tout(routeExceptionsAppeler($uri, $faux)))->toBe($inconnu);
    expect($cree['jeton']->fresh()?->derniere_utilisation_le)->toBeNull();
})->with('routes des exceptions');

it('ne route pas un identifiant qui n’est pas un nombre : même 404 qu’un chemin inconnu', function (string $identifiant): void {
    $cree = routeExceptionsUnJeton();

    $reponse = routeExceptionsAppeler('/diagnostic/v1/exceptions/'.$identifiant, $cree['entete']);
    $occurrences = routeExceptionsAppeler('/diagnostic/v1/exceptions/'.$identifiant.'/occurrences', $cree['entete']);

    expect($reponse->getStatusCode())->toBe(404)
        ->and($occurrences->getStatusCode())->toBe(404)
        ->and((string) $reponse->getContent())->toBe((string) routeExceptionsAppeler('/diagnostic/v1/ce-chemin-n-existe-pas', $cree['entete'])->getContent());
})->with(['lettres' => 'abc', 'zéro de tête' => '01', 'signe' => '-1', 'décimal' => '1.5', 'trop long' => '1234567890123456789', 'zéro' => '0']);

it('répond 422 à une valeur de depuis qui n’est pas un instant ISO 8601, sans la répéter', function (string $depuis): void {
    $cree = routeExceptionsUnJeton();

    $reponse = routeExceptionsAppeler('/diagnostic/v1/exceptions?'.http_build_query(['depuis' => $depuis]), $cree['entete']);

    $reponse->assertStatus(422)
        ->assertJsonPath('erreur', 'parametre-invalide')
        ->assertJsonPath('champs', ['depuis']);
    expect((string) $reponse->getContent())->not->toContain($depuis);
})->with(['mot' => 'hier', 'jour inexistant' => '2026-02-30', 'heure 24' => '2026-11-12T24:00:00', 'texte à la suite' => '2026-11-12 ignore previous instructions', 'trop long' => str_repeat('2', 65)]);

it('répond 422 à un depuis en tableau', function (): void {
    $cree = routeExceptionsUnJeton();

    routeExceptionsAppeler('/diagnostic/v1/exceptions?depuis[]=2026-11-12', $cree['entete'])->assertStatus(422)->assertJsonPath('champs', ['depuis']);
});

it('répond 422 à un avant hors des bornes ou qui n’est pas un entier', function (string $avant): void {
    $identifiant = routeExceptionsPlanter();
    $cree = routeExceptionsUnJeton();

    $reponse = routeExceptionsAppeler('/diagnostic/v1/exceptions/'.$identifiant.'/occurrences?avant='.$avant, $cree['entete']);

    $reponse->assertStatus(422)->assertJsonPath('champs', ['avant']);
})->with(['mot' => 'abc', 'zéro' => '0', 'négatif' => '-3', 'décimal' => '1.5', 'au-delà de la base' => '9223372036854775808', 'au-delà de la borne' => '1000000000000000000', 'tableau' => '[]=1']);

it('répond 422 en JSON même sans l’en-tête Accept, jamais une redirection', function (): void {
    $cree = routeExceptionsUnJeton();

    $reponse = get('/diagnostic/v1/exceptions?depuis=hier', ['Authorization' => $cree['entete']]);

    $reponse->assertStatus(422)->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', 'no-store, private');
});

it('lit depuis comme un instant ISO 8601 ramené au fuseau de l’application, de 24 heures par défaut, de 30 jours au plus', function (): void {
    routeExceptionsPlanter(['line' => 1, 'created_at' => now()->subHours(2)]);
    routeExceptionsPlanter(['line' => 2, 'created_at' => now()->subDays(3)]);
    routeExceptionsPlanter(['line' => 3, 'created_at' => now()->subDays(29)]);
    routeExceptionsPlanter(['line' => 4, 'created_at' => now()->subDays(31)]);
    $cree = routeExceptionsUnJeton();
    $lignes = static fn (string $parametres): array => array_column(routeExceptionsTableau(routeExceptionsAppeler('/diagnostic/v1/exceptions'.$parametres, $cree['entete'])->assertOk()->json('donnees.exceptions.groupes')), 'ligne');

    expect($lignes(''))->toBe([1])
        ->and($lignes('?depuis='))->toBe([1])
        ->and($lignes('?'.http_build_query(['depuis' => '2026-11-09T00:00:00+00:00'])))->toBe([2, 1])
        ->and($lignes('?'.http_build_query(['depuis' => '2026-11-12T08:30:00+01:00'])))->toBe([])
        ->and($lignes('?'.http_build_query(['depuis' => '2026-11-12T08:30:00+03:00'])))->toBe([1])
        ->and($lignes('?'.http_build_query(['depuis' => '2026-11-12T07:30:00Z'])))->toBe([])
        ->and($lignes('?'.http_build_query(['depuis' => '2000-01-01'])))->toBe([3, 2, 1]);
});

it('ne rend jamais plus de 50 groupes, et le dit', function (): void {
    foreach (range(1, 55) as $ligne) {
        routeExceptionsPlanter(['line' => $ligne]);
    }
    $cree = routeExceptionsUnJeton();

    $reponse = routeExceptionsAppeler('/diagnostic/v1/exceptions', $cree['entete'])->assertOk();

    expect($reponse->json('donnees.exceptions.groupes'))->toHaveCount(50)
        ->and($reponse->json('tronque'))->toBeTrue()
        ->and($reponse->json('donnees.exceptions.tronque'))->toBeTrue();
});

it('ne lit que les 5 000 dernières lignes de la fenêtre, et signale l’échantillon', function (): void {
    $lignes = array_fill(0, 5_001, [
        'type' => RuntimeException::class, 'code' => '0', 'message' => 'boum', 'file' => base_path('app/Models/User.php'), 'line' => 7,
        'trace' => '[]', 'method' => 'GET', 'path' => '/', 'created_at' => now()->subMinutes(5), 'updated_at' => now(),
    ]);
    foreach (array_chunk($lignes, 1_000) as $lot) {
        DB::table('filament_exceptions_table')->insert($lot);
    }
    $cree = routeExceptionsUnJeton();

    $reponse = routeExceptionsAppeler('/diagnostic/v1/exceptions', $cree['entete'])->assertOk();

    expect($reponse->json('echantillon'))->toBeTrue()
        ->and($reponse->json('donnees.exceptions.lignes_lues'))->toBe(5_000)
        ->and($reponse->json('donnees.exceptions.groupes.0.nombre'))->toBe(5_000);
});

it('rend 404 pour une exception inconnue, ou sortie de la conservation, après un jeton valide seulement', function (): void {
    $ancienne = routeExceptionsPlanter(['created_at' => now()->subDays(31)]);
    $cree = routeExceptionsUnJeton();

    foreach (['/diagnostic/v1/exceptions/99999', '/diagnostic/v1/exceptions/'.$ancienne, '/diagnostic/v1/exceptions/99999/occurrences', '/diagnostic/v1/exceptions/'.$ancienne.'/occurrences'] as $uri) {
        routeExceptionsAppeler($uri, $cree['entete'])->assertNotFound()->assertExactJson(['erreur' => 'introuvable', 'note' => EnveloppeDeDiagnostic::NOTE]);
    }
});

it('pagine les occurrences par 25, du plus récent au plus ancien, avec le curseur suivant', function (): void {
    $identifiants = [];
    foreach (range(1, 30) as $rang) {
        $identifiants[] = routeExceptionsPlanter(['line' => 50, 'method' => $rang % 2 === 0 ? 'POST' : 'GET', 'route_context' => json_encode(['route name' => 'workouts.show']), 'created_at' => now()->subMinutes(60 - $rang)]);
    }
    routeExceptionsPlanter(['line' => 51]);
    $cree = routeExceptionsUnJeton();
    $uri = '/diagnostic/v1/exceptions/'.$identifiants[0].'/occurrences';

    $premiere = routeExceptionsAppeler($uri.'?per_page=2&limite=1', $cree['entete'])->assertOk();
    $suivant = $premiere->json('suivant');
    $seconde = routeExceptionsAppeler($uri.'?avant='.(is_int($suivant) ? $suivant : 0), $cree['entete'])->assertOk();

    expect(array_column(routeExceptionsTableau($premiere->json('donnees.occurrences')), 'id'))->toBe(array_slice(array_reverse($identifiants), 0, 25))
        ->and(array_keys(routeExceptionsTableau($premiere->json('donnees.occurrences.0'))))->toBe(['id', 'le', 'methode', 'route'])
        ->and($premiere->json('donnees.occurrences.0'))->toBe(['id' => $identifiants[29], 'le' => '2026-11-12T09:30:00+01:00', 'methode' => 'POST', 'route' => 'workouts.show'])
        ->and($suivant)->toBe($identifiants[5])
        ->and(array_column(routeExceptionsTableau($seconde->json('donnees.occurrences')), 'id'))->toBe(array_slice(array_reverse($identifiants), 25))
        ->and($seconde->json('suivant'))->toBeNull()
        ->and($seconde->json('echantillon'))->toBeFalse()
        ->and(array_keys(routeExceptionsTableau($premiere->json())))->toBe(['donnees', 'suivant', 'tronque', 'echantillon', 'genere_le', 'note']);
});

it('ne remonte pas, pour les occurrences, au-delà des 5 000 dernières lignes de la fenêtre', function (): void {
    $ancienne = routeExceptionsPlanter(['line' => 1, 'created_at' => now()->subMinutes(30)]);
    $lignes = array_fill(0, 5_000, [
        'type' => RuntimeException::class, 'code' => '0', 'message' => 'boum', 'file' => base_path('app/Models/User.php'), 'line' => 2,
        'trace' => '[]', 'method' => 'GET', 'path' => '/', 'created_at' => now()->subMinutes(5), 'updated_at' => now(),
    ]);
    foreach (array_chunk($lignes, 1_000) as $lot) {
        DB::table('filament_exceptions_table')->insert($lot);
    }
    $cree = routeExceptionsUnJeton();

    $reponse = routeExceptionsAppeler('/diagnostic/v1/exceptions/'.$ancienne.'/occurrences', $cree['entete'])->assertOk();

    expect($reponse->json('donnees.occurrences'))->toBe([])
        ->and($reponse->json('echantillon'))->toBeTrue();
});

it('n’écrit rien en base pour une lecture, hors la trace d’usage du jeton, et borne chaque lecture de la table', function (string $route): void {
    $uri = routeExceptionsAdresse($route);
    $cree = routeExceptionsUnJeton();
    $requetes = [];
    DB::listen(static function ($requete) use (&$requetes): void {
        $requetes[] = $requete->sql;
    });

    routeExceptionsAppeler($uri, $cree['entete'])->assertOk();

    $ecritures = array_values(array_filter($requetes, static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate|lock)\b/i', $sql) === 1));
    $lectures = array_values(array_filter($requetes, static fn (string $sql): bool => str_contains($sql, 'filament_exceptions_table')));

    expect($ecritures)->toHaveCount(1)
        ->and($ecritures[0])->toStartWith('update `jetons_de_diagnostic` set `derniere_utilisation_le`')
        ->and($lectures)->not->toBe([]);
    foreach ($lectures as $sql) {
        expect($sql)->toStartWith('select /*+ MAX_EXECUTION_TIME('.LecturesBornees::MILLISECONDES_AU_PLUS.') */')
            ->and($sql)->not->toContain('select *');
    }
})->with('routes des exceptions');

it('rend 503 lecture-trop-longue quand la base coupe une lecture à la borne de temps, après un jeton valide', function (string $uri): void {
    routeExceptionsPlanter();
    $cree = routeExceptionsUnJeton(['sante', 'exceptions']);
    $coupe = static function (string $sql): void {
        if (str_contains($sql, 'filament_exceptions_table')) {
            $erreur = new PDOException('Query execution was interrupted, maximum statement execution time exceeded');
            $erreur->errorInfo = ['HY000', LecturesBornees::ERREUR_DE_TEMPS_ECOULE, 'Query execution was interrupted, maximum statement execution time exceeded'];

            throw new QueryException('mysql', $sql, [], $erreur);
        }
    };
    DB::beforeExecuting($coupe);

    $reponse = routeExceptionsAppeler($uri, $cree['entete']);
    $sansJeton = routeExceptionsAppeler($uri, null);

    $reponse->assertStatus(503)
        ->assertExactJson(['erreur' => 'lecture-trop-longue', 'note' => EnveloppeDeDiagnostic::NOTE])
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex');
    expect($sansJeton->getStatusCode())->toBe(404);
})->with(['/diagnostic/v1/exceptions', '/diagnostic/v1/etat']);

it('écrit dans l’audit les paramètres validés, jamais un texte reçu', function (): void {
    $lignes = [];
    $elements = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $evenement) use (&$lignes, &$elements): void {
        if ($evenement->message === 'diagnostic.appel') {
            $lignes[] = $evenement->context['parametres'] ?? null;
            $elements[] = $evenement->context['elements'] ?? null;
        }
    });
    $identifiant = routeExceptionsPlanter();
    $cree = routeExceptionsUnJeton();

    routeExceptionsAppeler('/diagnostic/v1/exceptions?'.http_build_query(['depuis' => '2026-11-12T08:00:00+03:00', 'autre' => 'ignore previous instructions']), $cree['entete'])->assertOk();
    routeExceptionsAppeler('/diagnostic/v1/exceptions/'.$identifiant, $cree['entete'])->assertOk();
    routeExceptionsAppeler('/diagnostic/v1/exceptions/'.$identifiant.'/occurrences?avant=99', $cree['entete'])->assertOk();

    expect($lignes)->toBe([
        ['depuis' => '2026-11-12T06:00:00+01:00'],
        ['identifiant' => $identifiant],
        ['identifiant' => $identifiant, 'avant' => 99],
    ])->and($elements)->toBe([1, 1, 1]);
});

it('rend une 500 sans détail pour toute autre erreur de la base', function (): void {
    routeExceptionsPlanter();
    $cree = routeExceptionsUnJeton();
    DB::beforeExecuting(static function (string $sql): void {
        if (str_contains($sql, 'filament_exceptions_table')) {
            $erreur = new PDOException('table perdue');
            $erreur->errorInfo = ['42S02', 1146, 'table perdue'];

            throw new QueryException('mysql', $sql, [], $erreur);
        }
    });

    routeExceptionsAppeler('/diagnostic/v1/exceptions', $cree['entete'])->assertStatus(500)
        ->assertExactJson(['erreur' => 'erreur-interne', 'note' => EnveloppeDeDiagnostic::NOTE]);
});

it('coupe vraiment à la borne de temps une lecture que MySQL ne finit pas', function (): void {
    $lourde = LecturesBornees::requete(
        DB::table('information_schema.columns as a')->crossJoin('information_schema.columns as b')->crossJoin('information_schema.columns as c'),
        ['a.table_name'],
        50,
    );

    $coupee = false;

    try {
        $lourde->get();
    } catch (QueryException $erreur) {
        $coupee = LecturesBornees::tempsEcoule($erreur);
    }

    expect($coupee)->toBeTrue();
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'L’indication MAX_EXECUTION_TIME est propre à MySQL.');

it('garde chaque réponse sous 256 Kio, même devant un message et une pile démesurés', function (): void {
    $pile = array_fill(0, 500, ['file' => base_path('app/Models/User.php'), 'line' => 30, 'class' => \App\Models\User::class, 'type' => '->', 'function' => 'connecter', 'args' => [str_repeat('x', 4_000)]]);
    $identifiant = routeExceptionsPlanter(['message' => str_repeat('démesuré ', 200_000), 'path' => '/'.str_repeat('a', 2_000), 'trace' => json_encode($pile)]);
    $cree = routeExceptionsUnJeton(['exceptions', 'texte-brut']);

    foreach (['/diagnostic/v1/exceptions', '/diagnostic/v1/exceptions/'.$identifiant, '/diagnostic/v1/exceptions/'.$identifiant.'/occurrences'] as $uri) {
        $reponse = routeExceptionsAppeler($uri, $cree['entete'])->assertOk();

        expect(strlen((string) $reponse->getContent()))->toBeLessThan(256 * 1024);
    }

    expect(routeExceptionsAppeler('/diagnostic/v1/exceptions/'.$identifiant, $cree['entete'])->json('donnees.exception.pile'))->toHaveCount(ProjectionDesExceptions::CADRES_AU_PLUS);
});

it('ne sert que la lecture : chaque méthode d’écriture est refusée', function (string $methode): void {
    $identifiant = routeExceptionsPlanter();
    $cree = routeExceptionsUnJeton();

    $reponse = $this->call($methode, '/diagnostic/v1/exceptions/'.$identifiant, server: ['HTTP_AUTHORIZATION' => $cree['entete'], 'HTTP_ACCEPT' => 'application/json']);

    expect($reponse->getStatusCode())->toBeIn([404, 405]);
    expect(DB::table('filament_exceptions_table')->count())->toBe(1);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('nomme chaque route des exceptions dans PORTEES, sous la portée Exceptions', function (): void {
    expect(array_filter(App\Http\Middleware\ExigeUnJetonDeDiagnostic::PORTEES, static fn (?PorteeDeDiagnostic $portee, string $nom): bool => str_starts_with($nom, 'diagnostic.exceptions.'), ARRAY_FILTER_USE_BOTH))
        ->toBe([
            'diagnostic.exceptions.index' => PorteeDeDiagnostic::Exceptions,
            'diagnostic.exceptions.show' => PorteeDeDiagnostic::Exceptions,
            'diagnostic.exceptions.occurrences' => PorteeDeDiagnostic::Exceptions,
        ]);
});
