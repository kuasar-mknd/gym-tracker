/**
 * Les caches du navigateur (`CacheStorage`), en mémoire, pour les tests du
 * worker (#2048, partie E).
 *
 * jsdom n'a pas `caches`, et la décision 10 du propriétaire refuse une
 * dépendance de plus. Cette fabrique ne couvre que ce que le worker et Workbox
 * emploient, et tient les règles dont la construction précédente dépend : une
 * entrée se range sous son adresse complète, paramètres compris (la révision
 * de Workbox est un paramètre) ; `keys()` rend les entrées dans l'ordre où
 * elles ont été rangées ; `match()` rend une réponse neuve à chaque lecture,
 * dont le corps se lit ; `caches.match(…, { cacheName })` ne crée pas le cache
 * qu'il nomme, quand `caches.open()` le crée.
 */

/**
 * L'adresse complète d'une requête ou d'une adresse, comme le cache la range.
 *
 * @param {Request | string} cle
 * @param {string} base
 */
const adresseDe = (cle, base) => new URL(typeof cle === 'string' ? cle : cle.url, base).href

/**
 * Sans `ignoreSearch`, l'adresse entière ; avec, l'adresse sans ses paramètres.
 *
 * @param {string} adresse
 * @param {{ ignoreSearch?: boolean }} [options]
 */
const comparable = (adresse, options = {}) => {
    if (options.ignoreSearch !== true) {
        return adresse
    }

    const url = new URL(adresse)
    url.search = ''

    return url.href
}

/**
 * Un cache : des réponses rangées par adresse.
 *
 * @param {string} base
 */
const creerUnCache = (base) => {
    /** @type {Map<string, { corps: ArrayBuffer, init: ResponseInit }>} */
    const entrees = new Map()

    const trouver = (cle, options) => {
        const cherchee = comparable(adresseDe(cle, base), options)

        return [...entrees.keys()].find((adresse) => comparable(adresse, options) === cherchee)
    }

    return {
        entrees,
        async match(cle, options) {
            const adresse = trouver(cle, options)

            if (adresse === undefined) {
                return undefined
            }

            const { corps, init } = entrees.get(adresse)

            return new Response(corps.slice(0), init)
        },
        async put(cle, reponse) {
            const adresse = adresseDe(cle, base)
            const corps = await reponse.arrayBuffer()

            entrees.delete(adresse)
            entrees.set(adresse, {
                corps,
                init: { status: reponse.status, statusText: reponse.statusText, headers: [...reponse.headers] },
            })
        },
        async delete(cle, options) {
            const adresse = trouver(cle, options)

            return adresse !== undefined && entrees.delete(adresse)
        },
        async keys() {
            return [...entrees.keys()].map((adresse) => new Request(adresse))
        },
    }
}

/**
 * Des caches d'un appareil, sous l'adresse `base` d'où le worker les ouvre.
 *
 * @param {string} [base]
 */
export const creerDesCachesEnMemoire = (base = globalThis.location?.href ?? 'http://localhost/') => {
    /** @type {Map<string, ReturnType<typeof creerUnCache>>} */
    const caches = new Map()

    return {
        async open(nom) {
            if (!caches.has(nom)) {
                caches.set(nom, creerUnCache(base))
            }

            return caches.get(nom)
        },
        async has(nom) {
            return caches.has(nom)
        },
        async delete(nom) {
            return caches.delete(nom)
        },
        async keys() {
            return [...caches.keys()]
        },
        async match(cle, options = {}) {
            const candidats =
                options.cacheName === undefined ? [...caches.values()] : [caches.get(options.cacheName)].filter(Boolean)

            for (const cache of candidats) {
                const reponse = await cache.match(cle, options)

                if (reponse !== undefined) {
                    return reponse
                }
            }

            return undefined
        },
        /**
         * Les adresses d'un cache, dans l'ordre où elles ont été rangées, ou
         * `null` sans ce cache : ce que le test lit, sans passer par `open`.
         *
         * @param {string} nom
         */
        adresses(nom) {
            return caches.has(nom) ? [...caches.get(nom).entrees.keys()] : null
        },
    }
}
