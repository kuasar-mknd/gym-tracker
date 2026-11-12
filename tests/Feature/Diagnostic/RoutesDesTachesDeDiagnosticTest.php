<?php

declare(strict_types=1);

use App\Enums\PorteeDeDiagnostic;
use App\Http\Middleware\ExigeUnJetonDeDiagnostic;
use App\Models\Admin;
use App\Models\JetonDeDiagnostic;
use App\Models\TachePlanifiee;
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
 * Les routes des tâches planifiées de l'accès de diagnostic (#2032), sous la
 * portée `Sante` : la liste et le détail, en lecture seule. Les valeurs
 * plantées dans le message d'un échec (adresse, IP, jeton, secret) se
 * composent à l'exécution et ne doivent sortir que par `non_fiable`,
 * expurgées, et sous `TexteBrut` seulement.
 */
const ROUTE_TACHES_IP = '203.0.113.88';
const ROUTE_TACHES_ADRESSE = 'camille.canari@example.org';
const ROUTE_TACHES_SECRET = 'sk_live_canari_0123456789abcdef';

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
function routeTachesUnJeton(array $portees = ['sante']): array
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
function routeTachesAppeler(string $uri, ?string $entete): TestResponse
{
    return get($uri, $entete === null ? ['Accept' => 'application/json'] : ['Accept' => 'application/json', 'Authorization' => $entete]);
}

/**
 * Une tâche du moniteur, écrite sans passer par le modèle de la page.
 *
 * @param  array<string, mixed>  $colonnes
 */
function routeTachesPlanter(string $nom = 'backup:run', array $colonnes = []): int
{
    return (int) DB::table('monitored_scheduled_tasks')->insertGetId([
        'name' => $nom,
        'type' => 'command',
        'cron_expression' => '30 2 * * *',
        'grace_time_in_minutes' => 10,
        'last_started_at' => now()->subHours(7),
        'last_finished_at' => now()->subHours(7),
        'created_at' => now()->subDays(40),
        'updated_at' => now(),
        ...$colonnes,
    ]);
}

/**
 * Un échec d'une tâche, avec sa durée, son code de sortie et son message.
 *
 * @param  array<string, mixed>  $meta
 */
function routeTachesPlanterUnEchec(int $tache, array $meta = [], ?Carbon $le = null): int
{
    return (int) DB::table('monitored_scheduled_task_log_items')->insertGetId([
        'monitored_scheduled_task_id' => $tache,
        'type' => 'failed',
        'meta' => json_encode(['runtime' => 2.5, 'exit_code' => 1, 'failure_message' => 'boum', 'output' => 'sortie-canari', ...$meta]),
        'created_at' => $le ?? now()->subHours(3),
        'updated_at' => $le ?? now()->subHours(3),
    ]);
}

/**
 * Une tâche en échec dont chaque champ de texte porte une valeur sensible.
 */
function routeTachesPlanterLesCanaris(): int
{
    $jeton = JetonDeDiagnostic::composer(Str::lower(Str::random(JetonDeDiagnostic::LONGUEUR_DE_L_IDENTIFIANT)), Str::random(JetonDeDiagnostic::LONGUEUR_DU_SECRET));
    $tache = routeTachesPlanter('backup:run', ['last_failed_at' => now()->subHours(3)]);
    routeTachesPlanterUnEchec($tache, [
        'failure_message' => 'échec pour '.ROUTE_TACHES_ADRESSE.' depuis '.ROUTE_TACHES_IP.' avec Bearer '.$jeton.' et Bearer '.ROUTE_TACHES_SECRET,
        'output' => 'sortie '.ROUTE_TACHES_SECRET.' '.ROUTE_TACHES_ADRESSE,
    ]);

    return $tache;
}

/**
 * @return list<string>
 */
function routeTachesLesCanaris(): array
{
    return [ROUTE_TACHES_IP, ROUTE_TACHES_ADRESSE, ROUTE_TACHES_SECRET, 'canari', 'gtdiag_'];
}

