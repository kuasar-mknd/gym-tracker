import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { http, ErreurHttp, ErreurReseau } from '@/Utils/http'

const reponse = (corps, { status = 200, type = 'application/json' } = {}) => ({
    ok: status >= 200 && status < 300,
    status,
    headers: { get: () => type },
    json: () => Promise.resolve(corps),
    text: () => Promise.resolve(String(corps)),
})

beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="jeton-de-test">'
    globalThis.fetch = vi.fn()
})

afterEach(() => {
    document.head.innerHTML = ''
    vi.restoreAllMocks()
})

const dernierAppel = () => globalThis.fetch.mock.calls.at(-1)

describe('http', () => {
    it('rend le corps et le statut', async () => {
        globalThis.fetch.mockResolvedValue(reponse({ data: { id: 3 } }))

        await expect(http.get('/api/v1/sets')).resolves.toEqual({ data: { data: { id: 3 } }, status: 200 })
    })

    it('porte le jeton CSRF et se dit appelé en XHR', async () => {
        globalThis.fetch.mockResolvedValue(reponse(null, { status: 204 }))

        await http.post('/push-subscriptions', { endpoint: 'https://exemple.test' })

        const [url, options] = dernierAppel()

        expect(url).toBe('/push-subscriptions')
        expect(options.method).toBe('POST')
        expect(options.credentials).toBe('same-origin')
        expect(options.headers['X-CSRF-TOKEN']).toBe('jeton-de-test')
        expect(options.headers['X-Requested-With']).toBe('XMLHttpRequest')
        expect(JSON.parse(options.body)).toEqual({ endpoint: 'https://exemple.test' })
    })

    it('n’envoie pas de corps quand il n’y en a pas', async () => {
        globalThis.fetch.mockResolvedValue(reponse({}))

        await http.delete('/api/v1/sets/4')

        expect(dernierAppel()[1].body).toBeUndefined()
        expect(dernierAppel()[1].headers['Content-Type']).toBeUndefined()
    })

    /**
     * `classifySyncError` lit `response.status` pour trancher entre une écriture
     * refusée pour toujours et une panne passagère : la forme de l'erreur fait
     * partie du contrat, pas seulement son message.
     */
    it('lève une erreur qui porte le statut du serveur', async () => {
        globalThis.fetch.mockResolvedValue(reponse({ message: 'refusé' }, { status: 422 }))

        await expect(http.post('/api/v1/sets', {})).rejects.toMatchObject({
            response: { status: 422, data: { message: 'refusé' } },
        })
        await expect(http.post('/api/v1/sets', {})).rejects.toBeInstanceOf(ErreurHttp)
    })

    it('distingue le serveur qui n’a jamais répondu', async () => {
        globalThis.fetch.mockRejectedValue(new TypeError('Failed to fetch'))

        const erreur = await http.get('/api/v1/sets').catch((e) => e)

        expect(erreur).toBeInstanceOf(ErreurReseau)
        expect(erreur.code).toBe('ERR_NETWORK')
        expect(erreur.request).toBeTruthy()
        expect(erreur.response).toBeUndefined()
    })

    it('rend null sur un 204, sans essayer de lire du JSON', async () => {
        globalThis.fetch.mockResolvedValue(reponse(undefined, { status: 204, type: '' }))

        await expect(http.delete('/api/v1/sets/4')).resolves.toEqual({ data: null, status: 204 })
    })

    it('accepte une réponse qui n’est pas du JSON', async () => {
        globalThis.fetch.mockResolvedValue(reponse('bonjour', { type: 'text/plain' }))

        await expect(http.get('/quelque-chose')).resolves.toEqual({ data: 'bonjour', status: 200 })
    })

    it('marche sans jeton CSRF dans la page', async () => {
        document.head.innerHTML = ''
        globalThis.fetch.mockResolvedValue(reponse({}))

        await http.get('/api/v1/sets')

        expect(dernierAppel()[1].headers['X-CSRF-TOKEN']).toBeUndefined()
    })
})
