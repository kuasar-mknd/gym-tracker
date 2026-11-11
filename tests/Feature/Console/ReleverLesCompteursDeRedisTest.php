<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\Support\RedisQuiRepond;

/*
 * `php artisan redis:relever` (#2151, étape 0 sans console Redis) : les
 * compteurs de Redis qu'une issue publique peut recevoir, tels quels, et
 * rien d'autre. `INFO` sans filtre donne la mémoire et le système de
 * l'hôte ; `--bigkeys` et ses voisines nomment des clés, dont celle du
 * compteur des tentatives de connexion, qui porte une adresse et une IP. La
 * mémoire sort en pourcentage du plafond : un plafond relevé dirait sinon ce
 * que l'hôte donne à Redis. La suite n'a pas de Redis : les réponses sont
 * écrites (`Tests\Support\RedisQuiRepond`).
 */

beforeEach(function (): void {
    Config::set('database.redis.default.host', 'redis');
    Config::set('database.redis.default.port', '6379');
    Config::set('database.redis.cache.host', 'redis');
    Config::set('database.redis.cache.port', '6379');
});

it('n’imprime que les champs de sa liste blanche, la mémoire en pourcentage du plafond', function (): void {
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info(['errorstat_OOM' => 'count=3'])]);

    expect(Artisan::call('redis:relever'))->toBe(0)
        ->and(Artisan::output())->toBe(implode("\n", [
            'Instance des données (connexion default)',
            'redis_version: 8.10.3',
            'memoire: 10 % du plafond',
            'memoire_au_pic: 15 % du plafond',
            'maxmemory_policy: allkeys-lru',
            'mem_fragmentation_ratio: 1.21',
            'evicted_keys: 0',
            'expired_keys: 120',
            'keyspace_hits: 970',
            'keyspace_misses: 30',
            'errorstat_OOM: count=3',
            'aof_delayed_fsync: 0',
            'aof_last_write_status: ok',
            'aof_last_bgrewrite_status: ok',
            'rdb_last_bgsave_status: ok',
            'db0: keys=12,expires=10,avg_ttl=3600,subexpiry=0',
            'db1: keys=40,expires=40,avg_ttl=1800,subexpiry=0',
            'db1_distrib_strings_sizes: 1K=30,4K=10',
            '',
        ]));
});

it('ne laisse passer ni la mémoire de l’hôte, ni son système, ni un nom de clé, ni un plafond en octets', function (): void {
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info()]);

    Artisan::call('redis:relever');
    $sortie = Artisan::output();

    foreach (['total_system_memory', '16867414016', '15.71G', 'os:', 'Linux', 'executable', 'run_id', 'hotkey', 'ada@example.org', '203.0.113.5', '134217728', '128.00M', '13421772', '12.80M', 'uptime'] as $interdit) {
        expect($sortie)->not->toContain($interdit);
    }
});

it('dit d’une instance sans plafond qu’elle n’en a pas, sans donner sa mémoire', function (): void {
    RedisQuiRepond::repond(['default' => RedisQuiRepond::info(['maxmemory' => 0])]);

    Artisan::call('redis:relever');

    $sortie = Artisan::output();

    expect($sortie)->toContain("memoire: sans plafond (maxmemory 0)\n")
        ->and($sortie)->not->toContain('memoire_au_pic')
        ->and($sortie)->not->toContain('13421772');
});

it('relève aussi le cache quand il vit sur une autre instance', function (): void {
    Config::set('database.redis.cache.host', 'redis-cache');
    RedisQuiRepond::repond([
        'default' => RedisQuiRepond::info(['maxmemory_policy' => 'noeviction']),
        'cache' => RedisQuiRepond::info(['used_memory' => intdiv(134_217_728 * 40, 100)]),
    ]);

    expect(Artisan::call('redis:relever'))->toBe(0);

    $sortie = Artisan::output();

    expect($sortie)->toContain("Instance des données (connexion default)\nredis_version: 8.10.3\nmemoire: 10 % du plafond\n")
        ->toContain("\n\nInstance du cache (connexion cache)\nredis_version: 8.10.3\nmemoire: 40 % du plafond\n")
        ->toContain("maxmemory_policy: noeviction\n");
});

it('échoue en le disant quand Redis ne répond pas', function (): void {
    RedisQuiRepond::repond(['default' => new RuntimeException('Connection refused')]);

    expect(Artisan::call('redis:relever'))->toBe(1)
        ->and(Artisan::output())->toContain('Instance des données (connexion default) : pas de réponse à INFO (RuntimeException : Connection refused).');
});