/**
 * Un tableau lu d'un JSON, vide si la valeur n'en est pas un.
 *
 * @return array<array-key, mixed>
 */
function routeTachesTableau(mixed $valeur): array
{
    return is_array($valeur) ? $valeur : [];
}

/**
 * L'adresse de la route désignée, une tâche plantée avec ses échecs.
 */
function routeTachesAdresse(string $route): string
{
    $tache = routeTachesPlanterLesCanaris();

    return $route === 'la liste' ? '/diagnostic/v1/taches' : '/diagnostic/v1/taches/'.$tache;
}

dataset('routes des tâches', ['la liste', 'le détail']);

it('rend de la liste exactement ces clés, sans clé non_fiable, et sans la sortie de la commande', function (): void {
    routeTachesPlanterLesCanaris();
    $cree = routeTachesUnJeton(['sante', 'texte-brut']);

    $reponse = routeTachesAppeler('/diagnostic/v1/taches', $cree['entete'])->assertOk();

    expect(array_keys(routeTachesTableau($reponse->json())))->toBe(['donnees', 'suivant', 'tronque', 'echantillon', 'genere_le', 'note'])
        ->and(array_keys(routeTachesTableau($reponse->json('donnees.taches_planifiees'))))->toBe(['taches', 'lignes_lues', 'echantillon', 'tronque'])
        ->and(array_keys(routeTachesTableau($reponse->json('donnees.taches_planifiees.taches.0'))))->toBe([
            'id', 'commande', 'cron', 'etat', 'dernier_depart_le', 'derniere_fin_le', 'dernier_echec_le', 'marge_en_minutes', 'echecs_sur_7_jours', 'derniers_echecs',
        ])
        ->and($reponse->json('donnees.taches_planifiees.taches.0'))->toMatchArray([
            'commande' => 'backup:run',
            'cron' => '30 2 * * *',
            'etat' => 'echouee',
            'marge_en_minutes' => 10,
            'echecs_sur_7_jours' => 1,
        ])
        ->and($reponse->json('donnees.taches_planifiees.taches.0.derniers_echecs.0'))->toMatchArray(['duree_en_secondes' => 2.5, 'code_de_sortie' => 1])
        ->and((string) $reponse->getContent())->not->toContain('"non_fiable":')
        ->and((string) $reponse->getContent())->not->toContain('sortie-canari');
});

it('rend du détail exactement ces clés sans TexteBrut : la clé non_fiable n’existe pas', function (): void {
    $tache = routeTachesPlanterLesCanaris();
    $cree = routeTachesUnJeton(['sante']);

    $reponse = routeTachesAppeler('/diagnostic/v1/taches/'.$tache, $cree['entete'])->assertOk();

    expect(array_keys(routeTachesTableau($reponse->json('donnees.tache_planifiee'))))->toBe([
        'id', 'commande', 'cron', 'etat', 'dernier_depart_le', 'derniere_fin_le', 'dernier_echec_le', 'marge_en_minutes', 'echecs_sur_7_jours', 'derniers_echecs', 'echantillon',
    ])
        ->and(array_keys(routeTachesTableau($reponse->json('donnees.tache_planifiee.derniers_echecs.0'))))->toBe(['le', 'duree_en_secondes', 'code_de_sortie'])
        ->and($reponse->json('donnees.tache_planifiee'))->toMatchArray(['id' => $tache, 'commande' => 'backup:run', 'etat' => 'echouee', 'echecs_sur_7_jours' => 1])
        ->and((string) $reponse->getContent())->not->toContain('"non_fiable":');
});

