/**
 * Un verrou d'écran factice (`navigator.wakeLock`) et la visibilité de la page,
 * qui tiennent les règles de la spécification Screen Wake Lock dont le minuteur
 * d'intervalles dépend (#2113). jsdom n'a ni l'un, ni une page qui se cache.
 *
 * - `request('screen')` rend une sentinelle quand la page est visible ; cachée,
 *   la demande est refusée (`NotAllowedError`), comme quand le navigateur la
 *   refuse de lui-même (économie d'énergie : `verrouDEcran.refuser`).
 * - Une demande peut rester en attente (`verrouDEcran.retenir`) jusqu'à ce que
 *   le test la règle (`reglerLesDemandes()`) : elle est alors refusée si la
 *   page s'est cachée entre-temps, comme dans la spécification, qui juge la
 *   visibilité au moment de rendre la sentinelle.
 * - Le navigateur relâche chaque verrou quand la page se cache : `cacherLaPage()`
 *   relâche les sentinelles actives (`release`, `relachePar: 'systeme'`), puis
 *   émet `visibilitychange` ; `montrerLaPage()` la rend visible et l'émet.
 * - `release()` relâche une seule fois, émet `release` et marque `released`.
 *
 * Chaque demande se range dans `demandes`, avec la visibilité de la page à son
 * départ, et chaque sentinelle rendue dans `verrous`. Le faux se pose dans le
 * `beforeEach` (`installerLeVerrouDEcran()`) et se retire dans l'`afterEach`
 * (`retirerLeVerrouDEcran()`), qui rend aussi à `document` sa visibilité.
 */

/** Les réglages du navigateur simulé, remis à zéro à chaque installation. */
export const verrouDEcran = { refuser: false, retenir: false }

/** Chaque appel à `request`, dans l'ordre : son type et la visibilité de la page. */
export const demandes = []

/** Chaque sentinelle rendue, dans l'ordre. */
export const verrous = []

let visibilite = 'visible'
let enAttente = []

/** Une sentinelle de verrou d'écran : `type`, `released`, `release()`, et l'évènement `release`. */
export class SentinelleFactice extends EventTarget {
    constructor(type) {
        super()
        this.type = type
        this.released = false
        /** Qui l'a relâchée : `page` (par `release()`) ou `systeme` (la page cachée). */
        this.relachePar = null
        verrous.push(this)
    }

    release() {
        this.liberer('page')

        return Promise.resolve()
    }

    /** @param {'page'|'systeme'} par */
    liberer(par) {
        if (this.released) {
            return
        }

        this.released = true
        this.relachePar = par
        this.dispatchEvent(new Event('release'))
    }
}

const refus = (raison) => new DOMException(raison, 'NotAllowedError')

const api = {
    request(type) {
        demandes.push({ type, visibilite })

        if (type !== 'screen') {
            return Promise.reject(new TypeError(`Type de verrou inconnu : ${type}`))
        }

        if (visibilite !== 'visible') {
            return Promise.reject(refus('La page est cachée.'))
        }

        if (verrouDEcran.refuser) {
            return Promise.reject(refus('Le navigateur refuse le verrou.'))
        }

        if (verrouDEcran.retenir) {
            return new Promise((resoudre, rejeter) => {
                enAttente.push({ type, resoudre, rejeter })
            })
        }

        return Promise.resolve(new SentinelleFactice(type))
    },
}

/** Les sentinelles que rien n'a relâchées. */
export const verrousActifs = () => verrous.filter((verrou) => !verrou.released)

/** Rend les demandes retenues : une sentinelle si la page est visible, un refus sinon. */
export const reglerLesDemandes = () => {
    const demandesRetenues = enAttente
    enAttente = []

    for (const { type, resoudre, rejeter } of demandesRetenues) {
        if (visibilite === 'visible') {
            resoudre(new SentinelleFactice(type))
        } else {
            rejeter(refus('La page est cachée.'))
        }
    }
}

/** La page se cache : le navigateur relâche chaque verrou, puis l'annonce. */
export const cacherLaPage = () => {
    visibilite = 'hidden'
    verrousActifs().forEach((verrou) => verrou.liberer('systeme'))
    document.dispatchEvent(new Event('visibilitychange'))
}

/** La page revient au premier plan. */
export const montrerLaPage = () => {
    visibilite = 'visible'
    document.dispatchEvent(new Event('visibilitychange'))
}

/**
 * Pose le faux sur `navigator.wakeLock`, et la visibilité simulée sur
 * `document`. Sans l'API (`sansApi`), seule la visibilité se pose : un
 * navigateur qui n'a pas le verrou d'écran.
 *
 * @param {{ sansApi?: boolean }} [options]
 */
export const installerLeVerrouDEcran = ({ sansApi = false } = {}) => {
    verrouDEcran.refuser = false
    verrouDEcran.retenir = false
    demandes.length = 0
    verrous.length = 0
    enAttente = []
    visibilite = 'visible'

    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => visibilite })
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => visibilite !== 'visible' })

    if (sansApi) {
        delete navigator.wakeLock
    } else {
        Object.defineProperty(navigator, 'wakeLock', { configurable: true, value: api })
    }
}

/** Retire le faux et la visibilité simulée, et oublie demandes et verrous. */
export const retirerLeVerrouDEcran = () => {
    delete navigator.wakeLock
    delete document.visibilityState
    delete document.hidden
    demandes.length = 0
    verrous.length = 0
    enAttente = []
    visibilite = 'visible'
}
