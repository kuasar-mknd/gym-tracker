<?php

declare(strict_types=1);

namespace App\Support\TempsDuServeur;

use Closure;

/**
 * Ce qu'une réponse a coûté au serveur, le temps d'une requête (#1315).
 *
 * `TempsDuServeur` la démarre en tête de la pile globale ; les écouteurs que
 * pose `TempsDuServeurServiceProvider` y notent la route trouvée, la réponse
 * du contrôleur, chaque requête SQL, chaque lecture du cache (#2148, étape 1)
 * et toute étape d'une authentification. Le middleware en tire l'en-tête
 * `Server-Timing` au retour.
 *
 * Liée en `scoped` : Octane l'oublie à la fin de chaque requête, une file à la
 * fin de chaque tâche, et rien ici n'est statique. `demarrer()` remet de plus
 * tout à zéro, pour un processus long qui servirait plusieurs requêtes sans
 * rien oublier — la suite de tests en est un. Les écouteurs ne la créent
 * jamais : seul le middleware l'ouvre, et une requête SQL faite ailleurs — un
 * worker de file ou le planificateur, qui reçoivent la même variable — ne
 * touche à rien. Avant d'être démarrée, elle ne compte d'ailleurs aucune
 * requête SQL.
 *
 * Les instants viennent de `hrtime()`, monotone : une horloge murale corrigée
 * pendant la requête fausserait la mesure. Aucun instant ne vient d'ailleurs :
 * `REQUEST_TIME_FLOAT`, qu'un serveur pose sur sa propre horloge murale et à
 * un moment qui dépend du serveur, ne s'y mêle jamais.
 */
final class MesureDuTempsServeur
{
    private ?int $debut = null;

    private ?int $routeTrouvee = null;

    private ?int $reponseDuControleur = null;

    private int $nombreDeRequetesSql = 0;

    private float $dureeSqlEnMs = 0.0;

    private int $nombreDeLecturesDuCache = 0;

    private int $dureeDuCacheEnNs = 0;

    /**
     * Les lectures du cache demandées et pas encore toutes répondues, la plus
     * récente en dernier : depuis quand chacune attend, et combien de clés
     * elle attend encore.
     *
     * @var list<array{depuis: int, cles: int}>
     */
    private array $lecturesEnCours = [];

    private bool $authentificationTentee = false;

    /**
     * @param  (Closure(): int)|null  $horloge  Les instants, en nanosecondes ; `hrtime()` à défaut.
     *                                          Un test la remplace pour avancer le temps à la main.
     */
    public function __construct(private readonly ?Closure $horloge = null)
    {
    }

    public function demarrer(): void
    {
        $this->debut = $this->maintenant();
        $this->routeTrouvee = null;
        $this->reponseDuControleur = null;
        $this->nombreDeRequetesSql = 0;
        $this->dureeSqlEnMs = 0.0;
        $this->nombreDeLecturesDuCache = 0;
        $this->dureeDuCacheEnNs = 0;
        $this->lecturesEnCours = [];
        $this->authentificationTentee = false;
    }

    public function compterUneRequeteSql(float $dureeEnMs): void
    {
        if ($this->debut === null) {
            return;
        }

        $this->nombreDeRequetesSql++;
        $this->dureeSqlEnMs += $dureeEnMs;
    }

    /**
     * Le cache va chercher une clé (`RetrievingKey`), ou plusieurs d'un coup
     * (`RetrievingManyKeys`) : la lecture attend désormais une réponse par clé.
     */
    public function noterUneDemandeAuCache(int $cles): void
    {
        if ($this->debut === null || $cles < 1) {
            return;
        }

        $this->lecturesEnCours[] = ['depuis' => $this->maintenant(), 'cles' => $cles];
    }

