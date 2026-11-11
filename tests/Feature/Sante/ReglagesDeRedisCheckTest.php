<?php

declare(strict_types=1);

use App\Support\Sante\LecteurDeRedis;
use App\Support\Sante\ReglagesDeRedisCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\Support\RedisQuiRepond;

/*
 * Une seule instance de Redis porte la file, les sessions, les verrous, les
 * limiteurs et le cache, sous `allkeys-lru` : un cache plein évince d'abord ce
 * que personne n'a relu depuis longtemps, sessions et verrous compris, et rien
 * ne le disait (#2151). « Réglages de Redis » relit `INFO` en production et
 * passe au rouge dès que l'instance des données évince, refuse une écriture,
 * dépasse 75 % de son plafond ou ne persiste plus. Les tests remplacent la
 * réponse de Redis par celle que rend phpredis (`Tests\Support\RedisQuiRepond`) :
 * la suite n'a pas de Redis.
 */

/**
 * Le contrôle, jugé comme en production.
 */
function reglagesDeRedisEnProduction(): ReglagesDeRedisCheck
{
    app()->detectEnvironment(fn (): string => 'production');

    return ReglagesDeRedisCheck::new();
}

/**
 * Le cache posé sur une autre instance que les données, comme le prévoit
 * l'étape 2 de #2151.
 */
function reglagesDeRedisCacheAPart(): void
{
    Config::set('database.redis.default.host', 'redis');
    Config::set('database.redis.default.port', '6379');
    Config::set('database.redis.cache.host', 'redis-cache');
    Config::set('database.redis.cache.port', '6379');
}

beforeEach(function (): void {
    Config::set('database.redis.default.host', 'redis');
    Config::set('database.redis.default.port', '6379');
    Config::set('database.redis.default.url');
    Config::set('database.redis.cache.host', 'redis');
    Config::set('database.redis.cache.port', '6379');
    Config::set('database.redis.cache.url');
});

it('ne juge pas Redis hors production, sans le lire', function (): void {
    RedisQuiRepond::repond([]);

    $resultat = ReglagesDeRedisCheck::new()->run();

    expect($resultat->status->value)->toBe('ok')
        ->and($resultat->shortSummary)->toBe('Non jugé hors production')
        ->and($resultat->notificationMessage)->toContain('ne juge que la production');
});

it('porte un nom stable et un libellé français', function (): void {
    $controle = ReglagesDeRedisCheck::new();

    expect($controle->getName())->toBe('ReglagesDeRedis')
        ->and($controle->getLabel())->toBe('Réglages de Redis');
});

it('met Redis au vert : sous la moitié du plafond, sans éviction ni refus, persistance en ordre', function (): void {
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info()]);

    $resultat = reglagesDeRedisEnProduction()->run();

    expect($resultat->status->value)->toBe('ok', $resultat->notificationMessage)
        ->and($resultat->shortSummary)->toBe('Redis 8.10.3 · données 10 % du plafond · 0 éviction')
        ->and($resultat->meta)->toBe([
            'donnees' => [
                'version' => '8.10.3',
                'memoire_en_pourcentage' => 10,
                'politique' => 'allkeys-lru',
                'evictions' => 0,
                'refus_faute_de_memoire' => 0,
                'persistance' => ['aof' => 'ok', 'reecriture_aof' => 'ok', 'instantane' => 'ok'],
            ],
        ]);
});

it('ne garde de la réponse d’INFO que les champs de sa liste blanche', function (): void {
    $gardes = LecteurDeRedis::filtrer(RedisQuiRepond::info());

    expect(array_keys($gardes))->toEqualCanonicalizing([
        'redis_version',
        'uptime_in_seconds',
        'used_memory',
        'used_memory_peak',
        'maxmemory',
        'maxmemory_policy',
        'mem_fragmentation_ratio',
        'evicted_keys',
        'expired_keys',
        'keyspace_hits',
        'keyspace_misses',
        'aof_enabled',
        'aof_delayed_fsync',
        'aof_last_write_status',
        'aof_last_bgrewrite_status',
        'rdb_last_bgsave_status',
        'db0',
        'db1',
        'db1_distrib_strings_sizes',
    ])
        ->and($gardes)->not->toHaveKeys(['total_system_memory', 'total_system_memory_human', 'os', 'executable', 'run_id', 'hotkey_0'])
        ->and($gardes['mem_fragmentation_ratio'])->toBe('1.21')
        ->and($gardes['maxmemory'])->toBe('134217728');

    expect(LecteurDeRedis::filtrer(RedisQuiRepond::info(['errorstat_OOM' => 'count=3'])))->toHaveKey('errorstat_OOM', 'count=3');
});