it('rend la clé non_fiable, avec le message de chaque échec expurgé, sous TexteBrut seulement', function (): void {
    $tache = routeTachesPlanterLesCanaris();
    $cree = routeTachesUnJeton(['sante', 'texte-brut']);

    $reponse = routeTachesAppeler('/diagnostic/v1/taches/'.$tache, $cree['entete'])->assertOk();
    $contenu = (string) $reponse->getContent();

    expect(array_keys(routeTachesTableau($reponse->json('donnees.tache_planifiee.derniers_echecs.0'))))->toBe(['le', 'duree_en_secondes', 'code_de_sortie', 'non_fiable'])
        ->and(array_keys(routeTachesTableau($reponse->json('donnees.tache_planifiee.derniers_echecs.0.non_fiable'))))->toBe(['message'])
        ->and($reponse->json('donnees.tache_planifiee.derniers_echecs.0.non_fiable.message'))->toContain('échec pour [courriel]')
        ->and($contenu)->not->toContain('sortie-canari');

    foreach (routeTachesLesCanaris() as $canari) {
        expect($contenu)->not->toContain($canari);
    }
});

it('ne laisse sortir ni adresse, ni IP, ni jeton, ni secret planté, sur aucune route, sans TexteBrut', function (string $route): void {
    $uri = routeTachesAdresse($route);
    $cree = routeTachesUnJeton(['sante']);

    $contenu = (string) routeTachesAppeler($uri, $cree['entete'])->assertOk()->getContent();

    foreach (routeTachesLesCanaris() as $canari) {
        expect($contenu)->not->toContain($canari);
    }
})->with('routes des tâches');

it('n’expurge pas la liste d’un message qu’elle ne lit pas, même sous TexteBrut', function (): void {
    routeTachesPlanterLesCanaris();
    $cree = routeTachesUnJeton(['sante', 'texte-brut']);

    $contenu = (string) routeTachesAppeler('/diagnostic/v1/taches', $cree['entete'])->assertOk()->getContent();

    expect($contenu)->not->toContain('"non_fiable":')
        ->and($contenu)->not->toContain('échec pour');
});

it('refuse en 403 portee-non-accordee chaque route à un jeton sans la portée Sante, et la sert à qui l’a', function (string $route): void {
    $uri = routeTachesAdresse($route);
    $sans = routeTachesUnJeton(['exceptions', 'texte-brut']);
    $avec = routeTachesUnJeton(['sante']);

    routeTachesAppeler($uri, $sans['entete'])->assertForbidden()
        ->assertExactJson(['erreur' => 'portee-non-accordee', 'note' => EnveloppeDeDiagnostic::NOTE]);
    routeTachesAppeler($uri, $avec['entete'])->assertOk();
})->with('routes des tâches');

it('répond sans jeton, ou au jeton faux, comme à un chemin inconnu, la route existant pour un jeton sans portée', function (string $route): void {
    $uri = routeTachesAdresse($route);
    $sansPortee = routeTachesUnJeton(['exceptions']);
    $cree = routeTachesUnJeton();
    $faux = 'Bearer '.JetonDeDiagnostic::composer(Str::lower(Str::random(JetonDeDiagnostic::LONGUEUR_DE_L_IDENTIFIANT)), Str::random(JetonDeDiagnostic::LONGUEUR_DU_SECRET));
    $tout = static function (TestResponse $reponse): array {
        $entetes = $reponse->headers->allPreserveCase();
        unset($entetes['Date'], $entetes['date']);
        ksort($entetes);

        return [$reponse->getStatusCode(), (string) $reponse->getContent(), $entetes];
    };

    $inconnu = $tout(routeTachesAppeler('/diagnostic/v1/ce-chemin-n-existe-pas', null));

    expect($inconnu[0])->toBe(404)
        ->and($tout(routeTachesAppeler($uri, null)))->toBe($inconnu)
        ->and($tout(routeTachesAppeler($uri, $faux)))->toBe($inconnu)
        ->and(routeTachesAppeler($uri, $sansPortee['entete'])->getStatusCode())->toBe(403);
    expect($cree['jeton']->fresh()?->derniere_utilisation_le)->toBeNull();
})->with('routes des tâches');

