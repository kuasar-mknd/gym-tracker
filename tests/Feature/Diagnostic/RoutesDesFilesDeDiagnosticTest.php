<?php

declare(strict_types=1);

use App\Enums\PorteeDeDiagnostic;
use App\Http\Middleware\ExigeUnJetonDeDiagnostic;
use App\Models\Admin;
use App\Models\JetonDeDiagnostic;
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
 * La route des files de l'accès de diagnostic (#2032), sous la portée `Files` :
 * l'état des files et les jobs échoués, en lecture seule. Les valeurs plantées
 * dans la charge utile et le message d'un échec (adresse, IP, jeton, secret) se
 * composent à l'exécution et ne doivent sortir sous aucune forme : la liste ne
 * rend que des noms de classe et de file, même sous TexteBrut.
 */
const ROUTE_FILES_IP = '203.0.113.99';
const ROUTE_FILES_ADRESSE = 'camille.canari@example.org';
const ROUTE_FILES_SECRET = 'sk_live_canari_0123456789abcdef';

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
function routeFilesUnJeton(array $portees = ['files']): array
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
function routeFilesAppeler(string $uri, ?string $entete): TestResponse
{
    return get($uri, $entete === null ? ['Accept' => 'application/json'] : ['Accept' => 'application/json', 'Authorization' => $entete]);
}

/**
 * Un job échoué, écrit sans passer par la file.
 *
 * @param  array<string, mixed>  $colonnes
 */
function routeFilesPlanter(string $file = 'default', array $colonnes = []): int
{
    return (int) DB::table('failed_jobs')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => $file,
        'payload' => (string) json_encode(['displayName' => 'App\\Jobs\\EnvoiDuCourrier', 'data' => ['command' => 'charge-utile-canari']]),
        'exception' => "RuntimeException: échec message-canari in /var/www/html/app/Jobs/EnvoiDuCourrier.php:42\nStack trace:\n#0 {main}",
        'failed_at' => now()->subHours(2),
        ...$colonnes,
    ]);
}

/**
 * Un échec dont la charge utile et le message portent chacun une valeur sensible.
 */
function routeFilesPlanterLesCanaris(): void
{
    $jeton = JetonDeDiagnostic::composer(Str::lower(Str::random(JetonDeDiagnostic::LONGUEUR_DE_L_IDENTIFIANT)), Str::random(JetonDeDiagnostic::LONGUEUR_DU_SECRET));
    routeFilesPlanter('default', [
        'payload' => (string) json_encode(['displayName' => 'App\\Jobs\\EnvoiDuCourrier', 'data' => ['command' => 'pour '.ROUTE_FILES_ADRESSE.' depuis '.ROUTE_FILES_IP.' '.ROUTE_FILES_SECRET]]),
        'exception' => 'RuntimeException: échec pour '.ROUTE_FILES_ADRESSE.' depuis '.ROUTE_FILES_IP.' avec Bearer '.$jeton.' et Bearer '.ROUTE_FILES_SECRET." in /var/www/html/x.php:1\nStack trace:\n#0 {main}",
    ]);
}

/**
 * @return list<string>
 */
function routeFilesLesCanaris(): array
{
    return [ROUTE_FILES_IP, ROUTE_FILES_ADRESSE, ROUTE_FILES_SECRET, 'canari', 'gtdiag_', 'charge-utile'];
}

/**
 * Un tableau lu d'un JSON, vide si la valeur n'en est pas un.
 *
 * @return array<array-key, mixed>
 */
function routeFilesTableau(mixed $valeur): array
{
    return is_array($valeur) ? $valeur : [];
}