/*
 * Predis range les champs par section ; phpredis les rend à plat. Le lecteur
 * lit les deux, et ne garde jamais un champ hors de sa liste, même rangé.
 */
it('lit aussi une réponse rangée par section', function (): void {
    $gardes = LecteurDeRedis::filtrer([
        'Server' => ['redis_version' => '8.10.3', 'os' => 'Linux'],
        'Memory' => ['used_memory' => 10, 'maxmemory' => 100, 'total_system_memory' => 1000],
        'Keyspace' => ['db0' => ['keys' => '1', 'expires' => '0']],
    ]);

    expect($gardes)->toBe(['redis_version' => '8.10.3', 'used_memory' => '10', 'maxmemory' => '100']);
});

it('ne montre jamais la mémoire ni le système de l’hôte, ni un nom de clé', function (): void {
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info(['evicted_keys' => 4, 'used_memory' => 120_000_000])]);

    $resultat = reglagesDeRedisEnProduction()->run();
    $texte = json_encode([$resultat->shortSummary, $resultat->notificationMessage, $resultat->meta], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($resultat->status->value)->toBe('failed');

    foreach (['16867414016', '15.71G', 'Linux', 'ada@example.org', '203.0.113.5', '134217728', '128.00M', '120000000', 'redis-server'] as $interdit) {
        expect($texte)->not->toContain($interdit);
    }
});

it('met Redis au rouge quand l’instance des données a évincé une clé, et dit quoi faire', function (): void {
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info(['evicted_keys' => 17])]);

    $resultat = reglagesDeRedisEnProduction()->run();

    expect($resultat->status->value)->toBe('failed')
        ->and($resultat->shortSummary)->toBe('Redis 8.10.3 · données 10 % du plafond · 17 évictions')
        ->and($resultat->notificationMessage)
        ->toContain('17 clés évincées depuis le démarrage de Redis')
        ->toContain('file, sessions et verrous')
        ->toContain('--maxmemory');
});

it('met Redis au rouge quand il refuse des écritures faute de mémoire', function (): void {
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info(['errorstat_OOM' => 'count=3'])]);

    $resultat = reglagesDeRedisEnProduction()->run();

    expect($resultat->status->value)->toBe('failed')
        ->and($resultat->notificationMessage)
        ->toContain('3 écritures refusées faute de mémoire')
        ->toContain('--maxmemory');
});

it('met Redis au rouge au-delà de 75 % du plafond des données, et à l’orange au-delà de 50 %', function (int $occupee, string $statut, string $message): void {
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info(['used_memory' => intdiv(134_217_728 * $occupee, 100)])]);

    $resultat = reglagesDeRedisEnProduction()->run();

    expect($resultat->status->value)->toBe($statut)
        ->and($resultat->shortSummary)->toBe("Redis 8.10.3 · données {$occupee} % du plafond · 0 éviction")
        ->and($resultat->notificationMessage)->toContain($message);
})->with([
    'sous la moitié' => [50, 'ok', ''],
    'au-delà de la moitié' => [51, 'warning', 'occupe 51 % de son plafond'],
    'aux trois quarts' => [75, 'warning', 'occupe 75 % de son plafond'],
    'au-delà des trois quarts' => [76, 'failed', 'occupe 76 % de son plafond'],
]);

it('met Redis au rouge quand sa persistance échoue', function (string $champ, string $nom): void {
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info([$champ => 'err'])]);

    $resultat = reglagesDeRedisEnProduction()->run();

    expect($resultat->status->value)->toBe('failed')
        ->and($resultat->notificationMessage)
        ->toContain("{$nom} en échec")
        ->toContain('journal du service redis');
})->with([
    'écriture de l’AOF' => ['aof_last_write_status', 'écriture de l\'AOF'],
    'réécriture de l’AOF' => ['aof_last_bgrewrite_status', 'réécriture de l\'AOF'],
    'instantané RDB' => ['rdb_last_bgsave_status', 'instantané RDB'],
]);

