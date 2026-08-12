/**
 * Progression d'échauffement : quel poids pour chaque palier.
 *
 * Extrait de Pages/Tools/WarmupCalculator.vue, où cette logique vivait dans un
 * `computed` sans aucun test. Un arrondi faux y produit un poids infaisable —
 * une barre qu'on ne peut pas charger — sans que rien ne le signale.
 */

/**
 * Arrondit au multiple d'incrément le plus proche.
 *
 * L'incrément représente le plus petit saut chargeable : avec des disques de
 * 1,25 kg par paire, la barre ne progresse que par 2,5 kg.
 *
 * @param {number} weight
 * @param {number} increment
 * @returns {number}
 */
export function roundWeight(weight, increment) {
    if (!increment) {
        return weight
    }

    return Math.round(weight / increment) * increment
}

/**
 * @typedef {{ percent: number, reps?: number }} WarmupStep
 */

/**
 * Paliers d'échauffement calculés à partir du poids visé.
 *
 * Deux règles portent tout le sens :
 *
 * - un palier à 0 % est la barre à vide, pas un calcul de pourcentage ;
 * - aucun palier ne descend sous le poids de la barre. Sans ce plancher, un
 *   premier palier à 10 % d'une charge légère demanderait de charger moins que
 *   la barre elle-même, ce qui n'existe pas.
 *
 * @param {WarmupStep[]} steps
 * @param {{ targetWeight: number, barWeight: number, roundingIncrement: number }} options
 * @returns {(WarmupStep & { weight: number, plateLoad: string })[]}
 */
export function calculateWarmupSets(steps, { targetWeight, barWeight, roundingIncrement }) {
    return steps.map((step) => {
        const weight =
            step.percent === 0
                ? barWeight
                : Math.max(barWeight, roundWeight(targetWeight * (step.percent / 100), roundingIncrement))

        const perSide = Math.max(0, weight - barWeight) / 2

        return {
            ...step,
            weight,
            plateLoad: perSide > 0 ? `${perSide}kg` : 'Vide',
        }
    })
}