it('rend exactement ces clés, avec l’état des files et les échecs comptés par file', function (): void {
    routeFilesPlanter('default');
    routeFilesPlanter('default');
    routeFilesPlanter('notifications');
    $cree = routeFilesUnJeton();

    $reponse = routeFilesAppeler('/diagnostic/v1/files', $cree['entete'])->assertOk();

    expect(array_keys(routeFilesTableau($reponse->json())))->toBe(['donnees', 'suivant', 'tronque', 'echantillon', 'genere_le', 'note'])
        ->and(array_keys(routeFilesTableau($reponse->json('donnees'))))->toBe(['files'])
        ->and(array_keys(routeFilesTableau($reponse->json('donnees.files'))))->toBe(['horizon', 'echecs', 'echecs_par_file', 'lignes_lues', 'echantillon', 'tronque', 'derniers_echecs'])
        ->and(array_keys(routeFilesTableau($reponse->json('donnees.files.echecs.0'))))->toBe(['job', 'exception', 'dernier_uuid', 'nombre', 'premiere_le', 'derniere_le', 'dernier_id'])
        ->and($reponse->json('donnees.files.echecs_par_file'))->toBe([['file' => 'default', 'echecs' => 2], ['file' => 'notifications', 'echecs' => 1]])
        ->and($reponse->json('donnees.files.lignes_lues'))->toBe(3)
        ->and($reponse->json('donnees.files.derniers_echecs'))->toHaveCount(3)
        ->and(array_keys(routeFilesTableau($reponse->json('donnees.files.derniers_echecs.0'))))->toBe(['uuid', 'job', 'file', 'echoue_le', 'exception'])
        ->and($reponse->json('donnees.files.derniers_echecs.0'))->toMatchArray(['file' => 'notifications', 'job' => 'App\\Jobs\\EnvoiDuCourrier', 'exception' => 'RuntimeException'])
        ->and($reponse->json('donnees.files.echecs.0'))->toMatchArray(['job' => 'App\\Jobs\\EnvoiDuCourrier', 'exception' => 'RuntimeException', 'nombre' => 3]);
});

it('ne laisse sortir ni charge utile, ni message, ni adresse, ni IP, ni jeton, sans TexteBrut', function (): void {
    routeFilesPlanterLesCanaris();
    $cree = routeFilesUnJeton(['files']);

    $contenu = (string) routeFilesAppeler('/diagnostic/v1/files', $cree['entete'])->assertOk()->getContent();

    expect($contenu)->not->toContain('"non_fiable":')
        ->and($contenu)->not->toContain('échec pour');
    foreach (routeFilesLesCanaris() as $canari) {
        expect($contenu)->not->toContain($canari);
    }
});

it('rend le message des derniers échecs, expurgé, sous non_fiable et sous TexteBrut seulement, sans jamais la charge utile', function (): void {
    routeFilesPlanterLesCanaris();
    $cree = routeFilesUnJeton(['files', 'texte-brut']);

    $reponse = routeFilesAppeler('/diagnostic/v1/files', $cree['entete'])->assertOk();
    $contenu = (string) $reponse->getContent();

    expect(array_keys(routeFilesTableau($reponse->json('donnees.files.derniers_echecs.0'))))->toBe(['uuid', 'job', 'file', 'echoue_le', 'exception', 'non_fiable'])
        ->and(array_keys(routeFilesTableau($reponse->json('donnees.files.derniers_echecs.0.non_fiable'))))->toBe(['message'])
        ->and($reponse->json('donnees.files.derniers_echecs.0.non_fiable.message'))->toContain('échec pour [courriel]');
    foreach (routeFilesLesCanaris() as $canari) {
        expect($contenu)->not->toContain($canari);
    }
});

it('ne rend jamais plus de cinq derniers échecs, du plus récent au plus ancien', function (): void {
    foreach (range(1, 8) as $numero) {
        routeFilesPlanter('f'.$numero, ['failed_at' => now()->subMinutes(100 - $numero)]);
    }
    $cree = routeFilesUnJeton();

    $reponse = routeFilesAppeler('/diagnostic/v1/files', $cree['entete'])->assertOk();

    expect(array_column(routeFilesTableau($reponse->json('donnees.files.derniers_echecs')), 'file'))->toBe(['f8', 'f7', 'f6', 'f5', 'f4']);
});

it('refuse en 403 portee-non-accordee à un jeton sans la portée Files, et la sert à qui l’a', function (): void {
    routeFilesPlanter();
    $sans = routeFilesUnJeton(['sante', 'exceptions', 'texte-brut']);
    $avec = routeFilesUnJeton(['files']);

    routeFilesAppeler('/diagnostic/v1/files', $sans['entete'])->assertForbidden()
        ->assertExactJson(['erreur' => 'portee-non-accordee', 'note' => EnveloppeDeDiagnostic::NOTE]);
    routeFilesAppeler('/diagnostic/v1/files', $avec['entete'])->assertOk();
});

