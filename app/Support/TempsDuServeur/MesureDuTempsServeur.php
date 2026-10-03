<?php

declare(strict_types=1);

namespace App\Support\TempsDuServeur;

/**
 * Ce qu'une réponse a coûté au serveur, le temps d'une requête (#1315).
 *
 * `TempsDuServeur` la démarre en tête de la pile globale ; les écouteurs que
 * pose `TempsDuServeurServiceProvider` y notent la route trouvée, la réponse
 * du contrôleur, chaque requête SQL et toute étape d'une authentification. Le
 * middleware en tire l'en-tête `Server-Timing` au retour.
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
 * pendant la requête fausserait la mesure.
 */
final class MesureDuTempsServeur
{
    private ?int $debut = null;

    private ?int $routeTrouvee = null;

    private ?int $reponseDuControleur = null;

    private int $nombreDeRequetesSql = 0;

    private float $dureeSqlEnMs = 0.0;

    private bool $authentificationTentee = false;

    public function demarrer(): void
    {
        $this->debut = self::maintenant();
        $this->routeTrouvee = null;
        $this->reponseDuControleur = null;
        $this->nombreDeRequetesSql = 0;
        $this->dureeSqlEnMs = 0.0;
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
     * La pile globale est traversée et la route choisie : sa pile commence.
     */
    public function noterLaRouteTrouvee(): void
    {
        $this->routeTrouvee ??= self::maintenant();
    }

    /**
     * Le contrôleur a rendu sa réponse, ou la pile de la route a répondu à sa
     * place. Seule la première compte : le routeur prépare la même réponse une
     * seconde fois en sortant de cette pile.
     */
    public function noterLaReponseDuControleur(): void
    {
        $this->reponseDuControleur ??= self::maintenant();
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
     * `sql` cumule les requêtes et dit leur nombre. En ASCII : une valeur
     * d'en-tête n'a pas à porter d'accent.
     */
    public function valeurDeLEnTete(): string
    {
        $fin = self::maintenant();
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

    private static function maintenant(): int
    {
        return (int) hrtime(true);
    }
}
