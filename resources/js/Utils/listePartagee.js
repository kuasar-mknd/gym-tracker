/**
 * Une liste d'entrées que les onglets de l'appareil se partagent sous une clé
 * du `localStorage` : la file hors ligne et ses refus (`SyncService`, #2049).
 *
 * Chaque onglet gardait sa copie de la file, lue une fois à son chargement, et
 * la réécrivait entière à chaque changement : deux onglets ouverts
 * s'effaçaient leurs écritures en attente. Ici le stockage fait foi. Chaque
 * changement relit la liste, s'applique entrée par entrée, par identifiant
 * (`id`), puis écrit ; l'onglet ne garde de copie que pour la lire.
 *
 * Quand le stockage refuse l'écriture (quota atteint, stockage indisponible),
 * ce que l'onglet n'a pas pu écrire reste sa vérité, entrée par entrée : la
 * version qu'il voulait, ou le retrait. Chaque lecture la superpose au
 * stockage, à sa place, jusqu'à ce qu'une écriture passe. Relire le stockage
 * seul aurait perdu, à l'écriture suivante, l'écriture que l'onglet gardait en
 * mémoire faute de place.
 */

/** Une entrée qui peut porter un identifiant : un objet. */
const estUneEntree = (entree) => entree !== null && typeof entree === 'object' && !Array.isArray(entree)

/** Un identifiant que la liste reconnaît tel quel. */
const estUnIdentifiant = (id) => (typeof id === 'string' && id !== '') || typeof id === 'number'

/**
 * La liste gardée sous cette clé. Un stockage corrompu (une écriture coupée
 * par une suspension iOS, un quota atteint à mi-chemin) ou indisponible se lit
 * comme une liste vide : on perd au pire ce qui était déjà perdu.
 *
 * @param {() => Storage} stockage
 * @param {string} cle
 * @returns {Array<unknown>}
 */
export const lireLaListe = (stockage, cle) => {
    try {
        const valeur = JSON.parse(stockage().getItem(cle) || '[]')

        return Array.isArray(valeur) ? valeur : []
    } catch {
        return []
    }
}

/**
 * Donne à chaque entrée un identifiant à elle. Une entrée sans identifiant, ou
 * qui reprend celui d'une entrée précédente (un stockage abîmé), en reçoit un
 * dérivé de sa place : le même dans chaque onglet qui lit le même stockage, et
 * qu'aucune autre entrée ne porte.
 *
 * @param {Array<unknown>} liste
 * @returns {Array<unknown>} la même liste quand rien n'a changé
 */
export const normaliserLesIdentifiants = (liste) => {
    const pris = new Set()
    const premieres = new Set()

    liste.forEach((entree, rang) => {
        if (estUneEntree(entree) && estUnIdentifiant(entree.id) && !pris.has(entree.id)) {
            pris.add(entree.id)
            premieres.add(rang)
        }
    })

    if (premieres.size === liste.filter(estUneEntree).length) {
        return liste
    }

    return liste.map((entree, rang) => {
        if (!estUneEntree(entree) || premieres.has(rang)) {
            return entree
        }

        const base = estUnIdentifiant(entree.id) ? String(entree.id) : 'sans-identifiant'
        let suffixe = 2

        while (pris.has(`${base}~${suffixe}`)) {
            suffixe += 1
        }

        const id = `${base}~${suffixe}`

        pris.add(id)

        return { ...entree, id }
    })
}

/**
 * Le stockage, avec ce que l'onglet n'a pas pu écrire posé dessus : une
 * entrée qu'il a changée prend sa version, une entrée qu'il a retirée part, et
 * une entrée que le stockage n'a pas reprend sa place, derrière celle qui la
 * précédait quand l'onglet l'a voulue.
 *
 * @param {Array<unknown>} stockee
 * @param {Map<string|number, {entree: Object|null, apres: string|number|null}>} nonEcrites
 * @returns {Array<unknown>}
 */
export const superposer = (stockee, nonEcrites) => {
    if (nonEcrites.size === 0) {
        return stockee
    }

    const presentes = new Set()
    const liste = []

    stockee.forEach((entree) => {
        if (!estUneEntree(entree) || !nonEcrites.has(entree.id)) {
            liste.push(entree)

            if (estUneEntree(entree)) {
                presentes.add(entree.id)
            }

            return
        }

        presentes.add(entree.id)

        const voulue = nonEcrites.get(entree.id).entree

        if (voulue !== null) {
            liste.push(voulue)
        }
    })

    nonEcrites.forEach(({ entree, apres }, id) => {
        if (entree === null || presentes.has(id)) {
            return
        }

        const precedente = apres === null ? -1 : liste.findIndex((candidate) => candidate?.id === apres)

        liste.splice(apres === null ? 0 : precedente === -1 ? liste.length : precedente + 1, 0, entree)
        presentes.add(id)
    })

    return liste
}