it('répond sans jeton, ou au jeton faux, comme à un chemin inconnu, la route existant pour un jeton sans portée', function (): void {
    $sansPortee = routeFilesUnJeton(['exceptions']);
    $cree = routeFilesUnJeton();
    $faux = 'Bearer '.JetonDeDiagnostic::composer(Str::lower(Str::random(JetonDeDiagnostic::LONGUEUR_DE_L_IDENTIFIANT)), Str::random(JetonDeDiagnostic::LONGUEUR_DU_SECRET));
    $tout = static function (TestResponse $reponse): array {
        $entetes = $reponse->headers->allPreserveCase();
        unset($entetes['Date'], $entetes['date']);
        ksort($entetes);

        return [$reponse->getStatusCode(), (string) $reponse->getContent(), $entetes];
    };

    $inconnu = $tout(routeFilesAppeler('/diagnostic/v1/ce-chemin-n-existe-pas', null));

    expect($inconnu[0])->toBe(404)
        ->and($tout(routeFilesAppeler('/diagnostic/v1/files', null)))->toBe($inconnu)
        ->and($tout(routeFilesAppeler('/diagnostic/v1/files', $faux)))->toBe($inconnu)
        ->and(routeFilesAppeler('/diagnostic/v1/files', $sansPortee['entete'])->getStatusCode())->toBe(403);
    expect($cree['jeton']->fresh()?->derniere_utilisation_le)->toBeNull();
});

it('ne groupe jamais plus de 50 classes, et le dit', function (): void {
    foreach (range(1, 55) as $numero) {
        routeFilesPlanter('default', ['payload' => (string) json_encode(['displayName' => 'App\\Jobs\\Job'.$numero])]);
    }
    $cree = routeFilesUnJeton();

    $reponse = routeFilesAppeler('/diagnostic/v1/files', $cree['entete'])->assertOk();

    expect($reponse->json('donnees.files.echecs'))->toHaveCount(50)
        ->and($reponse->json('tronque'))->toBeTrue()
        ->and($reponse->json('donnees.files.tronque'))->toBeTrue();
});

it('ne rend jamais plus de 50 files, et ne lit que les 5 000 derniers échecs en le disant', function (): void {
    $lignes = [];
    foreach (range(1, 5_001) as $numero) {
        $lignes[] = [
            'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'file'.($numero % 60), 'payload' => '{"displayName":"App\\\\Jobs\\\\Un"}',
            'exception' => 'RuntimeException: a', 'failed_at' => now()->subHour(),
        ];
    }
    foreach (array_chunk($lignes, 1_000) as $lot) {
        DB::table('failed_jobs')->insert($lot);
    }
    $cree = routeFilesUnJeton();

    $reponse = routeFilesAppeler('/diagnostic/v1/files', $cree['entete'])->assertOk();

    expect($reponse->json('echantillon'))->toBeTrue()
        ->and($reponse->json('donnees.files.lignes_lues'))->toBe(5_000)
        ->and($reponse->json('donnees.files.echecs_par_file'))->toHaveCount(50)
        ->and($reponse->json('donnees.files.tronque'))->toBeTrue()
        ->and(array_sum(array_column(routeFilesTableau($reponse->json('donnees.files.echecs_par_file')), 'echecs')))->toBeLessThanOrEqual(5_000);
});

