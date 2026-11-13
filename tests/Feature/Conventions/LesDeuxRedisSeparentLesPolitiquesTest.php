<?php

declare(strict_types=1);

use App\Providers\SanteServiceProvider;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Facades\Health;
use Symfony\Component\Yaml\Yaml;

/*
 * Un seul Redis sous `allkeys-lru` partageait l'éviction entre le cache, la
 * file, les sessions, les verrous et les limiteurs : remplir le cache
 * emportait une session ou un compteur de tentatives (#2151). La composition
 * en lance deux : `redis` (`noeviction`, persisté) porte tout ce qui ne doit
 * pas disparaître, `redis-cache` (`allkeys-lru`, sans persistance ni volume)
 * le cache seul.
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
    $jetons = explode(' ', (string) preg_replace('/\s+/', ' ', trim(deuxRedisTexte($service, 'command'))));
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
]);

it('exige le mot de passe de Redis, sur les deux instances, et le teste par un PONG', function (string $service): void {
    $composition = deuxRedisService($service);
    $sonde = deuxRedisTexte($composition, 'healthcheck.test.1');

    expect(preg_replace('/\s+/', ' ', deuxRedisTexte($composition, 'command')))->toContain('--requirepass ${REDIS_PASSWORD:?mot de passe de Redis}')
        ->and(deuxRedisTexte($composition, 'environment.REDISCLI_AUTH'))->toBe('${REDIS_PASSWORD}')
        ->and($sonde)->toContain('PONG')
        ->and($sonde)->not->toContain('-a ');
})->with(['redis', 'redis-cache']);

it('ne publie aucun port et ne joint que le réseau backend, pour les deux instances', function (string $service): void {
    $composition = deuxRedisService($service);

    expect($composition)->not->toHaveKey('ports')
        ->and($composition)->not->toHaveKey('expose')
        ->and($composition['networks'] ?? [])->toBe(['backend']);
})->with(['redis', 'redis-cache']);

it('branche le cache et les magasins durables sur app, worker et scheduler', function (string $service): void {
    $composition = deuxRedisService($service);

    expect(deuxRedisTexte($composition, 'environment.REDIS_CACHE_HOST'))->toBe('redis-cache')
        ->and(deuxRedisTexte($composition, 'environment.REDIS_CACHE_PORT'))->toBe('6379')
        ->and(deuxRedisTexte($composition, 'environment.CACHE_DURABLE_STORE'))->toBe('redis-durable');

    foreach (['redis', 'redis-cache'] as $dependance) {
        expect(deuxRedisTexte($composition, "depends_on.{$dependance}.condition"))->toBe('service_healthy');
    }
})->with(['app', 'worker', 'scheduler']);

it('range les limiteurs et les battements sur la connexion des données quand le magasin durable est posé', function (): void {
    config()->set('cache.limiter', 'redis-durable');

    expect(config('cache.stores.redis-durable.connection'))->toBe('default')
        ->and(config('cache.stores.redis.connection'))->toBe('cache');
});

it('retombe sur une seule instance quand REDIS_CACHE_HOST et REDIS_CACHE_PORT manquent', function (): void {
    expect(config('database.redis.cache.host'))->toBe(config('database.redis.default.host'))
        ->and(config('database.redis.cache.port'))->toBe(config('database.redis.default.port'));
});

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
