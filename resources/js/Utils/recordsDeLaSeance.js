/**
 * Les records que les séries d'une séance portent déjà, lus sur la copie locale :
 * le serveur marque chaque série validée qui bat un record (`personal_record`,
 * le badge de la coche). Un record par exercice, le plus lourd, les plus lourds
 * d'abord, cinq au plus : le même rang que le bilan du serveur.
 *
 * @param {{ workout_lines?: Array<{ exercise?: { name?: string }, sets?: Array<Object> }> }|null} seance
 * @returns {Array<{ exercice: string, valeur: number }>}
 */
export function recordsDeLaSeance(seance) {
    const parExercice = new Map()

    for (const ligne of seance?.workout_lines ?? []) {
        const nom = ligne.exercise?.name

        for (const serie of ligne.sets ?? []) {
            const valeur = Number(serie.weight)

            if (!nom || serie.is_completed !== true || !serie.personal_record || !(valeur > 0)) {
                continue
            }

            parExercice.set(nom, Math.max(parExercice.get(nom) ?? 0, valeur))
        }
    }

    return [...parExercice]
        .map(([exercice, valeur]) => ({ exercice, valeur }))
        .sort((a, b) => b.valeur - a.valeur)
        .slice(0, 5)
}
