/**
 * Ce que le chargement a effacé de la file hors ligne sans pouvoir l'envoyer,
 * dit une fois sur le premier écran authentifié (#1964).
 *
 * Une écriture mise en file, ou refusée, avant que la file ne note son compte
 * ne dit pas à qui elle appartient. Elle ne part jamais, pour ne pas partir sous
 * un autre compte, et `SyncService` l'efface à son chargement. La version
 * précédente la rejouait : une séance faite hors ligne, puis l'application
 * rouverte après la mise à jour, perdait ses dernières séries sans un mot.
 *
 * Seul le nombre est gardé, rien de ce que contenaient les écritures. Il reste
 * dans le stockage de l'appareil, et non en mémoire, jusqu'à ce qu'un écran le
 * montre : l'effacement, lui, est déjà écrit.
 */
const CLEF = 'gym-tracker:ecritures-effacees'

/** @returns {number} */
const lire = () => {
    const nombre = Number(localStorage.getItem(CLEF) ?? 0)

    return Number.isInteger(nombre) && nombre > 0 ? nombre : 0
}

/** @param {number} nombre les écritures sans compte que le chargement vient d'effacer */
export const noterLesEcrituresEffacees = (nombre) => {
    if (!(nombre > 0)) {
        return
    }

    try {
        localStorage.setItem(CLEF, String(lire() + nombre))
    } catch {
        // Stockage indisponible : l'avis se perd, l'effacement reste.
    }
}

/**
 * Rend le nombre noté, et l'oublie.
 *
 * @returns {number}
 */
export const reprendreLesEcrituresEffacees = () => {
    try {
        const nombre = lire()

        localStorage.removeItem(CLEF)

        return nombre
    } catch {
        return 0
    }
}

/** @param {number} nombre */
export const messageDesEcrituresEffacees = (nombre) =>
    nombre > 1
        ? `${nombre} modifications faites hors ligne avant la mise à jour de l'application n'ont pas pu être enregistrées et ont été effacées de cet appareil. Vérifie ta dernière séance.`
        : "Une modification faite hors ligne avant la mise à jour de l'application n'a pas pu être enregistrée et a été effacée de cet appareil. Vérifie ta dernière séance."