it('met Redis au rouge quand il ne répond pas, au lieu de planter sans message', function (): void {
    RedisQuiRepond::repond(['default' => new RuntimeException('Connection refused')]);

    $resultat = reglagesDeRedisEnProduction()->run();

    expect($resultat->status->value)->toBe('failed')
        ->and($resultat->shortSummary)->toBe('Redis ne répond pas')
        ->and($resultat->notificationMessage)
        ->toContain('ne répond pas à INFO')
        ->toContain('RuntimeException : Connection refused')
        ->toContain('REDIS_PASSWORD');
});

it('met Redis à l’orange quand INFO ne rend pas ce qu’il faut pour juger, plutôt que de le croire sain', function (): void {
    $info = RedisQuiRepond::info();
    unset($info['maxmemory'], $info['evicted_keys']);
    RedisQuiRepond::repond(['default' => $info]);

    $resultat = reglagesDeRedisEnProduction()->run();

    expect($resultat->status->value)->toBe('warning')
        ->and($resultat->shortSummary)->toBe('Redis 8.10.3 · données ? du plafond · ? éviction')
        ->and($resultat->notificationMessage)->toBe('Non vérifié : INFO n\'a pas rendu maxmemory ni evicted_keys pour l\'instance des données.');
});

it('met Redis à l’orange quand l’instance des données n’a pas de plafond', function (): void {
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info(['maxmemory' => 0])]);

    $resultat = reglagesDeRedisEnProduction()->run();

    expect($resultat->status->value)->toBe('warning')
        ->and($resultat->shortSummary)->toBe('Redis 8.10.3 · données sans plafond · 0 éviction')
        ->and($resultat->notificationMessage)->toContain('sans plafond (maxmemory 0)')->toContain('--maxmemory');
});

/*
 * Les compteurs d'INFO courent depuis le démarrage de Redis. Le contrôle
 * garde ceux du passage précédent dans le cache, et ne juge que ce qui a
 * changé depuis ; un redémarrage remet les compteurs à zéro, et ce qui suit
 * se compte depuis lui.
 */
it('ne juge que ce qui a changé depuis le passage précédent, redémarrage compris', function (): void {
    Carbon::setTestNow('2026-11-11 10:00:00');
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info(['evicted_keys' => 17, 'errorstat_OOM' => 'count=2', 'uptime_in_seconds' => 3600])]);
    expect(reglagesDeRedisEnProduction()->run()->status->value)->toBe('failed');

    Carbon::setTestNow('2026-11-11 10:05:00');
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info(['evicted_keys' => 17, 'errorstat_OOM' => 'count=2', 'uptime_in_seconds' => 3900])]);
    $calme = reglagesDeRedisEnProduction()->run();
    expect($calme->status->value)->toBe('ok', $calme->notificationMessage)
        ->and($calme->shortSummary)->toBe('Redis 8.10.3 · données 10 % du plafond · 17 évictions');

    Carbon::setTestNow('2026-11-11 10:10:00');
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info(['evicted_keys' => 20, 'errorstat_OOM' => 'count=2', 'uptime_in_seconds' => 4200])]);
    $nouvelles = reglagesDeRedisEnProduction()->run();
    expect($nouvelles->status->value)->toBe('failed')
        ->and($nouvelles->notificationMessage)->toContain('3 clés évincées depuis le passage précédent')
        ->and($nouvelles->notificationMessage)->not->toContain('écritures refusées');

    Carbon::setTestNow('2026-11-11 10:15:00');
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info(['evicted_keys' => 0, 'uptime_in_seconds' => 120])]);
    expect(reglagesDeRedisEnProduction()->run()->status->value)->toBe('ok');

    Carbon::setTestNow('2026-11-11 10:20:00');
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info(['evicted_keys' => 5, 'uptime_in_seconds' => 420])]);
    expect(reglagesDeRedisEnProduction()->run()->notificationMessage)->toContain('5 clés évincées depuis le passage précédent');

    Carbon::setTestNow('2026-11-11 10:25:00');
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info(['evicted_keys' => 2, 'uptime_in_seconds' => 60])]);
    expect(reglagesDeRedisEnProduction()->run()->notificationMessage)->toContain('2 clés évincées depuis le redémarrage de Redis');
});

