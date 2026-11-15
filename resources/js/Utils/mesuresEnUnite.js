const CENTIMETRES_PAR_POUCE = 2.54

/**
 * @param {number} valeur
 * @param {string} depuis
 * @param {string} vers
 * @returns {number}
 */
export const convertirLaMesure = (valeur, depuis, vers) => {
    if (depuis === vers) {
        return valeur
    }

    return vers === 'cm' ? valeur * CENTIMETRES_PAR_POUCE : valeur / CENTIMETRES_PAR_POUCE
}

/**
 * Chaque relevé de la courbe dans l'unité du dernier : la saisie accepte le
 * centimètre et le pouce, et une courbe ne porte qu'une unité.
 *
 * @param {Array<{ value: number|string, unit: string }>} releves Du plus ancien au plus récent.
 * @returns {Array<{ value: number, unit: string }>}
 */
export const courbeDansLUniteDuDernierReleve = (releves) => {
    if (releves.length === 0) {
        return []
    }

    const unite = releves[releves.length - 1].unit

    return releves.map((releve) => ({
        ...releve,
        value: Math.round(convertirLaMesure(Number(releve.value), releve.unit, unite) * 100) / 100,
        unit: unite,
    }))
}
