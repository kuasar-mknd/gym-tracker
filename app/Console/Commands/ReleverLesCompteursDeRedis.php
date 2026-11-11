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
    /** @var array<string, string> */
    private const array INSTANCES = ['donnees' => 'Instance des données', 'cache' => 'Instance du cache'];

    #[\Override]
    protected $signature = 'redis:relever';

    #[\Override]
    protected $description = 'Relève les compteurs de Redis que nomme sa liste blanche, la mémoire en pourcentage du plafond, à recopier tels quels';

    public function handle(LecteurDeRedis $lecteur): int
    {
        $code = self::SUCCESS;
        $blocs = [];

        foreach ($lecteur->instances() as $role => $connexion) {
            $titre = sprintf('%s (connexion %s)', self::INSTANCES[$role], $connexion);

            try {
                $blocs[] = implode("\n", [$titre, ...LecteurDeRedis::releve($lecteur->lire($connexion))]);
            } catch (Throwable $erreur) {
                $blocs[] = sprintf('%s : pas de réponse à INFO (%s : %s).', $titre, class_basename($erreur), Str::limit($erreur->getMessage(), 200));
                $code = self::FAILURE;
            }
        }

        $this->line(implode("\n\n", $blocs));

        return $code;
    }
}
