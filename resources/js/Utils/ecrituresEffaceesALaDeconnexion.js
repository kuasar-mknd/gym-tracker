/**
 * Ce que la déconnexion a effacé de la file hors ligne pour le compte qui
 * part, dit sur l'écran qui la suit (#2050).
 *
 * Depuis #1964, la file gardait les écritures du compte jusqu'à sa prochaine
 * connexion, en clair sur l'appareil, et l'écran de connexion le disait. Le
 * propriétaire a tranché : la déconnexion prévient d'abord, puis efface. La
 * personne a accepté de les perdre (`seDeconnecter`) ; l'écran d'arrivée dit
 * combien ont été effacées, une fois, pour que rien ne se perde sans un mot.
 * Elle navigue aussitôt vers l'écran de connexion : l'avis passe donc par le
 * stockage de l'onglet, et l'écran d'arrivée le lit à son montage.
 *
 * Seul le nombre y est écrit, rien de ce que contenaient les écritures. Un avis
 * de plus d'une minute est périmé : celui d'une déconnexion qui n'a pas abouti,
 * faute de réseau, ne doit pas ressortir des heures plus tard.
 */
const CLEF = 'gym-tracker:ecritures-effacees-a-la-deconnexion'

const DUREE_DE_VALIDITE_MS = 60 * 1000

/** @param {number} nombre les écritures du compte encore en file, que la déconnexion efface */
export const noterLesEcrituresEffaceesALaDeconnexion = (nombre) => {
    try {
        if (nombre > 0) {
            sessionStorage.setItem(CLEF, JSON.stringify({ nombre, le: Date.now() }))

            return
        }

        sessionStorage.removeItem(CLEF)
    } catch {
        // Stockage indisponible : l'avis se perd, l'effacement suit son cours.
    }
}

/**
 * Rend le nombre noté par la dernière déconnexion, et l'oublie.
 *
 * @returns {number}
 */
export const reprendreLesEcrituresEffaceesALaDeconnexion = () => {
    try {
        const avis = JSON.parse(sessionStorage.getItem(CLEF) ?? 'null')

        sessionStorage.removeItem(CLEF)

        const recent = Number.isFinite(avis?.le) && Date.now() - avis.le < DUREE_DE_VALIDITE_MS

        return recent && Number.isInteger(avis.nombre) && avis.nombre > 0 ? avis.nombre : 0
    } catch {
        return 0
    }
}

/** @param {number} nombre */
export const messageDesEcrituresEffaceesALaDeconnexion = (nombre) =>
    nombre > 1
        ? `${nombre} modifications faites hors ligne n'avaient pas encore été envoyées : elles ont été effacées de cet appareil avec la déconnexion.`
        : "Une modification faite hors ligne n'avait pas encore été envoyée : elle a été effacée de cet appareil avec la déconnexion."
