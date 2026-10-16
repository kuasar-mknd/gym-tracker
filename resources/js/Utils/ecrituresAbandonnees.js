import { dateCourte } from '@/Utils/date'

/**
 * Les écritures hors ligne qu'aucun envoi n'a pu faire aboutir en trente jours,
 * abandonnées et effacées de l'appareil, et dites au compte qui les avait
 * faites (#2050).
 *
 * La file hors ligne (`SyncService`) garde en clair, dans le stockage de
 * l'appareil, la charge de chaque écriture : poids, répétitions, notes. Une
 * entrée qui ne part jamais (un compte qui ne revient pas, supprimé depuis un
 * autre appareil ou depuis le panneau, une session expirée jamais rouverte)
 * restait pour toujours. Le propriétaire a fixé une durée : trente jours au
 * plus. Au-delà, l'entrée est abandonnée et effacée, et la personne le sait :
 * « ne jamais perdre en silence une série saisie hors ligne » (#1964).
 *
 * Seuls le nombre et la date de la plus ancienne sont gardés ici, par compte,
 * jusqu'à ce que ce compte ouvre une page sur cet appareil, rien de ce que
 * contenaient les écritures. L'avis attend son compte trente jours au plus :
 * passé ce délai, la première lecture l'efface du stockage (le chargement de
 * la file, chaque vidage, chaque écran authentifié), et un compte qui revient
 * plus tard n'en voit rien. Le garder pour toujours garderait l'identifiant
 * d'un compte qui ne reviendra pas, supprimé ailleurs, sur l'appareil.
 */
const CLEF = 'gym-tracker:ecritures-abandonnees'

/** Ce qu'une écriture peut attendre sur l'appareil, au plus, avant d'être abandonnée. */
export const JOURS_EN_FILE_AU_PLUS = 30

export const DUREE_EN_FILE_AU_PLUS_MS = JOURS_EN_FILE_AU_PLUS * 24 * 60 * 60 * 1000

/** Un compte tel que la file le note : une chaîne, ou null. */
const normaliser = (compte) => (compte === undefined || compte === null || compte === '' ? null : String(compte))

/** @param {unknown} avis */
const estUnAvis = (avis) =>
    avis !== null &&
    typeof avis === 'object' &&
    Number.isInteger(avis.nombre) &&
    avis.nombre > 0 &&
    Number.isFinite(avis.le) &&
    (avis.depuis === null || Number.isFinite(avis.depuis))

/** @param {Record<string, object>} avis */
const ecrire = (avis) => {
    try {
        if (Object.keys(avis).length === 0) {
            localStorage.removeItem(CLEF)

            return
        }

        localStorage.setItem(CLEF, JSON.stringify(avis))
    } catch {
        // Stockage plein ou refusé : l'avis se perd, l'effacement est déjà fait.
    }
}

/**
 * Le contenu du stockage, ou null quand il ne se lit pas.
 *
 * @returns {Record<string, unknown>|null}
 */
const lireLeStockage = () => {
    try {
        const avis = JSON.parse(localStorage.getItem(CLEF) ?? '{}')

        return avis !== null && typeof avis === 'object' && !Array.isArray(avis) ? avis : null
    } catch {
        return null
    }
}

/**
 * Les avis encore valables, par compte. Ceux de plus de trente jours, et ce
 * qui ne se lit pas, sont effacés du stockage, pas seulement écartés : l'avis
 * d'un compte qui ne revient pas y restait pour toujours. Un stockage illisible
 * ou refusé vaut « aucun avis ».
 *
 * @param {number} maintenant
 * @returns {Record<string, {nombre: number, depuis: number|null, le: number}>}
 */
const lire = (maintenant) => {
    const stocke = lireLeStockage()
    const entrees = Object.entries(stocke ?? {})
    const valables = Object.fromEntries(
        entrees.filter(([, unAvis]) => estUnAvis(unAvis) && maintenant - unAvis.le <= DUREE_EN_FILE_AU_PLUS_MS),
    )

    if (stocke === null || Object.keys(valables).length < entrees.length) {
        ecrire(valables)
    }

    return valables
}

/**
 * Efface du stockage les avis qui ont attendu leur compte plus de trente
 * jours. La file l'appelle à son chargement et à chaque vidage, comme elle
 * abandonne ses propres entrées : même sur un appareil où plus personne ne se
 * connecte.
 *
 * @param {number} maintenant
 */
export const oublierLesAvisPerimes = (maintenant = Date.now()) => {
    lire(maintenant)
}

/** La plus ancienne de deux dates, l'une ou l'autre pouvant manquer. */
const plusAncienne = (a, b) => {
    if (a === null) {
        return b
    }

    return b === null ? a : Math.min(a, b)
}

/**
 * Note, compte par compte, ce que la file vient d'abandonner. Un avis qui
 * attend encore son compte s'additionne au nouveau.
 *
 * @param {Map<string, {nombre: number, depuis: number|null}>} abandons
 * @param {number} maintenant
 */
export const noterLesEcrituresAbandonnees = (abandons, maintenant = Date.now()) => {
    if (abandons.size === 0) {
        return
    }

    const avis = lire(maintenant)

    abandons.forEach(({ nombre, depuis }, compte) => {
        const precedent = avis[compte]

        avis[compte] = {
            nombre: (precedent?.nombre ?? 0) + nombre,
            depuis: plusAncienne(precedent?.depuis ?? null, depuis),
            le: maintenant,
        }
    })

    ecrire(avis)
}

/**
 * Rend l'avis de ce compte, et l'oublie.
 *
 * @param {number|string|null|undefined} compte
 * @returns {{nombre: number, depuis: number|null}|null}
 */
export const reprendreLesEcrituresAbandonnees = (compte) => {
    const clef = normaliser(compte)
    const avis = lire(Date.now())
    const avisDuCompte = clef === null ? undefined : avis[clef]

    if (avisDuCompte === undefined) {
        return null
    }

    delete avis[clef]
    ecrire(avis)

    return { nombre: avisDuCompte.nombre, depuis: avisDuCompte.depuis }
}

/**
 * Oublie l'avis d'un compte qui quitte l'appareil : déconnecté, ou supprimé.
 *
 * @param {number|string|null|undefined} compte
 */
export const oublierLesEcrituresAbandonnees = (compte) => {
    const clef = normaliser(compte)
    const avis = lire(Date.now())

    if (clef === null || avis[clef] === undefined) {
        return
    }

    delete avis[clef]
    ecrire(avis)
}

/**
 * Ce que l'écran dit : combien, et depuis quand.
 *
 * @param {{nombre: number, depuis: number|null}} avis
 * @returns {string}
 */
export const messageDesEcrituresAbandonnees = ({ nombre, depuis }) => {
    const date = depuis === null ? '' : dateCourte(new Date(depuis))

    if (nombre > 1) {
        const quand = date === '' ? `depuis plus de ${JOURS_EN_FILE_AU_PLUS} jours` : `depuis le ${date}`

        return `${nombre} modifications faites hors ligne ${quand} n'ont pas pu être enregistrées : gardées ${JOURS_EN_FILE_AU_PLUS} jours sur cet appareil sans pouvoir partir, elles ont été abandonnées et effacées. Vérifie tes séances.`
    }

    const quand = date === '' ? `il y a plus de ${JOURS_EN_FILE_AU_PLUS} jours` : `le ${date}`

    return `Une modification faite hors ligne ${quand} n'a pas pu être enregistrée : gardée ${JOURS_EN_FILE_AU_PLUS} jours sur cet appareil sans pouvoir partir, elle a été abandonnée et effacée. Vérifie tes séances.`
}
