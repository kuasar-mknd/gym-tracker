<?php

declare(strict_types=1);

namespace App\Support\Sante;

use Illuminate\Support\Facades\DB;

/**
 * Lit dans MySQL les deux réglages qui décidaient du coût de chaque écriture
 * sur le disque de production (#1668), en lecture seule. `ReglagesDeLaBaseCheck` le
 * reçoit du conteneur : les tests le remplacent pour juger des valeurs que la
 * base de test n'a pas.
 */
class LecteurDesReglagesDeLaBase
{
    /**
     * @return array<string, string> les valeurs, par nom de variable
     */
    public function lire(): array
    {
        $lignes = DB::select("SHOW GLOBAL VARIABLES WHERE Variable_name IN ('innodb_flush_log_at_trx_commit', 'log_bin')");
        $variables = [];

        foreach ($lignes as $ligne) {
            $colonnes = (array) $ligne;
            $nom = $colonnes['Variable_name'] ?? null;
            $valeur = $colonnes['Value'] ?? null;

            if (is_string($nom) && is_string($valeur)) {
                $variables[$nom] = $valeur;
            }
        }

        return $variables;
    }
}