it('met Redis à l’orange quand des synchronisations de l’AOF ont été différées depuis le passage précédent', function (): void {
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info(['aof_delayed_fsync' => 4])]);
    reglagesDeRedisEnProduction()->run();

    RedisQuiRepond::repond(['default' => RedisQuiRepond::info(['aof_delayed_fsync' => 9, 'uptime_in_seconds' => 3900])]);
    $resultat = reglagesDeRedisEnProduction()->run();

    expect($resultat->status->value)->toBe('warning')
        ->and($resultat->notificationMessage)->toContain('5 synchronisations de l\'AOF différées depuis le passage précédent');
});

it('ne lit qu’une instance tant que le cache partage celle des données', function (): void {
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info()]);

    expect(app(LecteurDeRedis::class)->instances())->toBe(['donnees' => 'default'])
        ->and(reglagesDeRedisEnProduction()->run()->status->value)->toBe('ok');
});

/*
 * Une fois le cache à part (#2151, étape 2), l'instance des données ne doit
 * plus rien évincer : sa politique devient `noeviction`, et le contrôle
 * l'exige. Les évictions du cache sont son métier : un orange seulement, et
 * le taux de réussite se lit sur lui.
 */
it('lit les deux instances une fois le cache à part, et exige noeviction sur les données', function (): void {
    reglagesDeRedisCacheAPart();
    RedisQuiRepond::repond([
        'default' => RedisQuiRepond::info(['maxmemory_policy' => 'noeviction', 'keyspace_hits' => 5, 'keyspace_misses' => 500]),
        'cache' => RedisQuiRepond::info(['used_memory' => intdiv(134_217_728 * 40, 100)]),
    ]);

    $resultat = reglagesDeRedisEnProduction()->run();

    expect(app(LecteurDeRedis::class)->instances())->toBe(['donnees' => 'default', 'cache' => 'cache'])
        ->and($resultat->status->value)->toBe('ok', $resultat->notificationMessage)
        ->and($resultat->shortSummary)->toBe('Redis 8.10.3 · données 10 % du plafond · 0 éviction · cache 40 % · réussite 97 %')
        ->and($resultat->meta)->toHaveKeys(['donnees', 'cache'])
        ->and($resultat->meta['cache'])->toMatchArray(['memoire_en_pourcentage' => 40, 'taux_de_reussite_en_pourcentage' => 97]);

    RedisQuiRepond::repond([
        'default' => RedisQuiRepond::info(['maxmemory_policy' => 'allkeys-lru']),
        'cache' => RedisQuiRepond::info(),
    ]);
    $evince = reglagesDeRedisEnProduction()->run();

    expect($evince->status->value)->toBe('failed')
        ->and($evince->notificationMessage)->toContain('politique allkeys-lru')->toContain('--maxmemory-policy noeviction');
});

it('met Redis à l’orange quand le cache à part évince, sans toucher aux données', function (): void {
    reglagesDeRedisCacheAPart();
    RedisQuiRepond::repond([
        'default' => RedisQuiRepond::info(['maxmemory_policy' => 'noeviction']),
        'cache' => RedisQuiRepond::info(['evicted_keys' => 250]),
    ]);

    $resultat = reglagesDeRedisEnProduction()->run();

    expect($resultat->status->value)->toBe('warning')
        ->and($resultat->notificationMessage)->toContain('Le cache a évincé 250 clés depuis le démarrage de Redis');
});

it('met Redis au rouge quand l’instance du cache à part ne répond pas', function (): void {
    reglagesDeRedisCacheAPart();
    RedisQuiRepond::repond([
        'default' => RedisQuiRepond::info(['maxmemory_policy' => 'noeviction']),
        'cache' => new RuntimeException('read error on connection'),
    ]);

    $resultat = reglagesDeRedisEnProduction()->run();

    expect($resultat->status->value)->toBe('failed')
        ->and($resultat->notificationMessage)->toContain('L\'instance du cache ne répond pas à INFO');
});
