<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Les bornes de toute lecture du diagnostic (#2032).
 *
 * Un agrégat lit au plus les 5 000 dernières lignes de sa fenêtre, par clé
 * primaire, et le dit (`echantillon`) : une source qui déborde ne fait pas
 * lire toute sa table. Une liste rend au plus 50 groupes, et le dit
 * (`tronque`). Chaque projection en lit une de plus pour savoir si elle a
 * débordé, au détail comme à la liste : le détail d'un groupe ou d'une
 * tâche compte aussi ses lignes, et l'enveloppe des détails remonte le
 * drapeau comme celle du résumé.
 *
 * Chaque lecture porte aussi une borne de temps côté base
 * (`MAX_EXECUTION_TIME`, `requete()`) : quand MySQL l'atteint, la lecture
 * est coupée (`tempsEcoule()`) et la réponse est une 503.
 */
final class LecturesBornees
{
    public const int LIGNES_AU_PLUS = 5_000;

    public const int GROUPES_AU_PLUS = 50;

    public const int MILLISECONDES_AU_PLUS = 2_000;

    /** ER_QUERY_TIMEOUT : « Query execution was interrupted, maximum statement execution time exceeded ». */
    public const int ERREUR_DE_TEMPS_ECOULE = 3_024;

    /**
     * Une lecture qui nomme ses colonnes, sous la borne de temps de la base.
     * L'indication d'optimiseur doit suivre `select` : elle précède la
     * première colonne. Seul MySQL la lit ; ailleurs elle n'est qu'un
     * commentaire.
     *
     * @param  list<string>  $colonnes
     */
    public static function requete(Builder $requete, array $colonnes, int $millisecondes = self::MILLISECONDES_AU_PLUS): Builder
    {
        $premiere = array_shift($colonnes);

        if ($premiere === null) {
            return $requete;
        }

        $connexion = $requete->getConnection();

        if (! $connexion instanceof Connection || $connexion->getDriverName() !== 'mysql') {
            return $requete->select([$premiere, ...$colonnes]);
        }

        return $requete->select([
            $connexion->raw('/*+ MAX_EXECUTION_TIME('.$millisecondes.') */ '.$requete->getGrammar()->wrap($premiere)), // @phpstan-ignore argument.type
            ...$colonnes,
        ]);
    }

    /**
     * La base a-t-elle coupé la lecture parce qu'elle a duré plus que la borne ?
     */
    public static function tempsEcoule(QueryException $erreur): bool
    {
        return ($erreur->errorInfo[1] ?? null) === self::ERREUR_DE_TEMPS_ECOULE;
    }

    /**
     * Les lignes lues, sans celle de plus qui ne sert qu'à savoir si la
     * lecture a débordé.
     *
     * @template TLigne
     *
     * @param  Collection<int, TLigne>  $lignes
     * @return Collection<int, TLigne>
     */
    public static function bornees(Collection $lignes): Collection
    {
        return $lignes->take(self::LIGNES_AU_PLUS);
    }

    /**
     * La source a-t-elle plus de lignes que la borne ? Il y en a une de plus
     * dans la lecture.
     *
     * @template TLigne
     *
     * @param  Collection<int, TLigne>  $lignes  les lignes lues, celle de plus comprise
     */
    public static function deborde(Collection $lignes): bool
    {
        return $lignes->count() > self::LIGNES_AU_PLUS;
    }

    /**
     * Ce qu'une liste dit de sa lecture : les lignes lues, si la source a
     * débordé (`echantillon`), si la liste rend moins de groupes qu'elle n'en
     * a trouvé (`tronque`).
     *
     * @template TLigne
     * @template TGroupe
     *
     * @param  Collection<int, TLigne>  $lignes  les lignes lues, celle de plus comprise
     * @param  Collection<int, TGroupe>  $groupes  tous les groupes trouvés
     * @return array{lignes_lues: int, echantillon: bool, tronque: bool}
     */
    public static function bilan(Collection $lignes, Collection $groupes): array
    {
        return [
            'lignes_lues' => min($lignes->count(), self::LIGNES_AU_PLUS),
            'echantillon' => self::deborde($lignes),
            'tronque' => $groupes->count() > self::GROUPES_AU_PLUS,
        ];
    }
}
