<?php

declare(strict_types=1);

namespace App\Support\Sante;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * La mémoire, les évictions, les refus d'écriture et la persistance de
 * Redis, relus dans `INFO` en production (#2151). Une seule instance porte la
 * file, les sessions, les verrous, les limiteurs et le cache, sous
 * `allkeys-lru` : un cache plein évince d'abord ce que personne n'a relu
 * depuis longtemps, sessions et verrous compris, et rien ne le disait.
 *
 * Rouge quand l'instance des données évince une clé, refuse une écriture
 * faute de mémoire, dépasse 75 % de son plafond, ne persiste plus, ne répond
 * pas, ou, une fois le cache à part, évince encore ; orange au-delà de 50 %,
 * sans plafond, quand le cache à part évince, quand l'AOF diffère ses
 * synchronisations ou quand `INFO` ne rend pas de quoi juger. Les compteurs
 * courent depuis le démarrage de Redis : le contrôle garde ceux du passage
 * précédent dans le cache et ne juge que ce qui a changé depuis, un
 * redémarrage remettant le compte à zéro. Les seuils se calculent sur le
 * `maxmemory` que rend `INFO`, et rien ne montre une quantité de mémoire :
 * un plafond relevé dirait ce que l'hôte donne à Redis. Production
 * seulement ; la lecture se fait dans run(), jamais au démarrage, et rien
 * ne s'écrit en base.
 */
final class ReglagesDeRedisCheck extends Check
{
    /** Au-delà de cette part de son plafond, l'instance des données passe au rouge. */
    private const float PART_ROUGE = 0.75;

    /** Au-delà de celle-ci, à l'orange. */
    private const float PART_ORANGE = 0.5;

    /** Les compteurs du passage précédent, par rôle d'instance. */
    private const string CLE_DU_PASSAGE_PRECEDENT = 'sante:reglages-de-redis:passage-precedent';

    /** @var list<string> */
    private const array COMPTEURS = ['evicted_keys', 'errorstat_OOM', 'aof_delayed_fsync'];

    /**
     * Ce sans quoi l'instance ne se juge pas.
     *
     * @var list<string>
     */
    private const array REQUIS = [
        'used_memory',
        'maxmemory',
        'maxmemory_policy',
        'evicted_keys',
        'aof_last_write_status',
        'aof_last_bgrewrite_status',
        'rdb_last_bgsave_status',
    ];

    /** @var array<string, string> */
    private const array PERSISTANCE = [
        'aof_last_write_status' => 'écriture de l\'AOF',
        'aof_last_bgrewrite_status' => 'réécriture de l\'AOF',
        'rdb_last_bgsave_status' => 'instantané RDB',
    ];

    private const string RELEVER_LE_PLAFOND = 'Relever --maxmemory dans la commande du service redis, et la limite de mémoire de son conteneur avec lui.';

    /**
     * Le libellé de la page « Santé » et du courriel d'alerte, qui
     * découperaient sinon le nom de classe, sans ses accents.
     */
    #[\Override]
    protected ?string $label = 'Réglages de Redis';

    public function __construct(private readonly LecteurDeRedis $lecteur)
    {
        parent::__construct();
    }

    #[\Override]
    public function getName(): string
    {
        return 'ReglagesDeRedis';
    }

    public function run(): Result
    {
        if (! app()->isProduction()) {
            return Result::make()
                ->shortSummary('Non jugé hors production')
                ->ok('Ce contrôle ne juge que la production : Sail et la CI font tourner Redis sans plafond ni persistance réglés.');
        }

        $rouges = [];
        $oranges = [];
        $plafonds = [];
        $instances = $this->lecteur->instances();
        $cacheAPart = isset($instances['cache']);
        $precedent = $this->passagePrecedent();
        $passage = $precedent;
        $lues = [];

        foreach ($instances as $role => $connexion) {
            try {
                $champs = $this->lecteur->lire($connexion);
            } catch (Throwable $erreur) {
                $rouges[] = sprintf(
                    '%s ne répond pas à INFO (%s : %s) : vérifier que le service qui la porte tourne, et que REDIS_PASSWORD est celle de la pile.',
                    $this->nom($role),
                    class_basename($erreur),
                    Str::limit($erreur->getMessage(), 200),
                );

                continue;
            }

            $jugement = $this->juger($role, $champs, $precedent[$role] ?? null, $cacheAPart);
            $lues[$role] = $jugement['meta'];
            $plafonds[$role] = $jugement['plafond'];
            $rouges = [...$rouges, ...$jugement['rouges']];
            $oranges = [...$oranges, ...$jugement['oranges']];
            $passage[$role] = $this->compteurs($champs);
        }

        Cache::forever(self::CLE_DU_PASSAGE_PRECEDENT, $passage);

        $result = Result::make()
            ->meta($lues)
            ->shortSummary($this->resume($lues, $plafonds, $cacheAPart));

        if ($rouges !== []) {
            return $result->failed(implode(' ', [...$rouges, ...$oranges]));
        }

        if ($oranges !== []) {
            return $result->warning(implode(' ', $oranges));
        }

        return $result->ok();
    }

    /**
     * Juge une instance : ses rouges, ses oranges, la part de son plafond
     * qu'elle occupe, et ce que la page en garde, des rapports et des
     * comptes, jamais une quantité de mémoire.
     *
     * @param  array<string, string>  $champs
     * @param  array{uptime: int|null, compteurs: array<string, int|null>}|null  $avant
     * @return array{meta: array<string, mixed>, rouges: list<string>, oranges: list<string>, plafond: string}
     */
    private function juger(string $role, array $champs, ?array $avant, bool $cacheAPart): array
    {
        $rouges = [];
        $oranges = [];
        $donnees = $role === 'donnees';
        $illisibles = array_values(array_filter(self::REQUIS, fn (string $champ): bool => ! isset($champs[$champ])
            || (in_array($champ, ['used_memory', 'maxmemory', 'evicted_keys'], true) && $this->entier($champs[$champ]) === null)));

        if ($illisibles !== []) {
            $oranges[] = sprintf('Non vérifié : INFO n\'a pas rendu %s pour %s.', implode(' ni ', $illisibles), Str::lcfirst($this->nom($role)));
        }

        $part = $this->part($champs);
        $evictions = $this->ecart('evicted_keys', $champs, $avant);
        $refus = $this->ecart('errorstat_OOM', $champs, $avant);
        $differees = $this->ecart('aof_delayed_fsync', $champs, $avant);

        if ($evictions !== null && $evictions['nombre'] > 0) {
            if ($donnees) {
                $rouges[] = sprintf(
                    'L\'instance des données a évincé des clés (%s %s) : file, sessions et verrous y vivent avec le cache, et partent avec lui. %s',
                    $this->compte($evictions['nombre'], 'clé évincée', 'clés évincées'),
                    $evictions['depuis'],
                    self::RELEVER_LE_PLAFOND,
                );
            } else {
                $oranges[] = sprintf(
                    'Le cache a évincé %s %s : relever --maxmemory de son instance si le taux de réussite baisse.',
                    $this->compte($evictions['nombre'], 'clé', 'clés'),
                    $evictions['depuis'],
                );
            }
        }

        if ($refus !== null && $refus['nombre'] > 0) {
            $rouges[] = sprintf(
                '%s : %s faute de mémoire %s (errorstat_OOM) ; sessions et mises en file échouent alors. %s',
                $this->nom($role),
                $this->compte($refus['nombre'], 'écriture refusée', 'écritures refusées'),
                $refus['depuis'],
                self::RELEVER_LE_PLAFOND,
            );
        }

        if ($part === false) {
            $oranges[] = sprintf(
                '%s est sans plafond (maxmemory 0) : elle grandit jusqu\'à la limite de mémoire de son conteneur, qui la coupe. Poser --maxmemory dans la commande du service qui la porte.',
                $this->nom($role),
            );
        } elseif ($donnees && $part !== null && $part > self::PART_ROUGE) {
            $rouges[] = sprintf(
                'L\'instance des données occupe %d %% de son plafond (maxmemory) : au-delà, elle évince ou refuse des écritures. %s',
                $this->pourcent($part),
                self::RELEVER_LE_PLAFOND,
            );
        } elseif ($donnees && $part !== null && $part > self::PART_ORANGE) {
            $oranges[] = sprintf(
                'L\'instance des données occupe %d %% de son plafond (maxmemory) ; le rouge tombe à 75 %%. Prévoir de relever --maxmemory dans la commande du service redis.',
                $this->pourcent($part),
            );
        }

        $enEchec = [];

        foreach (self::PERSISTANCE as $champ => $nom) {
            if (isset($champs[$champ]) && $champs[$champ] !== 'ok') {
                $enEchec[] = sprintf('%s en échec (%s %s)', $nom, $champ, $champs[$champ]);
            }
        }

        if ($enEchec !== []) {
            $rouges[] = sprintf(
                'Persistance de %s : %s. Un redémarrage perdrait ce qui a été écrit depuis le dernier succès. Lire le journal du service redis (place sur le disque, droits du volume).',
                Str::lcfirst($this->nom($role)),
                implode(', ', $enEchec),
            );
        }

        if ($donnees && $cacheAPart && isset($champs['maxmemory_policy']) && $champs['maxmemory_policy'] !== 'noeviction') {
            $rouges[] = sprintf(
                'L\'instance des données évince encore (politique %s) : le cache à part, elle doit refuser une écriture plutôt que perdre une session ou un travail. Poser --maxmemory-policy noeviction dans la commande du service redis.',
                $champs['maxmemory_policy'],
            );
        }

        if ($differees !== null && $differees['nombre'] > 0) {
            $oranges[] = sprintf(
                '%s : %s %s ; le disque n\'a pas suivi, et une coupure brutale perdrait plus d\'une seconde d\'écritures. Si cela dure, chercher ce qui charge le disque de production.',
                $this->nom($role),
                $this->compte($differees['nombre'], 'synchronisation de l\'AOF différée', 'synchronisations de l\'AOF différées'),
                $differees['depuis'],
            );
        }

        $evictionsDepuisLeDemarrage = $this->entier($champs['evicted_keys'] ?? null);
        $jugee = [
            'version' => $champs['redis_version'] ?? null,
            'memoire_en_pourcentage' => is_float($part) ? $this->pourcent($part) : null,
            'politique' => $champs['maxmemory_policy'] ?? null,
            'evictions' => $evictionsDepuisLeDemarrage,
            'refus_faute_de_memoire' => $this->refusDepuisLeDemarrage($champs),
            'persistance' => [
                'aof' => $champs['aof_last_write_status'] ?? null,
                'reecriture_aof' => $champs['aof_last_bgrewrite_status'] ?? null,
                'instantane' => $champs['rdb_last_bgsave_status'] ?? null,
            ],
        ];

        if (! $donnees) {
            $jugee['taux_de_reussite_en_pourcentage'] = $this->tauxDeReussite($champs);
        }

        return [
            'meta' => $jugee,
            'rouges' => $rouges,
            'oranges' => $oranges,
            'plafond' => match (true) {
                $part === false => 'sans plafond',
                $part === null => '? du plafond',
                default => $this->pourcent($part).' % du plafond',
            },
        ];
    }

    /**
     * La part du plafond qu'occupe l'instance : `false` sans plafond, `null`
     * quand `INFO` ne la rend pas.
     *
     * @param  array<string, string>  $champs
     */
    private function part(array $champs): float|false|null
    {
        $occupee = $this->entier($champs['used_memory'] ?? null);
        $plafond = $this->entier($champs['maxmemory'] ?? null);

        if ($occupee === null || $plafond === null) {
            return null;
        }

        return $plafond === 0 ? false : $occupee / $plafond;
    }

    /**
     * Ce qu'un compteur a pris depuis le passage précédent, et depuis quand
     * il se compte : depuis le démarrage de Redis au premier passage, depuis
     * son redémarrage quand il a redémarré entre-temps (son âge a baissé).
     *
     * @param  array<string, string>  $champs
     * @param  array{uptime: int|null, compteurs: array<string, int|null>}|null  $avant
     * @return array{nombre: int, depuis: string}|null
     */
    private function ecart(string $compteur, array $champs, ?array $avant): ?array
    {
        $valeur = $this->valeurDuCompteur($compteur, $champs);

        if ($valeur === null) {
            return null;
        }

        $precedente = $avant['compteurs'][$compteur] ?? null;

        if ($avant === null || $precedente === null) {
            return ['nombre' => $valeur, 'depuis' => 'depuis le démarrage de Redis'];
        }

        $age = $this->entier($champs['uptime_in_seconds'] ?? null);

        if ($age !== null && $avant['uptime'] !== null && $age < $avant['uptime']) {
            return ['nombre' => $valeur, 'depuis' => 'depuis le redémarrage de Redis'];
        }

        if ($valeur < $precedente) {
            return ['nombre' => $valeur, 'depuis' => 'depuis la remise à zéro de ses compteurs'];
        }

        return ['nombre' => $valeur - $precedente, 'depuis' => 'depuis le passage précédent'];
    }

    /**
     * @param  array<string, string>  $champs
     * @return array{uptime: int|null, compteurs: array<string, int|null>}
     */
    private function compteurs(array $champs): array
    {
        $compteurs = [];

        foreach (self::COMPTEURS as $compteur) {
            $compteurs[$compteur] = $this->valeurDuCompteur($compteur, $champs);
        }

        return ['uptime' => $this->entier($champs['uptime_in_seconds'] ?? null), 'compteurs' => $compteurs];
    }

    /**
     * Un refus d'écriture n'apparaît dans `errorstats` qu'une fois arrivé :
     * absent, il vaut zéro.
     *
     * @param  array<string, string>  $champs
     */
    private function valeurDuCompteur(string $compteur, array $champs): ?int
    {
        if ($compteur === 'errorstat_OOM') {
            return $this->refusDepuisLeDemarrage($champs);
        }

        return $this->entier($champs[$compteur] ?? null);
    }

    /**
     * @param  array<string, string>  $champs
     */
    private function refusDepuisLeDemarrage(array $champs): ?int
    {
        if (! isset($champs['errorstat_OOM'])) {
            return 0;
        }

        return preg_match('/(?:^|,)count=(\d+)/', $champs['errorstat_OOM'], $correspondance) === 1 ? (int) $correspondance[1] : null;
    }

    /**
     * La part des lectures du cache qui ont trouvé leur clé, depuis le
     * démarrage de Redis (formule de redis.io).
     *
     * @param  array<string, string>  $champs
     */
    private function tauxDeReussite(array $champs): ?int
    {
        $trouvees = $this->entier($champs['keyspace_hits'] ?? null);
        $manquees = $this->entier($champs['keyspace_misses'] ?? null);

        if ($trouvees === null || $manquees === null || $trouvees + $manquees === 0) {
            return null;
        }

        return $this->pourcent($trouvees / ($trouvees + $manquees));
    }

    /**
     * @param  array<string, array<string, mixed>>  $lues
     * @param  array<string, string>  $plafonds
     */
    private function resume(array $lues, array $plafonds, bool $cacheAPart): string
    {
        $donnees = $lues['donnees'] ?? null;

        if ($donnees === null) {
            return 'Redis ne répond pas';
        }

        $evictions = $donnees['evictions'];
        $morceaux = [
            sprintf('Redis %s', is_string($donnees['version']) ? $donnees['version'] : '?'),
            'données '.($plafonds['donnees'] ?? '? du plafond'),
            is_int($evictions) ? $this->compte($evictions, 'éviction', 'évictions') : '? éviction',
        ];

        if ($cacheAPart) {
            $cache = $lues['cache'] ?? null;

            if ($cache === null) {
                $morceaux[] = 'cache ne répond pas';
            } else {
                $taux = $cache['taux_de_reussite_en_pourcentage'] ?? null;
                $morceaux[] = 'cache '.str_replace(' du plafond', '', $plafonds['cache'] ?? '?');
                $morceaux[] = 'réussite '.(is_int($taux) ? "{$taux} %" : '?');
            }
        }

        return implode(' · ', $morceaux);
    }

    /**
     * @return array<string, array{uptime: int|null, compteurs: array<string, int|null>}>
     */
    private function passagePrecedent(): array
    {
        $lu = Cache::get(self::CLE_DU_PASSAGE_PRECEDENT);
        $passage = [];

        foreach (is_array($lu) ? $lu : [] as $role => $instance) {
            if (! is_string($role) || ! is_array($instance) || ! is_array($instance['compteurs'] ?? null)) {
                continue;
            }

            $compteurs = [];

            foreach (self::COMPTEURS as $compteur) {
                $valeur = $instance['compteurs'][$compteur] ?? null;
                $compteurs[$compteur] = is_int($valeur) ? $valeur : null;
            }

            $passage[$role] = [
                'uptime' => is_int($instance['uptime'] ?? null) ? $instance['uptime'] : null,
                'compteurs' => $compteurs,
            ];
        }

        return $passage;
    }

    private function nom(string $role): string
    {
        return $role === 'donnees' ? 'L\'instance des données' : 'L\'instance du cache';
    }

    private function entier(?string $valeur): ?int
    {
        return $valeur !== null && ctype_digit($valeur) ? (int) $valeur : null;
    }

    private function pourcent(float $part): int
    {
        return (int) round($part * 100);
    }

    private function compte(int $nombre, string $singulier, string $pluriel): string
    {
        return sprintf('%d %s', $nombre, $nombre > 1 ? $pluriel : $singulier);
    }
}
