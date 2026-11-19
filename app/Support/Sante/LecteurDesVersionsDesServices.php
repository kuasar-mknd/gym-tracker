<?php

declare(strict_types=1);

namespace App\Support\Sante;

use Illuminate\Support\Facades\DB;

/**
 * Lit la version que rendent MySQL et Redis, en lecture seule (#2152).
 * `VersionsDesServicesCheck` le reçoit du conteneur : les tests le
 * remplacent pour juger des versions que la base de test et la suite, sans
 * Redis, n'ont pas.
 */
class LecteurDesVersionsDesServices
{
    /**
     * La version complète de MySQL : `version_with_update` quand le serveur
     * la donne, avec le rang de mise à jour que `VERSION()` tait (Bug
     * #40013285 ; 8.4.13 et 9.7.4 l'ont, 9.7.2 non, sondes du 2026-11-11),
     * sinon `version`. Une seule requête, qui ne lève pas sur un serveur qui
     * n'a pas la première.
     */
    public function mysql(): ?string
    {
        $lignes = DB::select("SHOW GLOBAL VARIABLES WHERE Variable_name IN ('version', 'version_with_update')");
        $variables = [];

        foreach ($lignes as $ligne) {
            $colonnes = (array) $ligne;
            $nom = $colonnes['Variable_name'] ?? null;
            $valeur = $colonnes['Value'] ?? null;

            if (is_string($nom) && is_string($valeur) && $valeur !== '') {
                $variables[$nom] = $valeur;
            }
        }

        return $variables['version_with_update'] ?? $variables['version'] ?? null;
    }

    /**
     * `redis_version` de chaque instance que lit `LecteurDeRedis` (données,
     * et cache et sessions quand elles sont à part), par sa liste blanche,
     * résolu à chaque lecture comme les contrôles le résolvent. Trois
     * instances de même version rendent cette version ; sinon les versions
     * lues, séparées par une barre, que la version attendue ne peut pas
     * égaler.
     */
    public function redis(): ?string
    {
        $lecteur = app(LecteurDeRedis::class);
        $versions = array_map(
            fn (string $connexion): ?string => $lecteur->lire($connexion)['redis_version'] ?? null,
            $lecteur->instances(),
        );

        $distinctes = array_values(array_unique($versions));

        return count($distinctes) === 1 ? $distinctes[0] : implode(' / ', array_map(fn (?string $version): string => $version ?? '?', $versions));
    }
}
