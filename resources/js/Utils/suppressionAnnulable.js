import { ref } from 'vue'

/** Le temps laissé pour annuler une suppression, avant qu'elle ne parte. */
export const DELAI_POUR_ANNULER_MS = 6000

/**
 * La suppression en attente d'annulation : ce que le toast du layout affiche,
 * `null` quand il n'y en a pas. Une seule à la fois ; une nouvelle valide
 * d'abord la précédente.
 *
 * @type {import('vue').Ref<{ message: string, auClavier: boolean }|null>}
 */
export const suppressionAnnulable = ref(null)

let courante = null
let minuteur = null

/** Le retrait vient du clavier : le focus ira sur « Annuler la suppression », que la corbeille disparue laisse sans cible. */
const geste = () => {
    try {
        return document.activeElement?.matches(':focus-visible') ?? false
    } catch {
        return false
    }
}

const armer = () => {
    clearTimeout(minuteur)
    minuteur = setTimeout(() => valider(), DELAI_POUR_ANNULER_MS)
}

const clore = () => {
    clearTimeout(minuteur)
    minuteur = null
    courante = null
    suppressionAnnulable.value = null
}

/** Les suppressions parties dont la réponse n'est pas revenue : la copie hors ligne attend qu'elles aient abouti. */
export const suppressionsEnVol = ref(0)

/**
 * L'échéance, ou tout ce qui ne peut plus l'attendre : la suppression part maintenant.
 *
 * @param {{ surDepart?: boolean }} [options] la page part ou se cache : l'envoi doit être durable
 * @returns {Promise<unknown>|undefined} l'envoi, que « Terminer » attend
 */
export function valider(options) {
    const suppression = courante

    clore()

    const envoi = suppression?.valider(options)

    if (envoi !== undefined) {
        suppressionsEnVol.value++
        envoi.finally(() => suppressionsEnVol.value--)
    }

    return envoi
}

/**
 * Annonce une suppression déjà faite à l'écran, qui ne partira qu'à l'échéance.
 *
 * `porte` dit si une rangée est celle qui attend : une fusion des props, qui
 * rend encore la série du serveur, ne la remet pas à l'écran.
 *
 * @param {{ message: string, porte: (set: object) => boolean, valider: () => void, annuler: () => void }} suppression
 */
export const proposerUneAnnulation = (suppression) => {
    valider()
    courante = suppression
    suppressionAnnulable.value = { message: suppression.message, auClavier: geste() }
    armer()
}

/** « Annuler » : la suppression ne part pas, l'écran reprend ce qu'il avait. */
export const annuler = () => {
    const suppression = courante

    clore()
    suppression?.annuler()
}

/** La suppression n'a plus lieu d'être (la ligne qui la portait est partie) : ni envoyée ni annulable. */
export const abandonner = () => {
    clore()
}

/** Survol ou focus sur le toast : le compte à rebours attend, pour qu'on ait le temps de l'atteindre. */
export const suspendre = () => {
    if (courante !== null) {
        clearTimeout(minuteur)
    }
}

export const reprendre = () => {
    if (courante !== null) {
        armer()
    }
}

/** Cette rangée est celle dont la suppression attend. */
export const estEnAttenteDeSuppression = (set) => courante?.porte(set) ?? false
