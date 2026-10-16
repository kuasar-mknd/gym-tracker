/**
 * Le dernier texte cherché dans « Ajouter un exercice », gardé sur l'appareil
 * pour qu'une fenêtre rouverte le retrouve.
 *
 * Il survivait à la déconnexion, et la personne suivante lisait ce que le
 * compte précédent avait cherché. La déconnexion acceptée et la suppression du
 * compte l'effacent (#2050). Un stockage refusé ne bloque ni la fenêtre ni la
 * déconnexion : la recherche repart vide, ou reste où elle était.
 */
export const CLEF_DE_LA_RECHERCHE = 'gymtracker_add_exercise_search'

/** @returns {string} */
export const lireLaRecherche = () => {
    try {
        return localStorage.getItem(CLEF_DE_LA_RECHERCHE) ?? ''
    } catch {
        return ''
    }
}

/** @param {string} texte */
export const garderLaRecherche = (texte) => {
    try {
        localStorage.setItem(CLEF_DE_LA_RECHERCHE, texte)
    } catch {
        // Stockage plein ou refusé : la recherche ne survivra pas à la fermeture.
    }
}

export const effacerLaRecherche = () => {
    try {
        localStorage.removeItem(CLEF_DE_LA_RECHERCHE)
    } catch {
        // Stockage refusé : rien n'a pu y être écrit non plus.
    }
}
