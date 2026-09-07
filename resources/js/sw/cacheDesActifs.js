/**
 * Le cache des morceaux que le precache ne prend plus.
 *
 * Les noms portent leur hachage : une entrée n'est jamais périmée, seulement
 * inutile. D'où la coupe à cent entrées, la plus ancienne d'abord — l'ordre de
 * `keys()` est celui des insertions.
 */
export const NOM_DU_CACHE = 'actifs-a-la-demande'
export const ENTREES_MAX = 100

/** Une requête que ce cache doit servir : un actif construit, lu, de chez nous. */
export const estUnActif = (requete, origine) => {
    if (requete.method !== 'GET') {
        return false
    }

    const url = new URL(requete.url)

    return url.origin === origine && url.pathname.startsWith('/build/assets/')
}

const garder = async (cache, requete, reponse) => {
    await cache.put(requete, reponse)

    const entrees = await cache.keys()

    for (const vieille of entrees.slice(0, Math.max(0, entrees.length - ENTREES_MAX))) {
        await cache.delete(vieille)
    }
}

/**
 * Le cache d'abord : un actif haché ne change pas sous son URL.
 *
 * @param {{request: Request, waitUntil: (p: Promise<unknown>) => void}} event
 * @param {CacheStorage} magasin
 * @param {(requete: Request) => Promise<Response>} allerChercher
 */
export const servirDepuisLeCache = async (event, magasin, allerChercher) => {
    const cache = await magasin.open(NOM_DU_CACHE)
    const connu = await cache.match(event.request)

    if (connu) {
        return connu
    }

    const reponse = await allerChercher(event.request)

    if (reponse.ok) {
        event.waitUntil(garder(cache, event.request, reponse.clone()))
    }

    return reponse
}
