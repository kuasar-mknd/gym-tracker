/**
 * L'ordonnancement des écritures concurrentes vers une même ressource.
 *
 * Deux protections distinctes, extraites de `Pages/Workouts/Show.vue` où elles
 * vivaient sans aucun test — alors qu'elles sont précisément ce qui empêche la
 * classe de bugs la plus difficile à reproduire du client : deux requêtes en vol
 * sur la même ligne, dont la réponse la plus ancienne arrive en dernier et
 * écrase la plus récente.
 *
 * On ne détecte pas ces bugs en observant : le mauvais entrelacement est rare
 * par construction. On les détecte en le FORÇANT, ce que les tests de ce module
 * font en faisant atterrir les réponses dans l'ordre inverse de leur départ.
 */

/**
 * Un compteur de séquence par clé, pour ignorer une réponse dépassée.
 *
 * Le motif : on prend un numéro avant d'envoyer, et on ne traite la réponse que
 * si ce numéro est toujours le dernier attribué. Une réponse plus ancienne qui
 * arrive après une plus récente n'est donc jamais appliquée.
 *
 * @returns {{ next: (key: string) => number, isLatest: (key: string, seq: number) => boolean }}
 */
export function createWriteSequencer() {
    /** @type {Record<string, number>} */
    const sequences = {}

    return {
        next: (key) => (sequences[key] = (sequences[key] ?? 0) + 1),
        isLatest: (key, seq) => sequences[key] === seq,
    }
}

/**
 * Une file par clé, pour que deux écritures ne se chevauchent pas.
 *
 * Le séquenceur ci-dessus règle l'application des réponses ; celui-ci règle le
 * départ des requêtes. Les deux sont nécessaires : sans file, deux PATCH
 * atteignent le serveur dans un ordre que rien ne garantit, et c'est le dernier
 * ARRIVÉ qui décide de ce que la base retient — pas le dernier voulu.
 *
 * Un échec ne casse pas la file : le maillon suivant part quand même, sinon une
 * requête refusée gèlerait la ressource pour le reste de la session.
 *
 * @returns {{ queue: <T>(key: string, run: () => Promise<T>) => Promise<T>, forget: (key: string) => void }}
 */
export function createWriteQueue() {
    /** @type {Map<string, Promise<unknown>>} */
    const chains = new Map()

    return {
        queue: (key, run) => {
            const inOrder = (chains.get(key) ?? Promise.resolve()).catch(() => {}).then(run)

            chains.set(key, inOrder)

            return inOrder
        },

        /**
         * Oublie la file d'une clé disparue.
         *
         * Rien ne doit s'empiler derrière une ligne qui n'existe plus, et sans
         * cet oubli les entrées survivraient à toutes les séries que la page a
         * affichées.
         */
        forget: (key) => {
            chains.delete(key)
        },
    }
}
