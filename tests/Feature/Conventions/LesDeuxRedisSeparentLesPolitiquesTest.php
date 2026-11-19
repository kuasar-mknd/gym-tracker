<?php

declare(strict_types=1);

use App\Providers\SanteServiceProvider;
use App\Support\JournalDuDiagnostic;
use App\Support\MagasinDurable;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Support\Facades\Cache;
use ReflectionProperty;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Facades\Health;
use Symfony\Component\Yaml\Yaml;

/*
 * Un seul Redis sous `allkeys-lru` partageait l'éviction entre le cache, la
 * file, les sessions, les verrous et les limiteurs : remplir le cache
 * emportait une session ou un compteur de tentatives (#2151). La composition
 * en lance trois : `redis` (`noeviction`, persisté) porte tout ce qui ne doit
 * pas disparaître, `redis-cache` (`allkeys-lru`, sans persistance ni volume)
 * le cache seul, `redis-sessions` (`volatile-lru`, instantané) les sessions :
 * une requête sans cookie en ouvre une, et un afflux depuis beaucoup
 * d'adresses remplissait l'instance des données, qui refuse alors toute
 * écriture, file et verrous compris.
 */

/**
 * @return array<string, mixed>
 */
function deuxRedisService(string $nom): array
{
    $service = data_get(Yaml::parseFile(base_path('docker-compose.prod.yml')), "services.{$nom}");

    return is_array($service) ? array_combine(array_map(strval(...), array_keys($service)), $service) : [];
}

/**
 * Une valeur de la composition, lue par son chemin, en texte (vide si elle manque).
 *
 * @param  array<string, mixed>  $service
 */
function deuxRedisTexte(array $service, string $chemin): string
{
    $valeur = data_get($service, $chemin);

    if (is_array($valeur)) {
        return implode(' ', array_filter($valeur, is_string(...)));
    }

    return is_scalar($valeur) ? (string) $valeur : '';
}

/**
 * Les arguments de `redis-server` : chaque option avec sa valeur ; le texte
 * vide (`--save ''`) se lit comme une valeur vide.
 *
 * @param  array<string, mixed>  $service
 * @return array<string, string>
 */
function deuxRedisOptions(array $service): array
{
    $jetons = explode(' ', (string) preg_replace('/\s+/', ' ', trim(str_replace('\\', ' ', deuxRedisTexte($service, 'command')))));
    $options = [];

    foreach ($jetons as $rang => $jeton) {
        if (str_starts_with($jeton, '--')) {
            $valeur = $jetons[$rang + 1] ?? '';
            $options[$jeton] = str_starts_with($valeur, '--') ? '' : trim($valeur, "'\"");
        }
    }

    return $options;
}

it('range la file, les sessions et les verrous sous noeviction, et le cache sous allkeys-lru', function (): void {
    expect(deuxRedisOptions(deuxRedisService('redis')))->toHaveKey('--maxmemory-policy', 'noeviction')
        ->and(deuxRedisOptions(deuxRedisService('redis-cache')))->toHaveKey('--maxmemory-policy', 'allkeys-lru');
});

it('range les sessions sous volatile-lru, évinçables parce que chacune porte une échéance', function (): void {
    expect(deuxRedisOptions(deuxRedisService('redis-sessions')))->toHaveKey('--maxmemory-policy', 'volatile-lru');
});

it('garde les sessions par un instantané et un volume à elles, sans AOF', function (): void {
    $sessions = deuxRedisService('redis-sessions');
    $commande = (string) preg_replace('/\s+/', ' ', deuxRedisTexte($sessions, 'command'));

    expect(deuxRedisOptions($sessions))->toHaveKey('--appendonly', 'no')
        ->and($commande)->toContain('--save 300 1')
        ->and($sessions['volumes'] ?? [])->toBe(['redis_sessions_data:/data'])
        ->and(array_keys((array) data_get(Yaml::parseFile(base_path('docker-compose.prod.yml')), 'volumes')))->toContain('redis_sessions_data')
        ->and(deuxRedisService('redis')['volumes'] ?? [])->not->toContain('redis_sessions_data:/data');
});

it('ne persiste pas le cache, et ne lui donne aucun volume', function (): void {
    $cache = deuxRedisService('redis-cache');
    $options = deuxRedisOptions($cache);

    expect($options)->toHaveKey('--save', '')
        ->and($options)->toHaveKey('--appendonly', 'no')
        ->and($cache)->not->toHaveKey('volumes');
});

