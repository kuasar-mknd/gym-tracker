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
 * `ReglagesDeRedisCheck`, `LecteurDesVersionsDesServices` et `redis:relever`
 * le reçoivent du conteneur : les tests remplacent `infoBrute()`, la suite
 * n'ayant pas de Redis.
 */
class LecteurDeRedis
{
    /**
     * Les seuls champs gardés : version, identité du processus et âge,
     * mémoire et plafond (en octets, que rien n'affiche : le contrôle et le
     * relevé n'en montrent que le rapport), politique, évictions et refus,
     * persistance, et, par base, le nombre de clés et la taille des valeurs
     * du cache, sans aucun nom. `run_id` est un identifiant aléatoire tiré à
     * chaque démarrage, qui ne dit rien de l'hôte ni d'une clé : le contrôle
     * le garde d'un passage à l'autre pour voir un redémarrage, et rien ne
     * l'affiche.
     *
     * @var list<string>
     */
    public const array CHAMPS = [
        'redis_version',
        'run_id',
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
     * Ce que le relevé imprime tel quel, dans cet ordre, après la version et
     * la mémoire : jamais une quantité de mémoire.
     *
     * @var list<string>
     */
    private const array RELEVES_TELS_QUELS = [
        'maxmemory_policy',
        'mem_fragmentation_ratio',
        'evicted_keys',
        'expired_keys',
        'keyspace_hits',
        'keyspace_misses',
        'errorstat_OOM',
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
        $gardes = [];

        foreach (self::aPlat($info) as $cle => $valeur) {
            if (in_array($cle, self::CHAMPS, true) && is_scalar($valeur)) {
                $gardes[$cle] = (string) $valeur;
            }
        }

        return $gardes;
    }

    /**
     * La part de son plafond qu'occupe l'instance, d'après le champ donné
     * (`used_memory`, ou `used_memory_peak` pour le pic) : `false` sans
     * plafond (`maxmemory` 0), `null` quand `INFO` ne la rend pas.
     *
     * @param  array<string, string>  $champs
     */
    public static function part(array $champs, string $champ = 'used_memory'): float|false|null
    {
        $occupee = self::entier($champs[$champ] ?? null);
        $plafond = self::entier($champs['maxmemory'] ?? null);

        if ($occupee === null || $plafond === null) {
            return null;
        }

        return $plafond === 0 ? false : $occupee / $plafond;
    }

    /**
     * Le relevé d'une instance, une ligne par champ (`champ: valeur`) : la
     * version, la mémoire et son pic en pourcentage du plafond, puis les
     * champs de `RELEVES_TELS_QUELS` que l'instance a rendus.
     *
     * @param  array<string, string>  $champs
     * @return list<string>
     */
    public static function releve(array $champs): array
    {
        $lignes = isset($champs['redis_version']) ? ['redis_version: '.$champs['redis_version']] : [];
        $lignes = [...$lignes, ...self::lignesDeMemoire($champs)];

        foreach (self::RELEVES_TELS_QUELS as $champ) {
            if (isset($champs[$champ])) {
                $lignes[] = "{$champ}: {$champs[$champ]}";
            }
        }

        return $lignes;
    }

    /**
     * Un nombre entier positif écrit en chiffres, ou `null`.
     */
    public static function entier(?string $valeur): ?int
    {
        return $valeur !== null && ctype_digit($valeur) ? (int) $valeur : null;
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
     * La réponse à plat : les champs d'une section rangée remontent d'un
     * niveau.
     *
     * @param  array<array-key, mixed>  $info
     * @return array<string, mixed>
     */
    private static function aPlat(array $info): array
    {
        $aPlat = [];

        foreach ($info as $cle => $valeur) {
            $champs = is_array($valeur) ? $valeur : [$cle => $valeur];

            foreach ($champs as $sousCle => $sousValeur) {
                $aPlat[(string) $sousCle] = $sousValeur;
            }
        }

        return $aPlat;
    }

    /**
     * @param  array<string, string>  $champs
     * @return list<string>
     */
    private static function lignesDeMemoire(array $champs): array
    {
        $lignes = [];

        foreach (['memoire' => 'used_memory', 'memoire_au_pic' => 'used_memory_peak'] as $nom => $champ) {
            $part = self::part($champs, $champ);

            if ($part === false) {
                return ['memoire: sans plafond (maxmemory 0)'];
            }

            if ($part !== null) {
                $lignes[] = sprintf('%s: %d %% du plafond', $nom, (int) round($part * 100));
            }
        }

        return $lignes;
    }

    /**
     * Ce qui désigne l'instance d'une connexion : son URL, ou son hôte et
     * son port.
     */
    private function adresse(string $connexion): string
    {
        $morceaux = [];

        foreach (['url', 'host', 'port'] as $cle) {
            $valeur = config("database.redis.{$connexion}.{$cle}");
            $morceaux[] = is_scalar($valeur) ? (string) $valeur : '';
        }

        return implode('|', $morceaux);
    }
}
