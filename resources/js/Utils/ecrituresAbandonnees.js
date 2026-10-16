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
 * contenaient les écritures. L'avis d'un compte qui ne revient pas s'efface
 * lui-même trente jours après avoir été noté.
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

/**
 * Les avis encore valables, par compte. Un stockage illisible ou refusé vaut
 * « aucun avis ».
 *
 * @param {number} maintenant
 * @returns {Record<string, {nombre: number, depuis: number|null, le: number}>}
 */
const lire = (maintenant) => {
    try {
        const avis = JSON.parse(localStorage.getItem(CLEF) ?? '{}')

        if (avis === null || typeof avis !== 'object' || Array.isArray(avis)) {
            return {}
        }

        return Object.fromEntries(
            Object.entries(avis).filter(
                ([, unAvis]) => estUnAvis(unAvis) && maintenant - unAvis.le <= DUREE_EN_FILE_AU_PLUS_MS,
            ),
        )
    } catch {
        return {}
    }
}

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
