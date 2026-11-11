<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Sante\LecteurDeRedis;
use LogicException;
use Override;
use Throwable;

/**
 * Remplace la lecture d'`INFO` de `LecteurDeRedis` par des réponses écrites
 * (#2151) : la suite n'a pas de Redis, et la lecture d'un vrai serveur ne
 * donnerait ni éviction, ni plein, ni persistance en échec. Le filtrage du
 * lecteur, lui, reste le vrai : chaque réponse passe par sa liste blanche.
 */
final class RedisQuiRepond
{
    /**
     * Une réponse d'`INFO everything` telle que phpredis la rend : un tableau
     * à plat, des entiers et des flottants là où la valeur est un nombre, et
     * ce que le lecteur ne doit jamais garder (mémoire et système de l'hôte,
     * chemin du binaire, une clé servie souvent, qui porte une adresse).
     *
     * @param  array<string, int|float|string>  $surcharges
     * @return array<string, int|float|string>
     */
    public static function info(array $surcharges = []): array
    {
        return [
            'redis_version' => '8.10.3',
            'redis_mode' => 'standalone',
            'os' => 'Linux 6.1.0 x86_64',
            'executable' => '/data/redis-server',
            'config_file' => '',
            'run_id' => '3af84ee04d2b25a15c9ae9a3b9448d0b40c9ce61',
            'tcp_port' => 6379,
            'uptime_in_seconds' => 3600,
            'connected_clients' => 4,
            'used_memory' => 13_421_772,
            'used_memory_human' => '12.80M',
            'used_memory_peak' => 20_132_659,
            'used_memory_peak_human' => '19.20M',
            'total_system_memory' => 16_867_414_016,
            'total_system_memory_human' => '15.71G',
            'maxmemory' => 134_217_728,
            'maxmemory_human' => '128.00M',
            'maxmemory_policy' => 'allkeys-lru',
            'mem_fragmentation_ratio' => 1.21,
            'loading' => 0,
            'rdb_last_bgsave_status' => 'ok',
            'aof_enabled' => 1,
            'aof_last_bgrewrite_status' => 'ok',
            'aof_last_write_status' => 'ok',
            'aof_delayed_fsync' => 0,
            'evicted_keys' => 0,
            'expired_keys' => 120,
            'keyspace_hits' => 970,
            'keyspace_misses' => 30,
            'hotkey_0' => 'login:ada@example.org|203.0.113.5',
            'db0' => 'keys=12,expires=10,avg_ttl=3600,subexpiry=0',
            'db1' => 'keys=40,expires=40,avg_ttl=1800,subexpiry=0',
            'db1_distrib_strings_sizes' => '1K=30,4K=10',
            ...$surcharges,
        ];
    }

    /**
     * Chaque connexion rend sa réponse, ou lève l'erreur donnée. Une
     * connexion absente lève aussi : le code jugé n'avait pas à la lire.
     *
     * @param  array<string, array<string, int|float|string>|Throwable>  $reponses
     */
    public static function repond(array $reponses): void
    {
        app()->instance(LecteurDeRedis::class, new class($reponses) extends LecteurDeRedis
        {
            /**
             * @param  array<string, array<string, int|float|string>|Throwable>  $reponses
             */
            public function __construct(private readonly array $reponses)
            {
            }

            #[Override]
            protected function infoBrute(string $connexion): array
            {
                $reponse = $this->reponses[$connexion] ?? throw new LogicException("Le code jugé a lu la connexion Redis {$connexion}.");

                if ($reponse instanceof Throwable) {
                    throw $reponse;
                }

                return $reponse;
            }
        });
    }
}
