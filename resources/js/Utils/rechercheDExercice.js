import SyncService from '@/Utils/SyncService'

/**
 * Le dernier texte cherché dans « Ajouter un exercice », gardé sur l'appareil
 * pour qu'une fenêtre rouverte le retrouve.
 *
 * Il survivait à la déconnexion, et la personne suivante lisait ce que le
 * compte précédent avait cherché. La déconnexion acceptée et la suppression du
 * compte l'effacent (#2050). Un stockage refusé ne bloque ni la fenêtre ni la
 * déconnexion : la recherche repart vide, ou reste où elle était.
 *
 * Une session peut aussi se fermer sans déconnexion : expirée, ou fermée par un
 * mot de passe changé ailleurs. Rien ne l'effaçait alors, et le compte suivant
 * la retrouvait. Elle est donc rangée avec le compte qui l'a tapée, celui que
 * la file hors ligne sait connecté (`SyncService.compte`) : celle d'un autre
 * compte, ou d'une version qui ne disait pas le sien, ne se lit pas et
 * s'efface. Une page d'invité sans session l'efface aussi (`GuestLayout`).
 */
export const CLEF_DE_LA_RECHERCHE = 'gymtracker_add_exercise_search'

/** Le compte connecté, tel que la file le note : une chaîne, ou null. */
const compteConnecte = () => SyncService.compte ?? null

export const effacerLaRecherche = () => {
    try {
        localStorage.removeItem(CLEF_DE_LA_RECHERCHE)
    } catch {
        // Stockage refusé : rien n'a pu y être écrit non plus.
    }
}

/** @returns {string} la recherche du compte connecté, ou rien */
export const lireLaRecherche = () => {
    try {
        const valeur = localStorage.getItem(CLEF_DE_LA_RECHERCHE)

        if (valeur === null) {
            return ''
        }

        const recherche = JSON.parse(valeur)

        if (recherche?.compte === compteConnecte() && typeof recherche.texte === 'string') {
            return recherche.texte
        }
    } catch {
        // Illisible : celle d'une version qui la gardait en texte seul, sans son compte.
    }

    effacerLaRecherche()

    return ''
}

/** @param {string} texte */
export const garderLaRecherche = (texte) => {
    try {
        localStorage.setItem(CLEF_DE_LA_RECHERCHE, JSON.stringify({ compte: compteConnecte(), texte }))
    } catch {
        // Stockage plein ou refusé : la recherche ne survivra pas à la fermeture.
    }
}
