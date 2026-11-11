<?php

declare(strict_types=1);

namespace App\Support\Sante;

use Illuminate\Support\Facades\DB;

/**
 * Lit dans MySQL, en lecture seule, les réglages et les compteurs qui disent ce
 * que coûte chaque écriture sur le stockage de production (#1668).
 * `ReglagesDeLaBaseCheck` et `base:mesurer` le reçoivent du conteneur : les
 * tests le remplacent pour juger des valeurs que la base de test n'a pas.
 *
 * Un nom que la base ne connaît pas manque simplement au tableau rendu :
 * à l'appelant de le dire « non rendu », jamais de le croire nul.
 */
class LecteurDesReglagesDeLaBase
{
    /**
     * Les deux réglages que juge la page « Santé ».
     *
     * @return array<string, string> les valeurs, par nom de variable
     */
    public function lire(): array
    {
        return $this->lireLesVariables(['innodb_flush_log_at_trx_commit', 'log_bin']);
    }

    /**
     * @param  list<string>  $noms
     * @return array<string, string> les valeurs rendues par `SHOW GLOBAL VARIABLES`, par nom
     */
    public function lireLesVariables(array $noms): array
    {
        return $this->lireLesValeurs('SHOW GLOBAL VARIABLES', $noms);
    }

    /**
     * @param  list<string>  $noms
     * @return array<string, string> les valeurs rendues par `SHOW GLOBAL STATUS`, par nom
     */
    public function lireLesCompteurs(array $noms): array
    {
        return $this->lireLesValeurs('SHOW GLOBAL STATUS', $noms);
    }

    /**
     * Les noms partent en paramètres liés, jamais dans le texte de la requête.
     *
     * @param  'SHOW GLOBAL VARIABLES'|'SHOW GLOBAL STATUS'  $instruction
     * @param  list<string>  $noms
     * @return array<string, string>
     */
    private function lireLesValeurs(string $instruction, array $noms): array
    {
        if ($noms === []) {
            return [];
        }

        $marques = implode(', ', array_fill(0, count($noms), '?'));
        $lignes = DB::select(sprintf('%s WHERE Variable_name IN (%s)', $instruction, $marques), $noms);
        $valeurs = [];

        foreach ($lignes as $ligne) {
            $colonnes = (array) $ligne;
            $nom = $colonnes['Variable_name'] ?? null;
            $valeur = $colonnes['Value'] ?? null;

            if (is_string($nom) && is_string($valeur)) {
                $valeurs[$nom] = $valeur;
            }
        }

        return $valeurs;
    }
}
