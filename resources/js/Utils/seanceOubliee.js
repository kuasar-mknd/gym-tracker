import { heureCourte, jourEtMois } from '@/Utils/date'

/**
 * Au-delà, une séance restée ouverte est oubliée (#2319) : une durée de
 * plusieurs jours ne dit rien. Le bandeau écrit sa date de début
 * (`ActiveWorkoutBanner`), et « Terminer » propose une heure de fin
 * (`WorkoutFinishModal`).
 */
export const SEUIL_D_UNE_SEANCE_OUBLIEE_S = 12 * 3600

/**
 * Une séance ouverte depuis plus de douze heures. Une horloge décalée ou un
 * fuseau changé ne donnent jamais une durée négative.
 *
 * @param {string|Date|null|undefined} debut `started_at`
 * @param {Date} [maintenant]
 * @returns {boolean}
 */
export const estUneSeanceOubliee = (debut, maintenant = new Date()) => {
    const depart = new Date(debut)

    if (!debut || isNaN(depart.getTime())) {
        return false
    }

    return Math.floor((maintenant - depart) / 1000) > SEUIL_D_UNE_SEANCE_OUBLIEE_S
}

/**
 * L'heure à laquelle « Terminer » propose de clore une séance oubliée : celle
 * de la dernière série saisie (la plus récente de ses `updated_at`, ou de ses
 * `created_at` à défaut), sinon le début de la séance. Une série dont l'heure
 * ne se lit pas, d'avant le début ou à venir, n'est pas retenue : la fin ne
 * précède jamais le début et ne passe jamais maintenant.
 *
 * @param {{started_at: string, workout_lines?: Array<{sets?: Array<{updated_at?: string|null, created_at?: string|null}>}>}} seance
 * @param {Date} [maintenant]
 * @returns {string} un instant ISO (`toISOString()`)
 */
export const finProposeeDUneSeance = (seance, maintenant = new Date()) => {
    const debut = new Date(seance.started_at)
    let derniere = debut

    for (const ligne of seance.workout_lines ?? []) {
        for (const serie of ligne.sets ?? []) {
            const instant = new Date(serie.updated_at ?? serie.created_at)

            if (!isNaN(instant.getTime()) && instant > derniere && instant <= maintenant) {
                derniere = instant
            }
        }
    }

    return derniere.toISOString()
}

/**
 * Un instant en toutes lettres courtes : « 26/08 à 18:42 ».
 *
 * @param {string|Date} instant
 * @returns {string}
 */
export const jourEtHeure = (instant) => {
    const date = new Date(instant)

    return `${jourEtMois(date)} à ${heureCourte(date)}`
}