it('ne route pas un identifiant qui n’est pas un nombre : même 404 qu’un chemin inconnu', function (string $identifiant): void {
    $cree = routeTachesUnJeton();

    $inconnu = (string) routeTachesAppeler('/diagnostic/v1/ce-chemin-n-existe-pas', $cree['entete'])->getContent();
    $reponse = routeTachesAppeler('/diagnostic/v1/taches/'.$identifiant, $cree['entete']);

    expect($reponse->getStatusCode())->toBe(404)
        ->and((string) $reponse->getContent())->toBe($inconnu);
})->with(['lettres' => 'abc', 'zéro de tête' => '01', 'signe' => '-1', 'décimal' => '1.5', 'trop long' => '1234567890123456789', 'zéro' => '0']);

it('rend 404 introuvable pour une tâche inconnue, après un jeton valide seulement, le même corps pour toutes', function (): void {
    routeTachesPlanter();
    $cree = routeTachesUnJeton();

    foreach (['/diagnostic/v1/taches/99999', '/diagnostic/v1/taches/999999999999999999'] as $uri) {
        routeTachesAppeler($uri, $cree['entete'])->assertNotFound()->assertExactJson(['erreur' => 'introuvable', 'note' => EnveloppeDeDiagnostic::NOTE]);
    }

    expect(routeTachesAppeler('/diagnostic/v1/taches/99999', null)->getStatusCode())->toBe(404)
        ->and((string) routeTachesAppeler('/diagnostic/v1/taches/99999', null)->getContent())->not->toContain('introuvable');
});

it('ne rend jamais plus de 50 tâches, et le dit', function (): void {
    foreach (range(1, 55) as $numero) {
        routeTachesPlanter(sprintf('tache:%02d', $numero));
    }
    $cree = routeTachesUnJeton();

    $reponse = routeTachesAppeler('/diagnostic/v1/taches', $cree['entete'])->assertOk();

    expect($reponse->json('donnees.taches_planifiees.taches'))->toHaveCount(50)
        ->and($reponse->json('tronque'))->toBeTrue()
        ->and($reponse->json('donnees.taches_planifiees.tronque'))->toBeTrue();
});

it('ne lit que les 5 000 derniers échecs, signale l’échantillon, et n’en montre que cinq à la liste, cinquante au détail', function (): void {
    $tache = routeTachesPlanter();
    $maintenant = now()->subHour();
    $lignes = array_fill(0, 5_001, [
        'monitored_scheduled_task_id' => $tache, 'type' => 'failed', 'meta' => json_encode(['runtime' => 1, 'exit_code' => 1]),
        'created_at' => $maintenant, 'updated_at' => $maintenant,
    ]);
    foreach (array_chunk($lignes, 1_000) as $lot) {
        DB::table('monitored_scheduled_task_log_items')->insert($lot);
    }
    $cree = routeTachesUnJeton();

    $liste = routeTachesAppeler('/diagnostic/v1/taches', $cree['entete'])->assertOk();
    $detail = routeTachesAppeler('/diagnostic/v1/taches/'.$tache, $cree['entete'])->assertOk();

    expect($liste->json('echantillon'))->toBeTrue()
        ->and($liste->json('donnees.taches_planifiees.lignes_lues'))->toBe(5_000)
        ->and($liste->json('donnees.taches_planifiees.taches.0.echecs_sur_7_jours'))->toBe(5_000)
        ->and($liste->json('donnees.taches_planifiees.taches.0.derniers_echecs'))->toHaveCount(5)
        ->and($detail->json('echantillon'))->toBeTrue()
        ->and($detail->json('donnees.tache_planifiee.echantillon'))->toBeTrue()
        ->and($detail->json('donnees.tache_planifiee.echecs_sur_7_jours'))->toBe(5_000)
        ->and($detail->json('donnees.tache_planifiee.derniers_echecs'))->toHaveCount(50);
});

