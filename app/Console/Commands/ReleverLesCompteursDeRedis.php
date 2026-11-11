<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Sante\LecteurDeRedis;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * `php artisan redis:relever` : les compteurs de chaque instance de Redis
 * que nomme la liste blanche de `LecteurDeRedis`, et rien d'autre (#2151,
 * étape 0 sans console Redis). Sa sortie se recopie telle quelle dans une
 * issue publique : ni la mémoire ni le système de l'hôte, ni aucun nom de
 * clé, et la mémoire en pourcentage du plafond, jamais en octets, un
 * plafond relevé disant sinon ce que l'hôte donne à Redis. Elle ne fait que
 * lire.
 */
final class ReleverLesCompteursDeRedis extends Command
{
    /**
     * Les champs imprimés tels quels, dans cet ordre, après la version et
     * les deux lignes de mémoire.
     *
     * @var list<string>
     */
    private const array TELS_QUELS = [
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

    #[\Override]
    protected $signature = 'redis:relever';

    #[\Override]
    protected $description = 'Relève les compteurs de Redis que nomme sa liste blanche, la mémoire en pourcentage du plafond, à recopier tels quels';

    public function handle(LecteurDeRedis $lecteur): int
    {
        $code = self::SUCCESS;
        $premiere = true;

        foreach ($lecteur->instances() as $role => $connexion) {
            if (! $premiere) {
                $this->newLine();
            }

            $premiere = false;
            $titre = sprintf('Instance %s (connexion %s)', $role === 'donnees' ? 'des données' : 'du cache', $connexion);

            try {
                $champs = $lecteur->lire($connexion);
            } catch (Throwable $erreur) {
                $this->error(sprintf('%s : pas de réponse à INFO (%s : %s).', $titre, class_basename($erreur), Str::limit($erreur->getMessage(), 200)));
                $code = self::FAILURE;

                continue;
            }

            $this->line($titre);

            foreach ($this->lignes($champs) as $ligne) {
                $this->line($ligne);
            }
        }

        return $code;
    }

    /**
     * @param  array<string, string>  $champs
     * @return list<string>
     */
    private function lignes(array $champs): array
    {
        $lignes = [];

        if (isset($champs['redis_version'])) {
            $lignes[] = 'redis_version: '.$champs['redis_version'];
        }

        $plafond = $this->entier($champs['maxmemory'] ?? null);

        if ($plafond === 0) {
            $lignes[] = 'memoire: sans plafond (maxmemory 0)';
        } elseif ($plafond !== null) {
            foreach (['memoire' => 'used_memory', 'memoire_au_pic' => 'used_memory_peak'] as $nom => $champ) {
                $occupee = $this->entier($champs[$champ] ?? null);

                if ($occupee !== null) {
                    $lignes[] = sprintf('%s: %d %% du plafond', $nom, (int) round($occupee / $plafond * 100));
                }
            }
        }

        foreach (self::TELS_QUELS as $champ) {
            if (isset($champs[$champ])) {
                $lignes[] = "{$champ}: {$champs[$champ]}";
            }
        }

        return $lignes;
    }

    private function entier(?string $valeur): ?int
    {
        return $valeur !== null && ctype_digit($valeur) ? (int) $valeur : null;
    }
}
