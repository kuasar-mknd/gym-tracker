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
 * redémarrage (un autre `run_id`) remettant le compte à zéro. Les seuils se calculent sur le
 * `maxmemory` que rend `INFO`, et rien ne montre une quantité de mémoire :
 * un plafond relevé dirait ce que l'hôte donne à Redis. Production
 * seulement ; la lecture se fait dans run(), jamais au démarrage, et rien
 * ne s'écrit en base.
 *
 * Un jugement se range en `[rouges, oranges]`, chaque règle dans sa méthode.
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
     * Ce sans quoi l'instance ne se juge pas ; les trois premiers se lisent
     * en nombres.
     *
     * @var list<string>
     */
    private const array REQUIS = [
        'used_memory',
        'maxmemory',
        'evicted_keys',
        'maxmemory_policy',
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

    /** @var array<string, string> */
    private const array NOMS = ['donnees' => 'L\'instance des données', 'cache' => 'L\'instance du cache'];

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

        $instances = $this->lecteur->instances();
        $cacheAPart = isset($instances['cache']);
        $passage = $this->passagePrecedent();
        $jugements = [];

        foreach ($instances as $role => $connexion) {
            $jugements[$role] = $this->jugerLInstance($role, $connexion, $passage[$role] ?? null, $cacheAPart);
            $passage[$role] = $jugements[$role]['compteurs'] ?? $passage[$role] ?? null;
        }

        Cache::forever(self::CLE_DU_PASSAGE_PRECEDENT, array_filter($passage, fn (?array $instance): bool => $instance !== null));

        return $this->resultat($jugements, $cacheAPart);
    }

    /**
     * Lit une instance et la juge. Une erreur de lecture devient un rouge
     * qui la nomme : laissée filer, le paquet afficherait « Crashed », sans
     * message ni courriel.
     *
     * @param  array{run_id: string|null, uptime: int|null, compteurs: array<string, int|null>}|null  $avant
     * @return array{meta: array<string, mixed>|null, rouges: list<string>, oranges: list<string>, plafond: string, compteurs: array{run_id: string|null, uptime: int|null, compteurs: array<string, int|null>}|null}
     */
    private function jugerLInstance(string $role, string $connexion, ?array $avant, bool $cacheAPart): array
    {
        try {
            $champs = $this->lecteur->lire($connexion);
        } catch (Throwable $erreur) {
            $message = sprintf(
                '%s ne répond pas à INFO (%s : %s) : vérifier que le service qui la porte tourne, et que REDIS_PASSWORD est celle de la pile.',
                self::NOMS[$role],
                class_basename($erreur),
                Str::limit($erreur->getMessage(), 200),
            );

            return ['meta' => null, 'rouges' => [$message], 'oranges' => [], 'plafond' => '?', 'compteurs' => null];
        }

        $part = LecteurDeRedis::part($champs);
        $ecarts = [];

        foreach (self::COMPTEURS as $compteur) {
            $ecarts[$compteur] = $this->ecart($compteur, $champs, $avant);
        }

        $regles = [
            $this->illisibles($role, $champs),
            $this->evictions($role, $ecarts['evicted_keys']),
            $this->refus($role, $ecarts['errorstat_OOM']),
            $this->memoire($role, $part),
            $this->persistance($role, $champs),
            $this->politique($role, $champs, $cacheAPart),
            $this->differees($role, $ecarts['aof_delayed_fsync']),
        ];

        return [
            'meta' => $this->meta($role, $champs, $part),
            'rouges' => array_merge(...array_column($regles, 0)),
            'oranges' => array_merge(...array_column($regles, 1)),
            'plafond' => $this->plafondLu($part),
            'compteurs' => $this->compteurs($champs),
        ];
    }

    /**
     * @param  array<string, string>  $champs
     * @return array{0: list<string>, 1: list<string>}
     */
    private function illisibles(string $role, array $champs): array
    {
        $manquants = array_values(array_filter(
            self::REQUIS,
            fn (string $champ, int $rang): bool => $rang < 3 ? LecteurDeRedis::entier($champs[$champ] ?? null) === null : ! isset($champs[$champ]),
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($manquants === []) {
            return [[], []];
        }

        return [[], [sprintf('Non vérifié : INFO n\'a pas rendu %s pour %s.', implode(' ni ', $manquants), Str::lcfirst(self::NOMS[$role]))]];
    }

    /**
     * Sur l'instance des données, une éviction emporte file, sessions ou
     * verrous : rouge. Sur le cache à part, c'est son métier : orange.
     *
     * @param  array{nombre: int, depuis: string}|null  $ecart
     * @return array{0: list<string>, 1: list<string>}
     */
    private function evictions(string $role, ?array $ecart): array
    {
        if ($ecart === null || $ecart['nombre'] === 0) {
            return [[], []];
        }

        if ($role === 'cache') {
            $message = sprintf(
                'Le cache a évincé %s %s : relever --maxmemory de son instance si le taux de réussite baisse.',
                $this->compte($ecart['nombre'], 'clé', 'clés'),
                $ecart['depuis'],
            );

            return [[], [$message]];
        }

        $message = sprintf(
            'L\'instance des données a évincé des clés (%s %s) : file, sessions et verrous y vivent avec le cache, et partent avec lui. %s',
            $this->compte($ecart['nombre'], 'clé évincée', 'clés évincées'),
            $ecart['depuis'],
            self::RELEVER_LE_PLAFOND,
        );

        return [[$message], []];
    }

    /**
     * @param  array{nombre: int, depuis: string}|null  $ecart
     * @return array{0: list<string>, 1: list<string>}
     */
    private function refus(string $role, ?array $ecart): array
    {
        if ($ecart === null || $ecart['nombre'] === 0) {
            return [[], []];
        }

        $message = sprintf(
            '%s : %s faute de mémoire %s (errorstat_OOM) ; sessions et mises en file échouent alors. %s',
            self::NOMS[$role],
            $this->compte($ecart['nombre'], 'écriture refusée', 'écritures refusées'),
            $ecart['depuis'],
            self::RELEVER_LE_PLAFOND,
        );

        return [[$message], []];
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function memoire(string $role, float|false|null $part): array
    {
        if ($part === false) {
            $message = sprintf(
                '%s est sans plafond (maxmemory 0) : elle grandit jusqu\'à la limite de mémoire de son conteneur, qui la coupe. Poser --maxmemory dans la commande du service qui la porte.',
                self::NOMS[$role],
            );

            return [[], [$message]];
        }

        return $role === 'donnees' && $part !== null ? $this->partDesDonnees($part) : [[], []];
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function partDesDonnees(float $part): array
    {
        if ($part > self::PART_ROUGE) {
            $message = sprintf(
                'L\'instance des données occupe %d %% de son plafond (maxmemory) : au-delà, elle évince ou refuse des écritures. %s',
                $this->pourcent($part),
                self::RELEVER_LE_PLAFOND,
            );

            return [[$message], []];
        }

        if ($part > self::PART_ORANGE) {
            $message = sprintf(
                'L\'instance des données occupe %d %% de son plafond (maxmemory) ; le rouge tombe à 75 %%. Prévoir de relever --maxmemory dans la commande du service redis.',
                $this->pourcent($part),
            );

            return [[], [$message]];
        }

        return [[], []];
    }

    /**
     * @param  array<string, string>  $champs
     * @return array{0: list<string>, 1: list<string>}
     */
    private function persistance(string $role, array $champs): array
    {
        $enEchec = [];

        foreach (self::PERSISTANCE as $champ => $nom) {
            if (($champs[$champ] ?? 'ok') !== 'ok') {
                $enEchec[] = sprintf('%s en échec (%s %s)', $nom, $champ, $champs[$champ]);
            }
        }

        if ($enEchec === []) {
            return [[], []];
        }

        $message = sprintf(
            'Persistance de %s : %s. Un redémarrage perdrait ce qui a été écrit depuis le dernier succès. Lire le journal du service redis (place sur le disque, droits du volume).',
            Str::lcfirst(self::NOMS[$role]),
            implode(', ', $enEchec),
        );

        return [[$message], []];
    }

    /**
     * Une fois le cache à part, l'instance des données refuse une écriture
     * plutôt que de perdre une session ou un travail.
     *
     * @param  array<string, string>  $champs
     * @return array{0: list<string>, 1: list<string>}
     */
    private function politique(string $role, array $champs, bool $cacheAPart): array
    {
        $politique = $champs['maxmemory_policy'] ?? 'noeviction';

        if ($role !== 'donnees' || ! $cacheAPart || $politique === 'noeviction') {
            return [[], []];
        }

        $message = sprintf(
            'L\'instance des données évince encore (politique %s) : le cache à part, elle doit refuser une écriture plutôt que perdre une session ou un travail. Poser --maxmemory-policy noeviction dans la commande du service redis.',
            $politique,
        );

        return [[$message], []];
    }

    /**
     * @param  array{nombre: int, depuis: string}|null  $ecart
     * @return array{0: list<string>, 1: list<string>}
     */
    private function differees(string $role, ?array $ecart): array
    {
        if ($ecart === null || $ecart['nombre'] === 0) {
            return [[], []];
        }

        $message = sprintf(
            '%s : %s %s ; le disque n\'a pas suivi, et une coupure brutale perdrait plus d\'une seconde d\'écritures. Si cela dure, chercher ce qui charge le disque de production.',
            self::NOMS[$role],
            $this->compte($ecart['nombre'], 'synchronisation de l\'AOF différée', 'synchronisations de l\'AOF différées'),
            $ecart['depuis'],
        );

        return [[], [$message]];
    }

    /**
     * Ce que la page garde d'une instance : des rapports et des comptes,
     * jamais une quantité de mémoire.
     *
     * @param  array<string, string>  $champs
     * @return array<string, mixed>
     */
    private function meta(string $role, array $champs, float|false|null $part): array
    {
        $meta = [
            'version' => $champs['redis_version'] ?? null,
            'memoire_en_pourcentage' => is_float($part) ? $this->pourcent($part) : null,
            'politique' => $champs['maxmemory_policy'] ?? null,
            'evictions' => LecteurDeRedis::entier($champs['evicted_keys'] ?? null),
            'refus_faute_de_memoire' => $this->refusDepuisLeDemarrage($champs),
            'persistance' => [
                'aof' => $champs['aof_last_write_status'] ?? null,
                'reecriture_aof' => $champs['aof_last_bgrewrite_status'] ?? null,
                'instantane' => $champs['rdb_last_bgsave_status'] ?? null,
            ],
        ];

        if ($role === 'cache') {
            $meta['taux_de_reussite_en_pourcentage'] = $this->tauxDeReussite($champs);
        }

        return $meta;
    }

    private function plafondLu(float|false|null $part): string
    {
        return match (true) {
            $part === false => 'sans plafond',
            $part === null => '? du plafond',
            default => $this->pourcent($part).' % du plafond',
        };
    }

    /**
     * Ce qu'un compteur a pris depuis le passage précédent, et depuis quand
     * il se compte : depuis le démarrage de Redis au premier passage, depuis
     * son redémarrage quand il a redémarré entre-temps.
     *
     * @param  array<string, string>  $champs
     * @param  array{run_id: string|null, uptime: int|null, compteurs: array<string, int|null>}|null  $avant
     * @return array{nombre: int, depuis: string}|null
     */
    private function ecart(string $compteur, array $champs, ?array $avant): ?array
    {
        $valeur = $this->valeurDuCompteur($compteur, $champs);
        $precedente = $avant['compteurs'][$compteur] ?? null;

        if ($valeur === null) {
            return null;
        }

        if ($precedente === null) {
            return ['nombre' => $valeur, 'depuis' => 'depuis le démarrage de Redis'];
        }

        if ($this->aRedemarre($champs, $avant)) {
            return ['nombre' => $valeur, 'depuis' => 'depuis le redémarrage de Redis'];
        }

        return $valeur < $precedente
            ? ['nombre' => $valeur, 'depuis' => 'depuis la remise à zéro de ses compteurs']
            : ['nombre' => $valeur - $precedente, 'depuis' => 'depuis le passage précédent'];
    }

    /**
     * @param  array<string, string>  $champs
     * @param  array{run_id: string|null, uptime: int|null, compteurs: array<string, int|null>}|null  $avant
     */
    private function aRedemarre(array $champs, ?array $avant): bool
    {
        $processus = $champs['run_id'] ?? null;
        $processusPrecedent = $avant['run_id'] ?? null;

        if ($processus !== null && $processusPrecedent !== null) {
            return $processus !== $processusPrecedent;
        }

        $age = LecteurDeRedis::entier($champs['uptime_in_seconds'] ?? null);
        $agePrecedent = $avant['uptime'] ?? null;

        return $age !== null && $agePrecedent !== null && $age < $agePrecedent;
    }

    /**
     * @param  array<string, string>  $champs
     * @return array{run_id: string|null, uptime: int|null, compteurs: array<string, int|null>}
     */
    private function compteurs(array $champs): array
    {
        $compteurs = [];

        foreach (self::COMPTEURS as $compteur) {
            $compteurs[$compteur] = $this->valeurDuCompteur($compteur, $champs);
        }

        return ['run_id' => $champs['run_id'] ?? null, 'uptime' => LecteurDeRedis::entier($champs['uptime_in_seconds'] ?? null), 'compteurs' => $compteurs];
    }

    /**
     * @param  array<string, string>  $champs
     */
    private function valeurDuCompteur(string $compteur, array $champs): ?int
    {
        if ($compteur === 'errorstat_OOM') {
            return $this->refusDepuisLeDemarrage($champs);
        }

        return LecteurDeRedis::entier($champs[$compteur] ?? null);
    }

    /**
     * Un refus d'écriture n'apparaît dans `errorstats` qu'une fois arrivé :
     * absent, il vaut zéro.
     *
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
        $trouvees = (int) LecteurDeRedis::entier($champs['keyspace_hits'] ?? null);
        $lectures = $trouvees + (int) LecteurDeRedis::entier($champs['keyspace_misses'] ?? null);

        return $lectures === 0 ? null : $this->pourcent($trouvees / $lectures);
    }

    /**
     * @param  array<string, array{meta: array<string, mixed>|null, rouges: list<string>, oranges: list<string>, plafond: string, compteurs: mixed}>  $jugements
     */
    private function resultat(array $jugements, bool $cacheAPart): Result
    {
        $rouges = array_merge(...array_values(array_column($jugements, 'rouges')));
        $oranges = array_merge(...array_values(array_column($jugements, 'oranges')));
        $result = Result::make()
            ->meta(array_filter(array_map(fn (array $jugement): ?array => $jugement['meta'], $jugements), fn (?array $meta): bool => $meta !== null))
            ->shortSummary($this->resume($jugements, $cacheAPart));

        if ($rouges !== []) {
            return $result->failed(implode(' ', [...$rouges, ...$oranges]));
        }

        return $oranges === [] ? $result->ok() : $result->warning(implode(' ', $oranges));
    }

    /**
     * La ligne de la page « Santé », qui n'affiche jamais les `meta` : la
     * version, la mémoire en pourcentage du plafond, la politique
     * d'éviction et les évictions de chaque instance.
     *
     * @param  array<string, array{meta: array<string, mixed>|null, rouges: list<string>, oranges: list<string>, plafond: string, compteurs: mixed}>  $jugements
     */
    private function resume(array $jugements, bool $cacheAPart): string
    {
        $donnees = $jugements['donnees']['meta'] ?? null;

        if ($donnees === null) {
            return 'Redis ne répond pas';
        }

        $evictions = $donnees['evictions'];
        $morceaux = [
            'Redis '.(is_string($donnees['version']) ? $donnees['version'] : '?'),
            'données '.$jugements['donnees']['plafond'],
            $this->politiqueLue($donnees),
            is_int($evictions) ? $this->compte($evictions, 'éviction', 'évictions') : '? éviction',
        ];

        return implode(' · ', $cacheAPart ? [...$morceaux, ...$this->resumeDuCache($jugements['cache'] ?? null)] : $morceaux);
    }

    /**
     * @param  array{meta: array<string, mixed>|null, rouges: list<string>, oranges: list<string>, plafond: string, compteurs: mixed}|null  $jugement
     * @return list<string>
     */
    private function resumeDuCache(?array $jugement): array
    {
        $meta = $jugement['meta'] ?? null;

        if ($jugement === null || $meta === null) {
            return ['cache ne répond pas'];
        }

        $taux = $meta['taux_de_reussite_en_pourcentage'] ?? null;

        return ['cache '.str_replace(' du plafond', '', $jugement['plafond']), $this->politiqueLue($meta), 'réussite '.(is_int($taux) ? "{$taux} %" : '?')];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function politiqueLue(array $meta): string
    {
        return is_string($meta['politique'] ?? null) ? $meta['politique'] : 'politique ?';
    }

    /**
     * Les compteurs du passage précédent, relus du cache sans rien croire
     * de leur forme.
     *
     * @return array<string, array{run_id: string|null, uptime: int|null, compteurs: array<string, int|null>}>
     */
    private function passagePrecedent(): array
    {
        $lu = Cache::get(self::CLE_DU_PASSAGE_PRECEDENT);
        $passage = [];

        foreach (is_array($lu) ? $lu : [] as $role => $instance) {
            $relue = $this->instanceRelue($instance);

            if (is_string($role) && $relue !== null) {
                $passage[$role] = $relue;
            }
        }

        return $passage;
    }

    /**
     * @return array{run_id: string|null, uptime: int|null, compteurs: array<string, int|null>}|null
     */
    private function instanceRelue(mixed $instance): ?array
    {
        if (! is_array($instance)) {
            return null;
        }

        $brut = is_array($instance['compteurs'] ?? null) ? $instance['compteurs'] : [];
        $compteurs = [];

        foreach (self::COMPTEURS as $compteur) {
            $compteurs[$compteur] = self::entierOuNul($brut[$compteur] ?? null);
        }

        $processus = $instance['run_id'] ?? null;

        return ['run_id' => is_string($processus) ? $processus : null, 'uptime' => self::entierOuNul($instance['uptime'] ?? null), 'compteurs' => $compteurs];
    }

    private static function entierOuNul(mixed $valeur): ?int
    {
        return is_int($valeur) ? $valeur : null;
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
