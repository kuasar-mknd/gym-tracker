/**
 * La progression qu'on affiche d'un objectif : « 100 % » est réservé à celui
 * qui est atteint, et 99,5 s'arrondirait à 100 sans l'être.
 *
 * @param {{progress_pct?: number|null, completed_at?: string|null}} objectif
 * @returns {number}
 */
export function progressionAffichee(objectif) {
    const progression = objectif.progress_pct || 0

    return !objectif.completed_at && Math.round(progression) >= 100 ? 99 : progression
}