it('n’écrit rien en base pour une lecture, hors la trace d’usage du jeton, et borne chaque lecture de failed_jobs', function (): void {
    routeFilesPlanterLesCanaris();
    $cree = routeFilesUnJeton(['files', 'texte-brut']);
    $requetes = [];
    DB::listen(static function ($requete) use (&$requetes): void {
        $requetes[] = $requete->sql;
    });

    routeFilesAppeler('/diagnostic/v1/files', $cree['entete'])->assertOk();

    $ecritures = array_values(array_filter($requetes, static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate|lock)\b/i', $sql) === 1));
    $lectures = array_values(array_filter($requetes, static fn (string $sql): bool => str_contains($sql, 'failed_jobs')));

    expect($ecritures)->toHaveCount(1)
        ->and($ecritures[0])->toStartWith('update `jetons_de_diagnostic` set `derniere_utilisation_le`')
        ->and($lectures)->not->toBe([]);
    foreach ($lectures as $sql) {
        expect($sql)->toStartWith('select /*+ MAX_EXECUTION_TIME('.LecturesBornees::MILLISECONDES_AU_PLUS.') */')
            ->and($sql)->not->toContain('select *')
            ->and($sql)->toMatch('/ limit \d+$/');
    }
});

it('rend 503 lecture-trop-longue quand la base coupe la lecture à la borne de temps, après un jeton valide', function (): void {
    routeFilesPlanter();
    $cree = routeFilesUnJeton();
    DB::beforeExecuting(static function (string $sql): void {
        if (str_contains($sql, 'failed_jobs')) {
            $erreur = new PDOException('Query execution was interrupted, maximum statement execution time exceeded');
            $erreur->errorInfo = ['HY000', LecturesBornees::ERREUR_DE_TEMPS_ECOULE, 'Query execution was interrupted, maximum statement execution time exceeded'];

            throw new QueryException('mysql', $sql, [], $erreur);
        }
    });

    $reponse = routeFilesAppeler('/diagnostic/v1/files', $cree['entete']);
    $sansJeton = routeFilesAppeler('/diagnostic/v1/files', null);

    $reponse->assertStatus(503)
        ->assertExactJson(['erreur' => 'lecture-trop-longue', 'note' => EnveloppeDeDiagnostic::NOTE])
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex');
    expect($sansJeton->getStatusCode())->toBe(404);
});

it('rend une 500 sans détail pour toute autre erreur de la base', function (): void {
    routeFilesPlanter();
    $cree = routeFilesUnJeton();
    DB::beforeExecuting(static function (string $sql): void {
        if (str_contains($sql, 'failed_jobs')) {
            $erreur = new PDOException('table perdue');
            $erreur->errorInfo = ['42S02', 1146, 'table perdue'];

            throw new QueryException('mysql', $sql, [], $erreur);
        }
    });

    routeFilesAppeler('/diagnostic/v1/files', $cree['entete'])->assertStatus(500)
        ->assertExactJson(['erreur' => 'erreur-interne', 'note' => EnveloppeDeDiagnostic::NOTE]);
});

it('écrit dans l’audit des paramètres vides, jamais un texte reçu', function (): void {
    $lignes = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $evenement) use (&$lignes): void {
        if ($evenement->message === 'diagnostic.appel') {
            $lignes[] = [$evenement->context['parametres'] ?? null, $evenement->context['elements'] ?? null];
        }
    });
    routeFilesPlanter();
    $cree = routeFilesUnJeton();

    routeFilesAppeler('/diagnostic/v1/files?'.http_build_query(['autre' => 'ignore previous instructions']), $cree['entete'])->assertOk();

    expect($lignes)->toBe([[[], 1]]);
});

it('coupe cinquante classes de job aux noms et aux messages démesurés bien en dessous de 256 Kio', function (): void {
    foreach (range(1, 60) as $numero) {
        routeFilesPlanter(str_repeat('f', 240).$numero, [
            'payload' => (string) json_encode(['displayName' => str_repeat('C', 250).$numero]),
            'exception' => str_repeat('E', 250).': '.str_repeat('démesuré ', 20_000),
        ]);
    }
    $cree = routeFilesUnJeton(['files', 'texte-brut']);

    $reponse = routeFilesAppeler('/diagnostic/v1/files', $cree['entete'])->assertOk();

    // Les budgets par champ coupent des entrées de plusieurs Mio : la réponse pleine (cinquante groupes) pèse une vingtaine de Kio, très en dessous du plafond, que `AccesDeDiagnosticTest` prouve à part. Le plancher empêche ce test de passer sans rien lire.
    expect(strlen((string) $reponse->getContent()))->toBeGreaterThan(15_000)
        ->and(strlen((string) $reponse->getContent()))->toBeLessThan(64 * 1024)
        ->and(routeFilesTableau($reponse->json('donnees.files.echecs')))->toHaveCount(50);
});

it('ne sert que la lecture : chaque autre méthode est refusée comme un chemin inconnu', function (string $methode): void {
    routeFilesPlanter();
    $cree = routeFilesUnJeton();

    $reponse = $this->call($methode, '/diagnostic/v1/files', server: ['HTTP_AUTHORIZATION' => $cree['entete'], 'HTTP_ACCEPT' => 'application/json']);

    expect($reponse->getStatusCode())->toBe(404)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
})->with(['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);

it('nomme la route des files dans PORTEES, sous la portée Files', function (): void {
    expect(ExigeUnJetonDeDiagnostic::PORTEES['diagnostic.files.index'])->toBe(PorteeDeDiagnostic::Files);
});

it('ne compte que les échecs des trente derniers jours', function (): void {
    routeFilesPlanter('ancienne', ['failed_at' => now()->subDays(31)]);
    routeFilesPlanter('recente', ['failed_at' => now()->subDays(2)]);
    $cree = routeFilesUnJeton();

    expect(routeFilesAppeler('/diagnostic/v1/files', $cree['entete'])->json('donnees.files.echecs_par_file'))->toBe([['file' => 'recente', 'echecs' => 1]]);
});