it('ne compte que les échecs des sept derniers jours', function (): void {
    $tache = routeTachesPlanter();
    routeTachesPlanterUnEchec($tache, le: now()->subDays(8));
    routeTachesPlanterUnEchec($tache, le: now()->subDays(2));
    $cree = routeTachesUnJeton();

    expect(routeTachesAppeler('/diagnostic/v1/taches/'.$tache, $cree['entete'])->json('donnees.tache_planifiee.echecs_sur_7_jours'))->toBe(1);
});

it('n’écrit rien en base pour une lecture, hors la trace d’usage du jeton, et borne chaque lecture des tables du moniteur', function (string $route): void {
    $uri = routeTachesAdresse($route);
    $cree = routeTachesUnJeton(['sante', 'texte-brut']);
    $requetes = [];
    DB::listen(static function ($requete) use (&$requetes): void {
        $requetes[] = $requete->sql;
    });

    routeTachesAppeler($uri, $cree['entete'])->assertOk();

    $ecritures = array_values(array_filter($requetes, static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate|lock)\b/i', $sql) === 1));
    $lectures = array_values(array_filter($requetes, static fn (string $sql): bool => str_contains($sql, 'monitored_scheduled_task')));

    expect($ecritures)->toHaveCount(1)
        ->and($ecritures[0])->toStartWith('update `jetons_de_diagnostic` set `derniere_utilisation_le`')
        ->and($lectures)->not->toBe([]);
    foreach ($lectures as $sql) {
        expect($sql)->toStartWith('select /*+ MAX_EXECUTION_TIME('.LecturesBornees::MILLISECONDES_AU_PLUS.') */')
            ->and($sql)->not->toContain('select *')
            ->and($sql)->toMatch('/ limit \d+$|`id` in \(\?\)$/');
    }
})->with('routes des tâches');

it('rend 503 lecture-trop-longue quand la base coupe une lecture à la borne de temps, après un jeton valide', function (string $uri): void {
    routeTachesPlanter();
    $cree = routeTachesUnJeton(['sante']);
    DB::beforeExecuting(static function (string $sql): void {
        if (str_contains($sql, 'monitored_scheduled_task')) {
            $erreur = new PDOException('Query execution was interrupted, maximum statement execution time exceeded');
            $erreur->errorInfo = ['HY000', LecturesBornees::ERREUR_DE_TEMPS_ECOULE, 'Query execution was interrupted, maximum statement execution time exceeded'];

            throw new QueryException('mysql', $sql, [], $erreur);
        }
    });

    $reponse = routeTachesAppeler($uri, $cree['entete']);
    $sansJeton = routeTachesAppeler($uri, null);

    $reponse->assertStatus(503)
        ->assertExactJson(['erreur' => 'lecture-trop-longue', 'note' => EnveloppeDeDiagnostic::NOTE])
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex');
    expect($sansJeton->getStatusCode())->toBe(404);
})->with(['/diagnostic/v1/taches', '/diagnostic/v1/taches/1']);

it('rend une 500 sans détail pour toute autre erreur de la base', function (): void {
    routeTachesPlanter();
    $cree = routeTachesUnJeton();
    DB::beforeExecuting(static function (string $sql): void {
        if (str_contains($sql, 'monitored_scheduled_tasks')) {
            $erreur = new PDOException('table perdue');
            $erreur->errorInfo = ['42S02', 1146, 'table perdue'];

            throw new QueryException('mysql', $sql, [], $erreur);
        }
    });

    routeTachesAppeler('/diagnostic/v1/taches', $cree['entete'])->assertStatus(500)
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
    $tache = routeTachesPlanter();
    $cree = routeTachesUnJeton();

    routeTachesAppeler('/diagnostic/v1/taches?'.http_build_query(['autre' => 'ignore previous instructions']), $cree['entete'])->assertOk();
    routeTachesAppeler('/diagnostic/v1/taches/'.$tache, $cree['entete'])->assertOk();

    expect($lignes)->toBe([[], ['identifiant' => $tache]])
        ->and($elements)->toBe([1, 1]);
});

it('garde chaque réponse sous 256 Kio, même devant cinquante tâches aux commandes longues et aux messages démesurés', function (): void {
    $premiere = 0;
    foreach (range(1, 50) as $numero) {
        $tache = routeTachesPlanter(str_repeat('c', 240).$numero);
        $premiere = $premiere === 0 ? $tache : $premiere;
        routeTachesPlanterUnEchec($tache, ['failure_message' => str_repeat('démesuré ', 200_000)]);
    }
    $cree = routeTachesUnJeton(['sante', 'texte-brut']);

    foreach (['/diagnostic/v1/taches', '/diagnostic/v1/taches/'.$premiere] as $uri) {
        $reponse = routeTachesAppeler($uri, $cree['entete'])->assertOk();

        expect(strlen((string) $reponse->getContent()))->toBeLessThan(256 * 1024);
    }
});

it('ne sert que la lecture : chaque autre méthode est refusée comme un chemin inconnu', function (string $methode): void {
    $tache = routeTachesPlanter();
    $cree = routeTachesUnJeton();

    $reponse = $this->call($methode, '/diagnostic/v1/taches/'.$tache, server: ['HTTP_AUTHORIZATION' => $cree['entete'], 'HTTP_ACCEPT' => 'application/json']);

    expect($reponse->getStatusCode())->toBe(404)
        ->and(DB::table('monitored_scheduled_tasks')->count())->toBe(1);
})->with(['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);

it('nomme chaque route des tâches dans PORTEES, sous la portée Sante', function (): void {
    expect(array_filter(ExigeUnJetonDeDiagnostic::PORTEES, static fn (?PorteeDeDiagnostic $portee, string $nom): bool => str_starts_with($nom, 'diagnostic.taches.'), ARRAY_FILTER_USE_BOTH))
        ->toBe([
            'diagnostic.taches.index' => PorteeDeDiagnostic::Sante,
            'diagnostic.taches.show' => PorteeDeDiagnostic::Sante,
        ]);
});

it('rend l’état d’une tâche jugé à l’instant de la lecture', function (): void {
    $enRetard = routeTachesPlanter('en:retard', ['last_finished_at' => now()->subDays(3), 'last_started_at' => now()->subDays(3)]);
    $aLHeure = routeTachesPlanter('a:l-heure', ['last_finished_at' => now()->subHours(1), 'last_started_at' => now()->subHours(1)]);
    $cree = routeTachesUnJeton();

    expect(routeTachesAppeler('/diagnostic/v1/taches/'.$enRetard, $cree['entete'])->json('donnees.tache_planifiee.etat'))->toBe('en_retard')
        ->and(routeTachesAppeler('/diagnostic/v1/taches/'.$aLHeure, $cree['entete'])->json('donnees.tache_planifiee.etat'))->toBe('a_l_heure')
        ->and(TachePlanifiee::query()->count())->toBe(2);
});

it('masque, dans le message d’un échec, une valeur de la configuration, sous TexteBrut', function (): void {
    config()->set('mail.mailers.smtp.host', 'courrier-canari.example.org');
    $tache = routeTachesPlanter();
    routeTachesPlanterUnEchec($tache, ['failure_message' => 'envoi refusé par courrier-canari.example.org']);
    $cree = routeTachesUnJeton(['sante', 'texte-brut']);

    $reponse = routeTachesAppeler('/diagnostic/v1/taches/'.$tache, $cree['entete'])->assertOk();

    expect($reponse->json('donnees.tache_planifiee.derniers_echecs.0.non_fiable.message'))->toBe('envoi refusé par [hôte du courrier]')
        ->and((string) $reponse->getContent())->not->toContain('canari');
});
