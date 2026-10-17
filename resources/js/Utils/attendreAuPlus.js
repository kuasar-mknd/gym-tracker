/**
 * Attend `promesse`, mais pas plus de `delai` millisecondes : se règle à la
 * première des deux échéances, sans jamais être rejetée.
 *
 * Ce qu'attendent « Terminer » et la déconnexion avant de compter ce qui n'est
 * pas encore enregistré (#1961, #2050) : la file et les écritures en vol n'ont
 * pas de délai maximum, et un réseau lent ne doit pas figer l'écran.
 *
 * @param {Promise<unknown>} promesse
 * @param {number} delai en millisecondes
 * @returns {Promise<void>}
 */
export const attendreAuPlus = (promesse, delai) =>
    new Promise((resoudre) => {
        const echeance = setTimeout(resoudre, delai)
        const fin = () => {
            clearTimeout(echeance)
            resoudre()
        }

        promesse.then(fin, fin)
    })
