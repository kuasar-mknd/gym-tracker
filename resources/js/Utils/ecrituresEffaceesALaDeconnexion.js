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

/**
 * Les écritures que la file avait abandonnées pour le compte qui part, après
 * trente jours, et dont l'avis attendait encore un écran de ce compte
 * (`Utils/ecrituresAbandonnees`). La déconnexion efface cet avis avec le reste,
 * et il n'avait pas toujours été montré : la vérification de l'adresse est sous
 * le layout invité, qui ne le lit pas, et un autre message pouvait occuper le
 * toast. La perte passait alors sans un mot ; l'écran de connexion la dit à sa
 * place, une fois, comme le nombre ci-dessus. Seuls le nombre et la date de la
 * plus ancienne y sont écrits.
 */
const CLEF_DES_ABANDONS = 'gym-tracker:ecritures-abandonnees-a-la-deconnexion'

const DUREE_DE_VALIDITE_MS = 60 * 1000

/**
 * Écrit un avis pour l'écran d'arrivée, daté, ou l'efface quand il n'y a rien
 * à dire.
 *
 * @param {string} cle
 * @param {Object|null} avis
 */
const noter = (cle, avis) => {
    try {
        if (avis !== null) {
            sessionStorage.setItem(cle, JSON.stringify({ ...avis, le: Date.now() }))

            return
        }

        sessionStorage.removeItem(cle)
    } catch {
        // Stockage indisponible : l'avis se perd, l'effacement suit son cours.
    }
}

/**
 * Rend l'avis noté par la dernière déconnexion s'il a moins d'une minute, et
 * l'oublie. Un avis illisible ou un stockage qui refuse valent « rien ».
 *
 * @param {string} cle
 * @returns {Object|null}
 */
const reprendre = (cle) => {
    try {
        const avis = JSON.parse(sessionStorage.getItem(cle) ?? 'null')

        sessionStorage.removeItem(cle)

        return Number.isFinite(avis?.le) && Date.now() - avis.le < DUREE_DE_VALIDITE_MS ? avis : null
    } catch {
        return null
    }
}

/** @param {number} nombre les écritures du compte encore en file, que la déconnexion efface */
export const noterLesEcrituresEffaceesALaDeconnexion = (nombre) => noter(CLEF, nombre > 0 ? { nombre } : null)

/**
 * Rend le nombre noté par la dernière déconnexion, et l'oublie.
 *
 * @returns {number}
 */
export const reprendreLesEcrituresEffaceesALaDeconnexion = () => {
    const avis = reprendre(CLEF)

    return Number.isInteger(avis?.nombre) && avis.nombre > 0 ? avis.nombre : 0
}

/**
 * @param {{nombre: number, depuis: number|null}|null} abandonnees l'avis des
 *   écritures abandonnées du compte qui part, que la déconnexion efface, ou
 *   null
 */
export const noterLesAbandonsALaDeconnexion = (abandonnees) =>
    noter(CLEF_DES_ABANDONS, abandonnees === null ? null : { nombre: abandonnees.nombre, depuis: abandonnees.depuis })

/**
 * Rend l'avis des écritures abandonnées noté par la dernière déconnexion, et
 * l'oublie.
 *
 * @returns {{nombre: number, depuis: number|null}|null}
 */
export const reprendreLesAbandonsALaDeconnexion = () => {
    const avis = reprendre(CLEF_DES_ABANDONS)

    if (!Number.isInteger(avis?.nombre) || avis.nombre <= 0) {
        return null
    }

    return { nombre: avis.nombre, depuis: Number.isFinite(avis.depuis) ? avis.depuis : null }
}

/**
 * Ce que dit un onglet resté sur une page du compte quand ce compte quitte
 * l'appareil depuis un autre onglet : déconnecté après avoir accepté de perdre
 * ses écritures, ou supprimé (`sync:compte-parti`). La session est commune aux
 * onglets : ce qu'on fait ensuite dans celui-ci n'est plus enregistré, ni
 * gardé sur l'appareil, et chaque écriture qui échoue le dit aussi.
 */
export const SESSION_FINIE_DANS_UN_AUTRE_ONGLET =
    "La session de ce compte a pris fin dans un autre onglet : ce qui n'était pas encore envoyé a été effacé de cet appareil. Reconnecte-toi pour continuer, sinon tes modifications ne seront pas enregistrées."

/** @param {number} nombre */
export const messageDesEcrituresEffaceesALaDeconnexion = (nombre) =>
    nombre > 1
        ? `${nombre} modifications faites hors ligne n'avaient pas encore été envoyées : elles ont été effacées de cet appareil avec la déconnexion.`
        : "Une modification faite hors ligne n'avait pas encore été envoyée : elle a été effacée de cet appareil avec la déconnexion."
