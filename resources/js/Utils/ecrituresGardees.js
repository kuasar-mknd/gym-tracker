/**
 * Ce que la déconnexion laisse en file pour le compte qui part, dit sur l'écran
 * qui la suit (#1964).
 *
 * Les écritures faites hors ligne et pas encore envoyées appartiennent à leur
 * compte : elles restent sur l'appareil et ne partent qu'à sa prochaine
 * connexion, jamais sous la session d'un autre. La déconnexion ne les attend
 * pas, mais elle ne les tait pas non plus. Elle navigue aussitôt vers l'écran
 * de connexion : l'avis passe donc par le stockage de l'onglet, et l'écran
 * d'arrivée le lit une fois.
 *
 * Seul le nombre y est écrit, rien de ce que contiennent les écritures. Un avis
 * de plus d'une minute est périmé : celui d'une déconnexion qui n'a pas abouti,
 * faute de réseau, ne doit pas ressortir des heures plus tard.
 */
const CLEF = 'gym-tracker:ecritures-gardees'

const DUREE_DE_VALIDITE_MS = 60 * 1000

/** @param {number} nombre les écritures du compte encore en file */
export const noterLesEcrituresGardees = (nombre) => {
    try {
        if (nombre > 0) {
            sessionStorage.setItem(CLEF, JSON.stringify({ nombre, le: Date.now() }))

            return
        }

        sessionStorage.removeItem(CLEF)
    } catch {
        // Stockage indisponible : l'avis se perd, pas les écritures.
    }
}

/**
 * Rend le nombre noté par la dernière déconnexion, et l'oublie.
 *
 * @returns {number}
 */
export const reprendreLesEcrituresGardees = () => {
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
export const messageDesEcrituresGardees = (nombre) =>
    nombre > 1
        ? `${nombre} modifications faites hors ligne n'ont pas encore été envoyées. Elles restent sur cet appareil et partiront à la prochaine connexion de ce compte, jamais sous un autre.`
        : "Une modification faite hors ligne n'a pas encore été envoyée. Elle reste sur cet appareil et partira à la prochaine connexion de ce compte, jamais sous un autre."
