import { CACHE_DE_LA_CONSTRUCTION_PRECEDENTE, estUnActif } from './cacheDesActifs.js'

/**
 * La construction précédente, gardée le temps d'une mise à jour (#2048,
 * partie E, décision 9 du propriétaire du 2026-10-12).
 *
 * Un déploiement pendant une séance : le nouveau worker s'installe, s'active
 * aussitôt (`skipWaiting`, `clientsClaim`) et prend la page ouverte, qui tourne
 * encore sur l'ancienne construction. À l'activation, Workbox efface du
 * precache ce que le nouveau manifeste n'a plus, et un fichier précaché
 * n'entre jamais dans `actifs-a-la-demande`, la route du precache répondant
 * avant celle des actifs (constaté dans #2048 : aucun `main-`, `Dashboard-` ni
 * `Show-*.js` parmi ses 52 entrées). Le morceau suivant que la
 * page importe, celui d'une page gardée ouverte depuis sa copie sans réseau,
 * manquait.
 *
 * Le worker garde donc, le temps d'une construction, les actifs du precache
 * que son manifeste n'a plus. Pas à l'activation : l'écouteur de Workbox y
 * efface le precache pendant que le nôtre le lirait, les deux promesses
 * courant ensemble. À l'installation, que Workbox ne fait qu'ajouter au
 * precache, le worker met ces actifs de côté, dans un cache qu'aucun autre
 * code ne touche ; à l'activation, il vide `construction-precedente` (la
 * construction d'avant, qu'une page ne demande plus après deux déploiements)
 * et y verse ce qu'il a mis de côté. La route des actifs le lit
 * (`servirDepuisLeCache`). Une installation que Workbox fait échouer laisse la
 * construction précédente telle quelle : la suivante jette ce qu'elle avait
 * mis de côté.
 *
 * Ce n'est qu'un confort, jamais une condition : ni la mise de côté ni la
 * bascule ne rejettent. Une promesse d'installation rejetée rend le nouveau
 * worker redondant, et un seul `put` refusé (le stockage à court de place,
 * sur iOS surtout) laissait la page sur l'ancien worker et l'ancien precache,
 * chaque déploiement rejouant la même copie (seconde relecture de la partie
 * E, 2026-10-18). Ce qui a pu être mis de côté avant le refus passe à
 * l'activation ; une bascule refusée jette ce qui restait de côté.
 *
 * Aucun de ces noms ne contient `-precache-` : `cleanupOutdatedCaches` efface
 * les caches qui le portent.
 */

/** Ce que l'installation met de côté pour l'activation. */
export const CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE = 'construction-precedente-mise-de-cote'

/** Le paramètre que Workbox ajoute à la clé d'une entrée qui porte une révision. */
const PARAMETRE_DE_REVISION = '__WB_REVISION__'

/**
 * L'adresse d'une entrée du precache, sans sa révision : celle que la page
 * demande.
 *
 * @param {string} cle
 */
const adresseSansRevision = (cle) => {
    const adresse = new URL(cle)

    adresse.searchParams.delete(PARAMETRE_DE_REVISION)

    return adresse.href
}

/**
 * À l'installation : met de côté les actifs du precache que le nouveau
 * manifeste n'a plus. Ni la page « hors ligne » ni les icônes, qui ne sont pas
 * des actifs construits et restent au precache sous leur adresse.
 *
 * @param {{
 *   magasin: CacheStorage,
 *   nomDuPrecache: string,
 *   manifeste: ReadonlyArray<{ url: string }>,
 *   origine: string,
 * }} moyens
 */
export const mettreDeCoteLaConstructionPrecedente = async ({ magasin, nomDuPrecache, manifeste, origine }) => {
    try {
        await magasin.delete(CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE)

        if (!(await magasin.has(nomDuPrecache))) {
            return
        }

        const precache = await magasin.open(nomDuPrecache)
        const nouvelles = new Set(manifeste.map((entree) => new URL(entree.url, origine).href))
        const miseDeCote = await magasin.open(CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE)

        for (const cle of await precache.keys()) {
            const adresse = adresseSansRevision(cle.url)

            if (nouvelles.has(adresse) || !estUnActif({ method: 'GET', url: adresse }, origine)) {
                continue
            }

            const reponse = await precache.match(cle)

            if (reponse !== undefined) {
                await miseDeCote.put(adresse, reponse)
            }
        }
    } catch {
        // Un confort : l'installation continue, avec ce qui a pu être mis de côté.
    }
}

/**
 * À l'activation : vide la construction précédente, et y verse ce que
 * l'installation a mis de côté.
 *
 * @param {CacheStorage} magasin
 */
export const basculerLaConstructionPrecedente = async (magasin) => {
    try {
        await magasin.delete(CACHE_DE_LA_CONSTRUCTION_PRECEDENTE)

        if (!(await magasin.has(CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE))) {
            return
        }

        const miseDeCote = await magasin.open(CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE)
        const precedente = await magasin.open(CACHE_DE_LA_CONSTRUCTION_PRECEDENTE)

        for (const cle of await miseDeCote.keys()) {
            const reponse = await miseDeCote.match(cle)

            if (reponse !== undefined) {
                await precedente.put(cle, reponse)
            }
        }
    } catch {
        // Un confort : l'activation continue, avec ce qui a pu passer.
    }

    // Ce qui reste de côté, aucune route ne le lit : il ne garderait que de la place.
    await magasin.delete(CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE).catch(() => false)
}