    /**
     * Le cache a répondu pour une clé, trouvée (`CacheHit`) ou non
     * (`CacheMissed`) : une lecture de plus, et le temps écoulé depuis la
     * demande, ou depuis la clé précédente du même groupe.
     *
     * La réponse revient à la lecture ouverte la plus récente, et chaque
     * lecture restée ouverte dessous reprend à cet instant. Une lecture faite
     * pendant une autre — la valeur par défaut d'une clé absente, que `many()`
     * calcule entre deux réponses — se compte donc une fois, et le calcul
     * autour d'elle, qui n'est pas du cache, pas du tout. Une demande restée
     * sans réponse (le magasin a levé) reste dessous, sans rien compter ni
     * rien empêcher.
     */
    public function noterUneReponseDuCache(): void
    {
        $lecture = array_pop($this->lecturesEnCours);

        if ($lecture === null) {
            return;
        }

        $maintenant = $this->maintenant();

        $this->nombreDeLecturesDuCache++;
        $this->dureeDuCacheEnNs += $maintenant - $lecture['depuis'];

        if ($lecture['cles'] > 1) {
            $this->lecturesEnCours[] = ['depuis' => $maintenant, 'cles' => $lecture['cles'] - 1];
        }

        $this->lecturesEnCours = array_map(
            static fn (array $ouverte): array => ['depuis' => $maintenant, 'cles' => $ouverte['cles']],
            $this->lecturesEnCours,
        );
    }

    /**
     * La pile globale est traversée et la route choisie : sa pile commence.
     */
    public function noterLaRouteTrouvee(): void
    {
        $this->routeTrouvee ??= $this->maintenant();
    }

    /**
     * Le contrôleur a rendu sa réponse, ou la pile de la route a répondu à sa
     * place. Seule la première compte : le routeur prépare la même réponse une
     * seconde fois en sortant de cette pile.
     */
    public function noterLaReponseDuControleur(): void
    {
        $this->reponseDuControleur ??= $this->maintenant();
    }

    public function noterUneAuthentification(): void
    {
        $this->authentificationTentee = true;
    }

    public function aVuUneAuthentification(): bool
    {
        return $this->authentificationTentee;
    }

    /**
     * La valeur de l'en-tête, arrêtée à l'instant de l'appel.
     *
     * `app` va du démarrage à cet appel ; `routage`, `controleur` et `rendu`
     * le découpent aux deux instants notés, quand la requête les a atteints ;
     * `sql` cumule les requêtes et dit leur nombre, `cache` de même pour les
     * lectures du cache, une par clé. En ASCII : une valeur d'en-tête n'a pas
     * à porter d'accent.
     */
    public function valeurDeLEnTete(): string
    {
        $fin = $this->maintenant();
        $debut = $this->debut ?? $fin;
        $etapes = [
            'app' => [$debut, $fin],
            'routage' => [$debut, $this->routeTrouvee],
            'controleur' => [$this->routeTrouvee, $this->reponseDuControleur],
            'rendu' => [$this->reponseDuControleur, $fin],
        ];
        $metriques = [];

        foreach ($etapes as $nom => [$depuis, $jusqua]) {
            if ($depuis !== null && $jusqua !== null) {
                $metriques[] = sprintf('%s;dur=%.1F', $nom, ($jusqua - $depuis) / 1_000_000);
            }
        }

        $metriques[] = $this->metriqueSql();
        $metriques[] = $this->metriqueDuCache();

        return implode(', ', $metriques);
    }

    private function metriqueSql(): string
    {
        return sprintf(
            'sql;dur=%.1F;desc="%d %s"',
            $this->dureeSqlEnMs,
            $this->nombreDeRequetesSql,
            $this->nombreDeRequetesSql === 1 ? 'requete' : 'requetes',
        );
    }

    private function metriqueDuCache(): string
    {
        return sprintf(
            'cache;dur=%.1F;desc="%d %s"',
            $this->dureeDuCacheEnNs / 1_000_000,
            $this->nombreDeLecturesDuCache,
            $this->nombreDeLecturesDuCache === 1 ? 'lecture' : 'lectures',
        );
    }

    private function maintenant(): int
    {
        return $this->horloge instanceof Closure ? ($this->horloge)() : (int) hrtime(true);
    }
}
