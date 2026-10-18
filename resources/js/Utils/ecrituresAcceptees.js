/**
 * La note des écritures de séance acceptées en ligne (#2048, partie D).
 *
 * La copie hors ligne d'une séance (`useCopieDeLaSeance`) n'est prise que
 * quand tout est enregistré, une seconde après le dernier changement : une
 * série acceptée juste avant de quitter la page, ou pendant qu'une autre
 * écriture attendait encore, peut donc y manquer. Chaque écriture directe de
 * la séance que le serveur accepte note aussitôt ici sa séance et l'heure
 * (`SyncService.request`). Une page qui montrera une copie plus ancienne que
 * cette note (partie E) saura dire « Des séries enregistrées après 18:20
 * n'apparaissent pas ici : elles sont bien enregistrées. »
 *
 * Rien d'autre qu'un compte, des identifiants de séance et des instants, dans
 * le `localStorage`. La note s'efface avec les copies (`oublierLesCopies`), et
 * celle d'un autre compte quand l'appareil change de titulaire. Un stockage
 * qui refuse n'empêche rien : la note manque, sans erreur.
 */

/** La clé du `localStorage` où vit la note. */
export const CLEF_DES_ECRITURES_ACCEPTEES = 'gym-tracker:ecritures-acceptees'

/** Les séances que la note retient au plus : les plus récemment écrites. */
export const SEANCES_NOTEES_AU_PLUS = 8

/**
 * @typedef {{ compte: string, seances: Record<string, number> }} NoteDesEcritures
 *   `seances` : l'instant de la dernière écriture acceptée de chaque séance.
 */

/**
 * Un identifiant de compte ou de séance en texte, ou `null`.
 *
 * @param {unknown} identifiant
 * @returns {string | null}
 */
const enTexte = (identifiant) =>
    (Number.isInteger(identifiant) && identifiant > 0) || (typeof identifiant === 'string' && identifiant !== '')
        ? String(identifiant)
        : null

/**
 * La note telle que le stockage la garde, ou `null` quand elle manque ou ne se lit pas.
 *
 * @param {Storage | undefined} stockage
 * @returns {NoteDesEcritures | null}
 */
const lire = (stockage) => {
    try {
        const note = JSON.parse(stockage?.getItem(CLEF_DES_ECRITURES_ACCEPTEES) ?? 'null')

        return note !== null &&
            typeof note === 'object' &&
            typeof note.compte === 'string' &&
            note.seances !== null &&
            typeof note.seances === 'object'
            ? note
            : null
    } catch {
        return null
    }
}

/**
 * Note qu'une écriture de la séance vient d'être acceptée. La note d'un autre
 * compte est remplacée.
 *
 * @param {{ compte: unknown, seance: unknown, instant: number }} ecriture
 * @param {Storage | undefined} [stockage]
 * @returns {boolean} vrai quand la note est écrite
 */
export const noterUneEcritureAcceptee = ({ compte, seance, instant }, stockage = globalThis.localStorage) => {
    const duCompte = enTexte(compte)
    const deLaSeance = enTexte(seance)

    if (duCompte === null || deLaSeance === null || !Number.isFinite(instant)) {
        return false
    }

    const precedente = lire(stockage)
    const seances = precedente?.compte === duCompte ? { ...precedente.seances } : {}

    seances[deLaSeance] = Math.max(instant, Number(seances[deLaSeance]) || 0)

    const gardees = Object.entries(seances)
        .filter(([, quand]) => Number.isFinite(quand))
        .sort(([, premier], [, second]) => second - premier)
        .slice(0, SEANCES_NOTEES_AU_PLUS)

    try {
        stockage.setItem(
            CLEF_DES_ECRITURES_ACCEPTEES,
            JSON.stringify({ compte: duCompte, seances: Object.fromEntries(gardees) }),
        )

        return true
    } catch {
        return false
    }
}

/**
 * L'instant de la dernière écriture acceptée de cette séance pour ce compte,
 * ou `null`.
 *
 * @param {{ compte: unknown, seance: unknown }} demande
 * @param {Storage | undefined} [stockage]
 * @returns {number | null}
 */
export const derniereEcritureAcceptee = ({ compte, seance }, stockage = globalThis.localStorage) => {
    const note = lire(stockage)
    const instant = note !== null && note.compte === enTexte(compte) ? note.seances[enTexte(seance)] : undefined

    return Number.isFinite(instant) ? instant : null
}

/**
 * Efface la note, sauf si elle est celle du compte qu'on garde : les purges
 * des copies l'emportent entière, et un changement de titulaire celle de
 * l'autre compte.
 *
 * @param {Storage | undefined} [stockage]
 * @param {{ saufDuCompte?: unknown }} [options]
 */
export const oublierLesEcrituresAcceptees = (stockage = globalThis.localStorage, { saufDuCompte } = {}) => {
    const garde = enTexte(saufDuCompte)

    if (garde !== null && lire(stockage)?.compte === garde) {
        return
    }

    try {
        stockage?.removeItem(CLEF_DES_ECRITURES_ACCEPTEES)
    } catch {
        // Un stockage qui refuse : rien d'autre à faire.
    }
}