/**
 * Ce qui sépare la liste que l'onglet voulait de celle que le stockage garde :
 * chaque entrée voulue que le stockage n'a pas, ou pas telle quelle, et chaque
 * entrée du stockage que l'onglet a retirée.
 *
 * @param {Array<unknown>} stockee
 * @param {Array<unknown>} voulue
 * @returns {Map<string|number, {entree: Object|null, apres: string|number|null}>}
 */
export const ecartEntre = (stockee, voulue) => {
    const telleQuelle = new Map(stockee.filter(estUneEntree).map((entree) => [entree.id, JSON.stringify(entree)]))
    const voulues = new Set()
    const ecart = new Map()
    let precedente = null

    voulue.forEach((entree) => {
        if (!estUneEntree(entree)) {
            return
        }

        voulues.add(entree.id)

        if (telleQuelle.get(entree.id) !== JSON.stringify(entree)) {
            ecart.set(entree.id, { entree, apres: precedente })
        }

        precedente = entree.id
    })

    telleQuelle.forEach((_version, id) => {
        if (!voulues.has(id)) {
            ecart.set(id, { entree: null, apres: null })
        }
    })

    return ecart
}

/**
 * @param {string} cle la clé du `localStorage`
 * @param {{stockage?: () => Storage, retirerSiVide?: boolean}} options
 *   `retirerSiVide` retire la clé plutôt que d'y écrire une liste vide
 * @returns {{
 *   lire: () => Array<unknown>,
 *   changer: (transformation: (liste: Array<unknown>) => Array<unknown>, options?: {retirerSiVide?: boolean}) => {liste: Array<unknown>, ecrite: boolean},
 *   oublierCeQuiNAPasEteEcrit: (predicat: (entree: Object) => boolean) => void,
 * }}
 */
export const creerUneListePartagee = (
    cle,
    { stockage = () => globalThis.localStorage, retirerSiVide: retirerParDefaut = false } = {},
) => {
    /**
     * Ce que l'onglet n'a pas pu écrire, par identifiant d'entrée : sa version
     * (ou null, retirée) et l'entrée qui la précédait.
     *
     * @type {Map<string|number, {entree: Object|null, apres: string|number|null}>}
     */
    let nonEcrites = new Map()

    const lireLeStockage = () => normaliserLesIdentifiants(lireLaListe(stockage, cle))

    return {
        /** La liste telle que l'onglet la voit : le stockage, et ce qu'il n'a pas pu y écrire. */
        lire: () => superposer(lireLeStockage(), nonEcrites),

        /**
         * Relit la liste, la change, puis l'écrit. Une transformation qui ne
         * change rien rend la liste reçue : rien ne s'écrit alors, et les
         * autres onglets n'en entendent rien.
         *
         * @returns {{liste: Array<unknown>, ecrite: boolean}} la liste voulue, et
         *   si le stockage l'a prise
         */
        changer: (transformation, { retirerSiVide = retirerParDefaut } = {}) => {
            const stockee = lireLeStockage()
            const avant = superposer(stockee, nonEcrites)
            const apres = transformation(avant)

            if (apres === avant && nonEcrites.size === 0) {
                return { liste: apres, ecrite: true }
            }

            try {
                if (retirerSiVide && apres.length === 0) {
                    stockage().removeItem(cle)
                } else {
                    stockage().setItem(cle, JSON.stringify(apres))
                }

                nonEcrites = new Map()

                return { liste: apres, ecrite: true }
            } catch {
                nonEcrites = ecartEntre(stockee, apres)

                return { liste: apres, ecrite: false }
            }
        },

        /**
         * Oublie, de ce que l'onglet n'a pas pu écrire, les entrées que le
         * prédicat désigne : celles d'un compte qui quitte l'appareil (#2050).
         */
        oublierCeQuiNAPasEteEcrit: (predicat) => {
            nonEcrites = new Map([...nonEcrites].filter(([, { entree }]) => entree === null || !predicat(entree)))
        },
    }
}
