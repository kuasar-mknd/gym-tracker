/**
 * Les séries qu'une statistique de l'historique d'un exercice a le droit de lire.
 *
 * Chaque série naît décochée, préremplie par le modèle ou par la valeur
 * proposée : une série jamais cochée est une intention, pas un effort. Le
 * volume de la séance (`Workout::recalculerLeVolume()`) et les records
 * (`PersonalRecordService`) ne comptent déjà que les séries validées ; les
 * graphiques de la fiche exercice comptaient tout, et une série prévue à
 * 140 kg puis jamais faite y faisait monter la « Charge Max » au-dessus du
 * record affiché (#1956).
 *
 * Le serveur envoie `is_completed` et `is_warmup` sur chaque série : une série
 * sans drapeau ne compte pas.
 */

/**
 * Les séries validées, échauffements compris, comme le volume de la séance.
 *
 * @param {Array<{is_completed?: boolean}>} series
 * @returns {Array<Object>}
 */
export function seriesValidees(series) {
    return (series ?? []).filter((serie) => serie.is_completed === true)
}

/**
 * Les séries validées hors échauffement : ce que toute la fiche exercice lit
 * (#2333), « Charge max » exceptée, qui lit en plus `peutEtablirUnRecord()`
 * (poids et répétitions positifs). Un échauffement validé compte dans le volume
 * de la séance, mais ni dans le record ni dans la fiche : il gonflait la charge
 * moyenne et n'avait rien d'un effort de travail. Une série au poids du corps
 * (sans charge) reste comptée, ses répétitions sont un effort.
 *
 * @param {Array<{is_completed?: boolean, is_warmup?: boolean}>} series
 * @returns {Array<Object>}
 */
export function seriesDeTravail(series) {
    return seriesValidees(series).filter((serie) => serie.is_warmup !== true)
}

/**
 * La série peut établir un record : les conditions de `PersonalRecordService`
 * — validée, hors échauffement, avec un poids et des répétitions.
 *
 * @param {{is_completed?: boolean, is_warmup?: boolean, weight?: number|string|null, reps?: number|string|null}} serie
 * @returns {boolean}
 */
export function peutEtablirUnRecord(serie) {
    return serie.is_completed === true && serie.is_warmup !== true && Number(serie.weight) > 0 && Number(serie.reps) > 0
}

/**
 * Le volume des séries validées, comme `Workout::recalculerLeVolume()` : un
 * poids ou des répétitions manquants comptent pour zéro.
 *
 * @param {Array<{is_completed?: boolean, weight?: number|null, reps?: number|null}>} series
 * @returns {number}
 */
export function volumeDesSeriesValidees(series) {
    return seriesValidees(series).reduce((total, serie) => total + (serie.weight || 0) * (serie.reps || 0), 0)
}

/**
 * Le volume de travail : celui de la fiche exercice, sans les échauffements
 * validés que `volumeDesSeriesValidees()` garde, comme
 * `Workout::recalculerLeVolume()`, dont le volume de la séance, des objectifs
 * et des statistiques globales est tiré côté serveur.
 *
 * @param {Array<{is_completed?: boolean, is_warmup?: boolean, weight?: number|null, reps?: number|null}>} series
 * @returns {number}
 */
export function volumeDesSeriesDeTravail(series) {
    return volumeDesSeriesValidees(seriesDeTravail(series))
}
