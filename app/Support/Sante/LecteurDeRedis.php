<?php

declare(strict_types=1);

namespace App\Support\Sante;

use Illuminate\Support\Facades\Redis;

/**
 * Lit `INFO` sur les instances de Redis de l'application, en lecture seule,
 * et n'en garde que les champs de sa liste blanche (#2151). `INFO` sans filtre
 * donne la mémoire et le système de l'hôte (`total_system_memory`, `os`), le
 * chemin du binaire, et, depuis Redis 8.10, des noms de clés servies souvent ;
 * aucun ne sort d'ici, ni vers la page « Santé », ni vers un relevé recopié
 * dans une issue publique.
 *
 * `ReglagesDeRedisCheck`, `VersionsDesServicesCheck` et `redis:relever` le
 * reçoivent du conteneur : les tests remplacent `infoBrute()`, la suite
 * n'ayant pas de Redis.
 */
class LecteurDeRedis
{
    /**
     * Les seuls champs gardés : version, mémoire et plafond (en octets, que
     * personne n'affiche : le contrôle et le relevé n'en montrent que le
     * rapport), politique, évictions et refus, persistance, et, par base,
     * le nombre de clés et la taille des valeurs du cache, sans aucun nom.
     *
     * @var list<string>
     */
    public const array CHAMPS = [
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
        'errorstat_OOM',
        'aof_enabled',
        'aof_delayed_fsync',
        'aof_last_write_status',
        'aof_last_bgrewrite_status',
        'rdb_last_bgsave_status',
        'db0',
        'db1',
        'db1_distrib_strings_sizes',
    ];

    /**
     * Les instances à lire, par rôle : celle des données (connexion
     * `default` : file, sessions, verrous), et celle du cache seulement
     * quand la connexion `cache` mène ailleurs. Tant qu'elles partagent
     * l'adresse, c'est la même instance, lue une fois.
     *
     * @return array<string, string> le nom de la connexion, par rôle
     */
    public function instances(): array
    {
        $instances = ['donnees' => 'default'];

        if ($this->adresse('default') !== $this->adresse('cache')) {
            $instances['cache'] = 'cache';
        }

        return $instances;
    }

    /**
     * Les champs de la liste blanche que rend `INFO` sur la connexion donnée.
     * Une erreur de connexion remonte telle quelle : à l'appelant d'en faire
     * un rouge.
     *
     * @return array<string, string>
     */
    public function lire(string $connexion): array
    {
        return self::filtrer($this->infoBrute($connexion));
    }

    /**
     * Ne garde de la réponse que les champs de `CHAMPS`, en chaînes. phpredis
     * rend un tableau à plat ; predis le range par section : les deux se
     * lisent, et une valeur qui n'est pas un scalaire ne se garde jamais.
     *
     * @param  array<array-key, mixed>  $info
     * @return array<string, string>
     */
    public static function filtrer(array $info): array
    {
        $aPlat = [];

        foreach ($info as $cle => $valeur) {
            if (is_array($valeur)) {
                foreach ($valeur as $sousCle => $sousValeur) {
                    $aPlat[(string) $sousCle] = $sousValeur;
                }

                continue;
            }

            $aPlat[(string) $cle] = $valeur;
        }

        $gardes = [];

        foreach ($aPlat as $cle => $valeur) {
            if (in_array($cle, self::CHAMPS, true) && is_scalar($valeur)) {
                $gardes[$cle] = (string) $valeur;
            }
        }

        return $gardes;
    }

    /**
     * La réponse brute d'`INFO everything`, la seule section qui couvre
     * aussi `errorstats` et `keysizes` ; elle ne sort jamais d'ici sans
     * passer par `filtrer()`.
     *
     * @return array<array-key, mixed>
     */
    protected function infoBrute(string $connexion): array
    {
        $info = Redis::connection($connexion)->command('info', ['everything']);

        return is_array($info) ? $info : [];
    }

    /**
     * Ce qui désigne l'instance d'une connexion : son URL, ou son hôte et
     * son port.
     */
    private function adresse(string $connexion): string
    {
        $url = config("database.redis.{$connexion}.url");
        $hote = config("database.redis.{$connexion}.host");
        $port = config("database.redis.{$connexion}.port");

        return implode('|', [
            is_scalar($url) ? (string) $url : '',
            is_scalar($hote) ? (string) $hote : '',
            is_scalar($port) ? (string) $port : '',
        ]);
    }
}