it('garde la persistance et son volume sur l’instance des données', function (): void {
    $donnees = deuxRedisService('redis');

    expect(deuxRedisOptions($donnees))->toHaveKey('--appendonly', 'yes')
        ->and($donnees['volumes'] ?? [])->toContain('redis_data:/data');
});

it('plafonne chaque instance, avec la marge d’un conteneur au-dessus', function (string $service, int $limiteMinimale): void {
    $composition = deuxRedisService($service);
    $plafond = deuxRedisOptions($composition)['--maxmemory'] ?? '';
    $limite = deuxRedisTexte($composition, 'deploy.resources.limits.memory');

    expect($plafond)->toMatch('/^\d+mb$/')
        ->and($limite)->toMatch('/^\d+M$/');

    expect((int) $limite)->toBeGreaterThanOrEqual((int) $plafond + $limiteMinimale);
})->with([
    'les données : la copie d’un fork compte dans le conteneur' => ['redis', 128],
    'le cache : fragmentation et modules de l’image' => ['redis-cache', 64],
    'les sessions : fragmentation, modules de l’image et copie de l’instantané' => ['redis-sessions', 64],
]);

it('exige le mot de passe de Redis, sur les trois instances, et le teste par un PONG', function (string $service): void {
    $composition = deuxRedisService($service);
    $sonde = deuxRedisTexte($composition, 'healthcheck.test.1');

    $commande = (string) preg_replace('/\s+/', ' ', deuxRedisTexte($composition, 'command'));

    expect($commande)->toContain('requirepass "$$mot_de_passe"')
        ->and($commande)->not->toContain('--requirepass')
        ->and(deuxRedisTexte($composition, 'environment.REDIS_PASSWORD'))->toBe('${REDIS_PASSWORD:?mot de passe de Redis}')
        ->and(deuxRedisTexte($composition, 'environment.REDISCLI_AUTH'))->toBe('${REDIS_PASSWORD}')
        ->and($sonde)->toContain('PONG')
        ->and($sonde)->not->toContain('-a ');
})->with(['redis', 'redis-cache', 'redis-sessions']);

it('ne publie aucun port et ne joint que le réseau backend, pour les trois instances', function (string $service): void {
    $composition = deuxRedisService($service);

    expect($composition)->not->toHaveKey('ports')
        ->and($composition)->not->toHaveKey('expose')
        ->and($composition['networks'] ?? [])->toBe(['backend']);
})->with(['redis', 'redis-cache', 'redis-sessions']);

it('branche le cache, les sessions et les magasins durables sur app, worker et scheduler', function (string $service): void {
    $composition = deuxRedisService($service);

    expect(deuxRedisTexte($composition, 'environment.REDIS_CACHE_HOST'))->toBe('redis-cache')
        ->and(deuxRedisTexte($composition, 'environment.REDIS_CACHE_PORT'))->toBe('6379')
        ->and(deuxRedisTexte($composition, 'environment.CACHE_DURABLE_STORE'))->toBe('redis-durable')
        ->and(deuxRedisTexte($composition, 'environment.SESSION_DRIVER'))->toBe('redis')
        ->and(deuxRedisTexte($composition, 'environment.SESSION_CONNECTION'))->toBe('sessions')
        ->and(deuxRedisTexte($composition, 'environment.REDIS_SESSIONS_HOST'))->toBe('redis-sessions')
        ->and(deuxRedisTexte($composition, 'environment.REDIS_SESSIONS_PORT'))->toBe('6379');

    foreach (['redis', 'redis-cache', 'redis-sessions'] as $dependance) {
        expect(deuxRedisTexte($composition, "depends_on.{$dependance}.condition"))->toBe('service_healthy');
    }
})->with(['app', 'worker', 'scheduler']);

it('range les limiteurs et les battements sur la connexion des données quand le magasin durable est posé', function (): void {
    config()->set('cache.limiter', 'redis-durable');

    expect(config('cache.stores.redis-durable.connection'))->toBe('default')
        ->and(config('cache.stores.redis.connection'))->toBe('cache');
});

