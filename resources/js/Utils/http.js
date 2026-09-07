/**
 * Les requêtes de l'application, sans axios.
 *
 * Inertia 3 a retiré axios ; il ne restait ici que pour quatre fichiers, et il
 * partait dans le morceau principal chargé par toutes les pages (#1815). Ce
 * module rend la même forme d'appel et la même forme d'erreur, parce que la
 * file hors-ligne garde ses requêtes en attente dans `localStorage` : une
 * requête écrite par la version précédente doit encore partir après la mise à
 * jour.
 *
 * La forme d'erreur est celle que `classifySyncError` lit déjà : `response`
 * quand le serveur a répondu, `code: 'ERR_NETWORK'` et `request` quand il n'a
 * pas répondu du tout.
 */

/** Le jeton CSRF posé par Laravel dans l'en-tête du document. */
const jetonCsrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? null

export class ErreurHttp extends Error {
    constructor(statut, corps, url) {
        super(`La requête ${url} a répondu ${statut}.`)
        this.name = 'ErreurHttp'
        this.response = { status: statut, data: corps }
    }
}

export class ErreurReseau extends Error {
    constructor(url, cause) {
        super(`La requête ${url} n'a pas abouti.`)
        this.name = 'ErreurReseau'
        this.code = 'ERR_NETWORK'
        // `classifySyncError` lit `request` pour dire « le serveur n'a jamais répondu ».
        this.request = { url }
        this.cause = cause
    }
}

const lireLeCorps = async (reponse) => {
    if (reponse.status === 204) {
        return null
    }

    const type = reponse.headers.get('content-type') ?? ''

    if (type.includes('application/json')) {
        return reponse.json().catch(() => null)
    }

    return reponse.text().catch(() => null)
}

/**
 * @param {{method?: string, url: string, data?: unknown, headers?: Record<string, string>, signal?: AbortSignal, timeout?: number}} config
 * @returns {Promise<{data: unknown, status: number}>}
 */
export const http = async (config) => {
    const { method = 'get', url, data, headers = {}, signal, timeout } = config
    const jeton = jetonCsrf()
    const envoieUnCorps = data !== undefined && data !== null

    /*
     * `fetch` n'a pas de délai maximum : une requête partie vers un serveur
     * injoignable attend indéfiniment, là où axios rendait la main. Deux appels
     * en dépendent pour ne pas bloquer l'abonnement aux notifications.
     */
    const arret = new AbortController()
    const echeance = timeout ? setTimeout(() => arret.abort(), timeout) : null

    signal?.addEventListener('abort', () => arret.abort(), { once: true })

    let reponse

    try {
        reponse = await fetch(url, {
            method: method.toUpperCase(),
            credentials: 'same-origin',
            signal: arret.signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(envoieUnCorps ? { 'Content-Type': 'application/json' } : {}),
                ...(jeton === null ? {} : { 'X-CSRF-TOKEN': jeton }),
                ...headers,
            },
            body: envoieUnCorps ? JSON.stringify(data) : undefined,
        })
    } catch (erreur) {
        throw new ErreurReseau(url, erreur)
    } finally {
        if (echeance !== null) {
            clearTimeout(echeance)
        }
    }

    const corps = await lireLeCorps(reponse)

    if (!reponse.ok) {
        throw new ErreurHttp(reponse.status, corps, url)
    }

    return { data: corps, status: reponse.status }
}

http.get = (url, config = {}) => http({ ...config, method: 'get', url })
http.post = (url, data, config = {}) => http({ ...config, method: 'post', url, data })
http.patch = (url, data, config = {}) => http({ ...config, method: 'patch', url, data })
http.put = (url, data, config = {}) => http({ ...config, method: 'put', url, data })
http.delete = (url, config = {}) => http({ ...config, method: 'delete', url })

export default http
