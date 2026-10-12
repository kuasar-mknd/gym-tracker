<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

use Illuminate\Support\Collection;

/**
 * Les bornes de toute lecture du diagnostic (#2032).
 *
 * Un agrégat lit au plus les 5 000 dernières lignes de sa fenêtre, par clé
 * primaire, et le dit (`echantillon`) : une source qui déborde ne fait pas
 * lire toute sa table. Une liste rend au plus 50 groupes, et le dit
 * (`tronque`). Chaque projection en lit une de plus pour savoir si elle a
 * débordé.
 */
final class LecturesBornees
{
    public const int LIGNES_AU_PLUS = 5_000;

    public const int GROUPES_AU_PLUS = 50;

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
            'echantillon' => $lignes->count() > self::LIGNES_AU_PLUS,
            'tronque' => $groupes->count() > self::GROUPES_AU_PLUS,
        ];
    }
}
