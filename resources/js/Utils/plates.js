/**
 * Chargement d'une barre : quels disques poser, et pour quel poids réel.
 *
 * Extrait de Pages/Tools/PlateCalculator.vue, où cette logique vivait dans un
 * `computed` et n'était couverte par aucun test. Elle est pourtant la plus
 * piégeuse de l'outil : sélection gloutonne, disponibilité par paires, et
 * arithmétique flottante sur des demi-disques.
 */

/**
 * Marge d'arrondi sous laquelle le reliquat est considéré comme nul.
 *
 * 0.5 - 0.2 - 0.3 ne vaut pas 0 en binaire mais 5.55e-17. Sans ce seuil, le
 * reliquat resterait positif et l'algorithme continuerait de chercher des
 * disques pour un poids qui n'existe plus.
 */
const FLOAT_TOLERANCE = 0.01

/**
 * @typedef {{ weight: number|string, quantity: number }} PlateStock
 */

/**
 * Disques à poser d'un seul côté de la barre, du plus lourd au plus léger.
 *
 * Les disques se posant par paires, seules les paires complètes comptent : un
 * inventaire de 3 disques de 20 kg n'en offre qu'une seule utilisable.
 *
 * @param {number} targetWeight  poids visé, barre comprise
 * @param {number} barWeight     poids de la barre à vide
 * @param {PlateStock[]} inventory
 * @returns {{ weight: number }[]}
 */
export function calculatePlates(targetWeight, barWeight, inventory = []) {
    if (!targetWeight || !barWeight || targetWeight <= barWeight) {
        return []
    }

    let remaining = (targetWeight - barWeight) / 2
    const result = []

    const sorted = inventory
        .map((plate) => ({ ...plate, weight: parseFloat(plate.weight) }))
        .filter((plate) => Number.isFinite(plate.weight) && plate.weight > 0)
        .sort((a, b) => b.weight - a.weight)

    for (const plate of sorted) {
        const availablePairs = Math.floor(plate.quantity / 2)
        let pairsUsed = 0

        while (remaining >= plate.weight && pairsUsed < availablePairs) {
            remaining -= plate.weight
            pairsUsed++
            result.push({ weight: plate.weight })
        }

        if (remaining < FLOAT_TOLERANCE) {
            remaining = 0
        }
    }

    return result
}

/**
 * Poids réellement chargé — barre plus les disques des DEUX côtés.
 *
 * Il diffère du poids visé dès que l'inventaire ne permet pas de l'atteindre
 * exactement, ce qui est le cas courant.
 *
 * @param {{ weight: number }[]} plates  disques d'un seul côté
 * @param {number} barWeight
 * @returns {number}
 */
export function actualWeight(plates, barWeight) {
    const perSide = plates.reduce((sum, plate) => sum + plate.weight, 0)

    return barWeight + perSide * 2
}