it('retombe sur une seule instance quand REDIS_CACHE_HOST, REDIS_SESSIONS_HOST et leurs ports manquent', function (): void {
    expect(config('database.redis.cache.host'))->toBe(config('database.redis.default.host'))
        ->and(config('database.redis.cache.port'))->toBe(config('database.redis.default.port'))
        ->and(config('database.redis.sessions.host'))->toBe(config('database.redis.default.host'))
        ->and(config('database.redis.sessions.port'))->toBe(config('database.redis.default.port'))
        ->and(config('database.redis.sessions.password'))->toBe(config('database.redis.default.password'));
});

it('range les sessions sur leur connexion sans déplacer le cache de l’application', function (): void {
    config()->set('session.driver', 'redis');
    config()->set('session.connection', 'sessions');
    config()->set('session.store');

    $cacheAvant = deuxRedisConnexionDuMagasin(Cache::store('redis')->getStore());
    $magasin = app('session')->driver()->getHandler();

    expect($magasin)->toBeInstanceOf(CacheBasedSessionHandler::class);
    assert($magasin instanceof CacheBasedSessionHandler);

    expect(deuxRedisConnexionDuMagasin($magasin->getCache()->getStore()))->toBe('sessions')
        ->and($cacheAvant)->toBe('cache')
        ->and(deuxRedisConnexionDuMagasin(Cache::store('redis')->getStore()))->toBe('cache');
});

/**
 * La connexion nommée d'un magasin Redis, lue sans l'ouvrir (la suite n'a pas
 * de Redis).
 */
function deuxRedisConnexionDuMagasin(object $magasin): mixed
{
    return new ReflectionProperty($magasin, 'connection')->getValue($magasin);
}

it('range ce qui doit survivre au cache sur le magasin que nomme cache.limiter', function (): void {
    config()->set('cache.stores.durable-essai', ['driver' => 'array']);
    config()->set('cache.limiter', 'durable-essai');
    MagasinDurable::depot()->put('durable:essai', 1, 60);

    expect(Cache::store('durable-essai')->get('durable:essai'))->toBe(1)
        ->and(Cache::store()->get('durable:essai'))->toBeNull();

    config()->set('cache.limiter');

    expect(MagasinDurable::depot()->get('durable:essai'))->toBeNull();
});

it('juge l’écriture en SQL du diagnostic sur le magasin durable, pas sur le défaut', function (string $defaut, ?string $durable, bool $attendu): void {
    config()->set('cache.stores.sql-essai', ['driver' => 'database']);
    config()->set('cache.default', $defaut);
    config()->set('cache.limiter', $durable);

    expect(JournalDuDiagnostic::lEcritureSeraitEnSql())->toBe($attendu)
        ->and(MagasinDurable::nom())->toBe($durable ?? $defaut);
})->with([
    'défaut en mémoire, durable en SQL' => ['array', 'sql-essai', true],
    'défaut en SQL, durable en mémoire' => ['sql-essai', 'array', false],
    'sans durable, le défaut en SQL' => ['sql-essai', null, true],
    'sans durable, le défaut en mémoire' => ['array', null, false],
]);

it('ne laisse ni les alertes, ni le journal de diagnostic, ni les compteurs et écarts de santé dans le cache évinçable', function (string $fichier): void {
    expect(file_get_contents(base_path($fichier)))->not->toContain('Cache::');
})->with([
    'app/Support/AlertesDeDiagnostic.php',
    'app/Support/JournalDuDiagnostic.php',
    'app/Support/Sante/VersionsDesServicesCheck.php',
    'app/Support/Sante/ReglagesDeRedisCheck.php',
    'app/Support/Sante/AccesDeDiagnosticCheck.php',
]);

it('passe les battements de la file et du planificateur par le magasin durable, quand il est posé', function (?string $durable, string $attendu): void {
    config()->set('cache.limiter', $durable);
    Health::clearChecks();
    $fournisseur = new SanteServiceProvider(app());
    $fournisseur->boot();

    $magasins = Health::registeredChecks()
        ->filter(static fn (Check $controle): bool => $controle instanceof QueueCheck || $controle instanceof ScheduleCheck)
        ->map(static fn (QueueCheck|ScheduleCheck $controle): string => $controle->getCacheStoreName());

    expect($magasins)->toHaveCount(2)
        ->and($magasins->unique()->values()->all())->toBe([$attendu]);
})->with([
    'posé' => ['redis-durable', 'redis-durable'],
    'absent' => [null, 'array'],
    'vide' => ['', 'array'],
]);
