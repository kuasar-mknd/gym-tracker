/**
 * La note du dernier préchauffage des copies hors ligne (#2048, partie D,
 * décision 7 du propriétaire) : l'empreinte de la clé de session sous
 * laquelle il est parti, et l'instant, dans le `localStorage`, pour tous les
 * onglets. Le préchauffage (`prechauffage.js`) la lit et l'écrit : au plus un
 * par demi-heure sous une même clé.
 *
 * Elle vit à part pour que le magasin (`copiesHorsLigne.js`) l'efface avec
 * les copies, sans dépendre du préchauffage : une copie oubliée alors que la
 * clé reste ouverte (une suppression du compte refusée, un autre contrat
 * vidé à l'ouverture) doit pouvoir se refaire tout de suite, et non une
 * demi-heure plus tard.
 */

/** La clé du `localStorage` qui dit quand le dernier préchauffage est parti, et sous quelle clé de session. */
export const CLEF_DU_DERNIER_PRECHAUFFAGE = 'gym-tracker:dernier-prechauffage'

/**
 * L'instant du dernier préchauffage sous cette clé de session, ou `null`.
 *
 * @param {Storage | null | undefined} stockage
 * @param {string} empreinte
 * @returns {number | null}
 */
export const lireLeDernierPrechauffage = (stockage, empreinte) => {
    try {
        const note = JSON.parse(stockage?.getItem(CLEF_DU_DERNIER_PRECHAUFFAGE) ?? 'null')

        return note?.empreinte === empreinte && Number.isFinite(note.a) ? note.a : null
    } catch {
        return null
    }
}

/**
 * Note qu'un préchauffage part sous cette clé de session, à cet instant.
 *
 * @param {Storage | null | undefined} stockage
 * @param {string} empreinte
 * @param {number} instant
 */
export const noterLeDernierPrechauffage = (stockage, empreinte, instant) => {
    try {
        stockage?.setItem(CLEF_DU_DERNIER_PRECHAUFFAGE, JSON.stringify({ empreinte, a: instant }))
    } catch {
        // Un stockage refusé : le préchauffage repartira plus tôt, sans plus de huit demandes à la fois.
    }
}

/**
 * Efface la note : le prochain préchauffage part sans attendre la demi-heure.
 *
 * @param {Storage | null | undefined} stockage
 */
export const oublierLeDernierPrechauffage = (stockage) => {
    try {
        stockage?.removeItem(CLEF_DU_DERNIER_PRECHAUFFAGE)
    } catch {
        // Un stockage refusé n'a rien gardé non plus.
    }
}
