import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

const request = vi.fn()

vi.mock('axios', () => ({ default: (...args) => request(...args) }))

const setOnline = (value) => {
    Object.defineProperty(navigator, 'onLine', { writable: true, configurable: true, value })
}

/**
 * The module exports a singleton built in its constructor from localStorage, so
 * each case needs a fresh import after the storage is arranged.
 */
const freshService = async () => {
    vi.resetModules()

    return (await import('@/Utils/SyncService')).default
}

const aQueuedPatch = (url = '/api/v1/sets/1') => ({
    method: 'patch',
    url,
    data: { weight: 100 },
    id: 'queued-1',
    timestamp: '2026-07-29T10:00:00.000Z',
})

beforeEach(() => {
    localStorage.clear()
    request.mockReset()
    setOnline(true)
    delete window.axios
})

afterEach(() => {
    localStorage.clear()
})

describe('SyncService.processQueue', () => {
    it('clears the queue when everything goes through', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch()]))
        request.mockResolvedValue({ data: {} })

        const service = await freshService()
        await service.processQueue()

        expect(request).toHaveBeenCalledTimes(1)
        expect(service.queue).toEqual([])
        expect(localStorage.getItem('offline_sync_failed')).toBeNull()
    })

    it('keeps a mutation queued when the connection drops again', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch()]))
        request.mockRejectedValue({ code: 'ERR_NETWORK' })

        const service = await freshService()
        await service.processQueue()

        expect(service.queue).toHaveLength(1)
        expect(localStorage.getItem('offline_sync_failed')).toBeNull()
    })

    /**
     * The defect. A queued mutation is an edit the user already made, offline.
     * Any non-network failure fell off the end of the catch and was gone, with
     * a console.error as the only record.
     */
    it.each([422, 403, 500])('does not lose a mutation the server refused with %i', async (status) => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch()]))
        request.mockRejectedValue({ response: { status } })

        const service = await freshService()
        await service.processQueue()

        // Read back out of storage rather than through the new accessor: on the
        // old code the mutation is nowhere at all, so this fails on the missing
        // edit rather than on a missing method.
        const kept = JSON.parse(localStorage.getItem('offline_sync_failed') ?? 'null')

        expect(kept).toHaveLength(1)
        expect(kept[0].url).toBe('/api/v1/sets/1')
        expect(kept[0].data).toEqual({ weight: 100 })
        expect(kept[0].status).toBe(status)
        expect(service.queue).toEqual([])
    })

    /**
     * The payload rides along with the announcement. Without it a listener can
     * only ever say "an item of the session could not be saved" — a URL carries
     * no name, and on a refused CREATE there is not even an id in it.
     */
    it('announces the refusal, with what was refused', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch('/api/v1/sets/7')]))
        request.mockRejectedValue({ response: { status: 422 } })

        const listener = vi.fn()
        window.addEventListener('sync:failed', listener)

        const service = await freshService()
        await service.processQueue()

        window.removeEventListener('sync:failed', listener)

        expect(listener).toHaveBeenCalledTimes(1)
        expect(listener.mock.calls[0][0].detail).toEqual({
            url: '/api/v1/sets/7',
            status: 422,
            data: { weight: 100 },
        })
    })

    it('survives a reload with the refused mutations intact', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch()]))
        request.mockRejectedValue({ response: { status: 422 } })

        const service = await freshService()
        await service.processQueue()

        const reloaded = await freshService()

        expect(reloaded.failedRequests()).toHaveLength(1)

        reloaded.clearFailedRequests()

        expect(reloaded.failedRequests()).toEqual([])
        expect(localStorage.getItem('offline_sync_failed')).toBeNull()
    })

    it('does not confuse one refusal with the whole queue', async () => {
        localStorage.setItem(
            'offline_sync_queue',
            JSON.stringify([aQueuedPatch('/api/v1/sets/1'), aQueuedPatch('/api/v1/sets/2')]),
        )
        request.mockRejectedValueOnce({ response: { status: 422 } }).mockResolvedValueOnce({ data: {} })

        const service = await freshService()
        await service.processQueue()

        expect(JSON.parse(localStorage.getItem('offline_sync_failed')).map((item) => item.url)).toEqual([
            '/api/v1/sets/1',
        ])
        expect(service.queue).toEqual([])
    })
})

/**
 * The defect that cost a whole workout.
 *
 * `isOnline` was `navigator.onLine`, read once in the constructor of a
 * module-level singleton. An iOS PWA reports that false at a cold launch, and
 * nothing ever repaired it: the `online` event only fires on a transition, and
 * the browser never considered itself offline. Every mutation for the rest of
 * the page session was queued and rejected with `isOffline`, which callers read
 * as "keep the value on screen" — so the workout filled up normally and
 * reloaded empty. Reproduced on a simulator: the FAB's Inertia POST created the
 * workout, then adding an exercise and a set produced no server request at all.
 */
describe('SyncService.request when navigator.onLine lies', () => {
    it('still sends the request when the browser claims to be offline', async () => {
        setOnline(false)
        request.mockResolvedValue({ data: { data: { id: 9 } } })

        const service = await freshService()
        const response = await service.post('/api/v1/sets', { reps: 10 })

        expect(request).toHaveBeenCalledWith(
            expect.objectContaining({ method: 'post', url: '/api/v1/sets', data: { reps: 10 } }),
        )
        expect(response.data.data.id).toBe(9)
        expect(service.queue).toEqual([])
    })

    it('queues only once the attempt actually fails', async () => {
        setOnline(false)
        request.mockRejectedValue({ code: 'ERR_NETWORK', request: {} })

        const service = await freshService()

        await expect(service.post('/api/v1/sets', { reps: 10 })).rejects.toMatchObject({ isOffline: true })
        expect(request).toHaveBeenCalledTimes(1)
        expect(service.queue).toHaveLength(1)
    })

    /**
     * A server that answered is not a connection that dropped. Filing a refusal
     * as "offline" queued it forever and told the caller to keep the value.
     */
    it('surfaces a server refusal instead of calling it offline', async () => {
        setOnline(false)
        request.mockRejectedValue({ response: { status: 422 }, request: {} })

        const service = await freshService()

        await expect(service.post('/api/v1/sets', { reps: 10 })).rejects.toMatchObject({
            response: { status: 422 },
        })
        expect(service.queue).toEqual([])
    })
})

/**
 * The dangerous failure is not the request that obviously fails. It is the one
 * the server accepted and wrote, whose response never came home — a tunnel, a
 * lock screen, a suspended PWA. The client cannot tell that apart from a
 * request that never arrived, so it queues and replays it, and a second row
 * appears. Workout 8 in the owner's database shows the shape: five lines
 * stamped within two seconds of each other by a queue flush.
 *
 * The key names the attempt, so the server can recognise a second telling.
 */
describe('SyncService idempotency', () => {
    const keyOf = (call) => call[0].headers?.['Idempotency-Key']

    it('names every create it sends', async () => {
        request.mockResolvedValue({ data: {} })

        const service = await freshService()
        await service.post('/api/v1/sets', { reps: 10 })

        expect(keyOf(request.mock.calls[0])).toEqual(expect.any(String))
        expect(keyOf(request.mock.calls[0]).length).toBeGreaterThan(8)
    })

    it('gives two separate creates two separate names', async () => {
        request.mockResolvedValue({ data: {} })

        const service = await freshService()
        await service.post('/api/v1/sets', { reps: 10 })
        await service.post('/api/v1/sets', { reps: 10 })

        expect(keyOf(request.mock.calls[0])).not.toBe(keyOf(request.mock.calls[1]))
    })

    /**
     * The whole point. A replay is the same attempt told twice, not a second
     * attempt, so it has to carry the same name.
     */
    it('replays a queued create under the name it was first sent with', async () => {
        request.mockRejectedValueOnce({ code: 'ERR_NETWORK', request: {} })

        const service = await freshService()
        await expect(service.post('/api/v1/sets', { reps: 10 })).rejects.toMatchObject({ isOffline: true })

        const attempted = keyOf(request.mock.calls[0])
        expect(attempted).toEqual(expect.any(String))

        request.mockResolvedValue({ data: {} })
        await service.processQueue()

        expect(request).toHaveBeenCalledTimes(2)
        expect(keyOf(request.mock.calls[1])).toBe(attempted)
    })

    it('survives a reload with the name intact', async () => {
        request.mockRejectedValueOnce({ code: 'ERR_NETWORK', request: {} })

        const service = await freshService()
        await expect(service.post('/api/v1/sets', { reps: 10 })).rejects.toMatchObject({ isOffline: true })
        const attempted = keyOf(request.mock.calls[0])

        // A new page load reads the queue back out of localStorage.
        request.mockResolvedValue({ data: {} })
        const reloaded = await freshService()
        await reloaded.processQueue()

        expect(keyOf(request.mock.calls[1])).toBe(attempted)
    })

    /**
     * A PATCH or DELETE against an id the server issued is already idempotent;
     * naming it would be noise.
     */
    it.each([
        ['patch', (s) => s.patch('/api/v1/sets/1', { weight: 100 })],
        ['delete', (s) => s.delete('/api/v1/sets/1')],
    ])('does not name a %s', async (_method, call) => {
        request.mockResolvedValue({ data: {} })

        const service = await freshService()
        await call(service)

        expect(keyOf(request.mock.calls[0])).toBeUndefined()
    })
})

/**
 * The 429 retry used to be the one path that escaped every safeguard around it.
 * It re-sent the ORIGINAL config, dropping the idempotency key on the attempt
 * most likely to need one — a 429 says the server was busy, not that it refused,
 * so the first attempt may well have been written. And a network failure on the
 * second attempt fell straight out of the catch: neither sent, nor queued, nor
 * recorded, while classifySyncError read the bare rejection as "offline" and the
 * draft replay deleted the local draft as a duplicate of a write nobody queued.
 */
describe('SyncService 429 retry', () => {
    const keyOf = (call) => call[0].headers?.['Idempotency-Key']

    it('retries under the same name it first used', async () => {
        vi.useFakeTimers()
        request
            .mockRejectedValueOnce({ response: { status: 429, headers: { 'retry-after': '0' } }, request: {} })
            .mockResolvedValueOnce({ data: {} })

        const service = await freshService()
        const inFlight = service.post('/api/v1/sets', { reps: 10 })
        await vi.runAllTimersAsync()
        await inFlight

        expect(request).toHaveBeenCalledTimes(2)
        expect(keyOf(request.mock.calls[1])).toBe(keyOf(request.mock.calls[0]))
        expect(keyOf(request.mock.calls[1])).toEqual(expect.any(String))

        vi.useRealTimers()
    })

    it('queues the write when the retry hits the network instead of the server', async () => {
        vi.useFakeTimers()
        request
            .mockRejectedValueOnce({ response: { status: 429, headers: { 'retry-after': '0' } }, request: {} })
            .mockRejectedValueOnce({ code: 'ERR_NETWORK', request: {} })

        const service = await freshService()
        // Assert on the rejection before advancing the clock: attaching the
        // handler afterwards leaves the rejection unhandled in between.
        const settled = expect(service.post('/api/v1/sets', { reps: 10 })).rejects.toMatchObject({ isOffline: true })
        await vi.runAllTimersAsync()
        await settled

        expect(service.queue).toHaveLength(1)

        vi.useRealTimers()
    })

    it('still surfaces a refusal on the retry rather than swallowing it', async () => {
        vi.useFakeTimers()
        request
            .mockRejectedValueOnce({ response: { status: 429, headers: { 'retry-after': '0' } }, request: {} })
            .mockRejectedValueOnce({ response: { status: 422 }, request: {} })

        const service = await freshService()
        const settled = expect(service.post('/api/v1/sets', { reps: 10 })).rejects.toMatchObject({
            response: { status: 422 },
        })
        await vi.runAllTimersAsync()
        await settled

        expect(service.queue).toEqual([])

        vi.useRealTimers()
    })
})

/**
 * The queue's whole purpose is to survive the app not running. It used to copy
 * itself into memory and immediately write an EMPTY queue to localStorage,
 * sending afterwards and saving again only at the very end — so for the entire
 * duration of a drain, the durable record said nothing was pending. A reload, a
 * crash, or an iOS suspension in that window destroyed every offline edit.
 */
describe('SyncService drain durability', () => {
    const queued = (url, id) => ({
        method: 'patch',
        url,
        data: { weight: 100 },
        id,
        timestamp: '2026-08-04T10:00:00.000Z',
    })

    const stored = () => JSON.parse(localStorage.getItem('offline_sync_queue') || '[]').map((item) => item.url)

    it('keeps an unsent mutation on disk while the one before it is in flight', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([queued('/a', 'q1'), queued('/b', 'q2')]))

        let releaseSecond
        request.mockResolvedValueOnce({ data: {} }).mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    releaseSecond = resolve
                }),
        )

        const service = await freshService()
        const draining = service.processQueue()
        await new Promise((resolve) => setTimeout(resolve, 0))

        // The app dies right here. /b has not been sent, so it must still be on disk.
        expect(stored()).toEqual(['/b'])

        releaseSecond({ data: {} })
        await draining

        expect(stored()).toEqual([])
    })

    it('leaves the whole queue on disk when the network is still down', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([queued('/a', 'q1'), queued('/b', 'q2')]))
        request.mockRejectedValue({ code: 'ERR_NETWORK', request: {} })

        const service = await freshService()
        await service.processQueue()

        expect(stored()).toEqual(['/a', '/b'])
    })

    /**
     * Re-queuing a failed entry appended it behind mutations added later, so on
     * the next drain an older value could land after a newer one and overwrite
     * it. Stopping at the failure keeps the order the user made the edits in.
     */
    it('replays in the order the edits were made, even after a failure', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([queued('/first', 'q1'), queued('/second', 'q2')]))
        request.mockRejectedValueOnce({ code: 'ERR_NETWORK', request: {} }).mockResolvedValue({ data: {} })

        // The constructor drains once: /first fails on the network and stops
        // there. The explicit drain then replays both, still in order.
        const service = await freshService()
        await service.processQueue()

        expect(request.mock.calls.map((call) => call[0].url)).toEqual(['/first', '/first', '/second'])
        expect(stored()).toEqual([])
    })

    it('drops a refused mutation from the queue but keeps it in the failed bucket', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([queued('/a', 'q1'), queued('/b', 'q2')]))
        request.mockRejectedValueOnce({ response: { status: 422 }, request: {} }).mockResolvedValueOnce({ data: {} })

        const service = await freshService()
        await service.processQueue()

        expect(stored()).toEqual([])
        expect(service.failedRequests().map((item) => item.url)).toEqual(['/a'])
    })
})

/**
 * Nothing in the app has ever emptied this list — clearFailedRequests() has no
 * caller — and each entry holds a whole request config. Left unbounded it
 * eventually fills localStorage, and the write throws QuotaExceededError in the
 * middle of a drain, taking the rest of the queue down with it.
 */
describe('SyncService failed-request bucket', () => {
    it('keeps the most recent refusals and stops growing', async () => {
        const many = Array.from({ length: 60 }, (_, i) => ({
            method: 'patch',
            url: `/api/v1/sets/${i}`,
            data: { weight: i },
            id: `q${i}`,
            timestamp: '2026-08-04T10:00:00.000Z',
        }))
        localStorage.setItem('offline_sync_queue', JSON.stringify(many))
        request.mockRejectedValue({ response: { status: 422 }, request: {} })

        const service = await freshService()
        await service.processQueue()

        const kept = service.failedRequests()

        expect(kept).toHaveLength(50)
        expect(kept.at(-1).url).toBe('/api/v1/sets/59')
        expect(kept.some((item) => item.url === '/api/v1/sets/0')).toBe(false)
    })

    it('does not abandon the queue when storage refuses the write', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch('/a'), aQueuedPatch('/b')]))
        request.mockRejectedValue({ response: { status: 422 }, request: {} })

        const setItem = localStorage.setItem.bind(localStorage)
        const spy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation((key, value) => {
            if (key === 'offline_sync_failed') throw new DOMException('full', 'QuotaExceededError')

            return setItem(key, value)
        })

        const service = await freshService()
        await service.processQueue()

        spy.mockRestore()

        // Both were attempted and neither is stuck in the queue.
        expect(request).toHaveBeenCalledTimes(2)
        expect(service.queue).toEqual([])
    })
})

/**
 * Le singleton vide la file dès sa construction : `chargé()` attend ce premier
 * passage, pour que chaque test compte ses tentatives à partir de là.
 */
const chargé = async () => {
    const service = await freshService()
    await service.pending

    return service
}

/**
 * 401 ou 419 : la session ou le jeton CSRF a expiré pendant que la PWA
 * dormait. L'écriture était classée refusée et la file vidée derrière elle,
 * sans re-soumission possible : une perte définitive pour une porte qui se
 * rouvre à la prochaine connexion (#1667).
 */
describe('SyncService session expirée', () => {
    it.each([401, 419])('garde la file intacte sur %i et prévient', async (status) => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch(), aQueuedPatch('/api/v1/sets/2')]))
        request.mockRejectedValue({ response: { status } })

        const listener = vi.fn()
        window.addEventListener('sync:auth-required', listener)

        const service = await chargé()

        window.removeEventListener('sync:auth-required', listener)

        expect(request).toHaveBeenCalledTimes(1)
        expect(service.queue).toHaveLength(2)
        expect(localStorage.getItem('offline_sync_failed')).toBeNull()
        expect(listener).toHaveBeenCalledTimes(1)
        expect(listener.mock.calls[0][0].detail).toEqual({ url: '/api/v1/sets/1', status, pending: 2 })
    })

    it('repart après la reconnexion', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch()]))
        request.mockRejectedValueOnce({ response: { status: 419 } }).mockResolvedValueOnce({ data: {} })

        const service = await chargé()
        expect(service.queue).toHaveLength(1)

        await service.processQueue()

        expect(request).toHaveBeenCalledTimes(2)
        expect(service.queue).toEqual([])
        expect(localStorage.getItem('offline_sync_failed')).toBeNull()
    })

    it('classe l écriture refusée après trois portes fermées, pour ne pas bloquer la file', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch(), aQueuedPatch('/api/v1/sets/2')]))
        request.mockRejectedValue({ response: { status: 419 } })

        const service = await chargé()
        await service.processQueue()
        await service.processQueue()

        // La première a épuisé ses trois tentatives ; la seconde commence les siennes.
        expect(service.failedRequests()).toHaveLength(1)
        expect(service.failedRequests()[0].url).toBe('/api/v1/sets/1')
        expect(service.queue).toHaveLength(1)
        expect(service.queue[0].url).toBe('/api/v1/sets/2')
    })

    it('survit à un rechargement avec le compte de tentatives', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch()]))
        request.mockRejectedValue({ response: { status: 401 } })

        await chargé()

        expect(JSON.parse(localStorage.getItem('offline_sync_queue'))[0].authAttempts).toBe(1)
    })
})

/**
 * Une écriture directe partait même quand des écritures plus anciennes
 * attendaient : la file les rejouait ensuite, et une vieille valeur
 * écrasait celle que l'utilisateur venait de saisir en ligne.
 */
describe('SyncService ordre des écritures', () => {
    it('vide la file avant d envoyer une écriture directe', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch('/api/v1/sets/1')]))
        request.mockRejectedValueOnce({ code: 'ERR_NETWORK' }).mockResolvedValue({ data: {} })

        // Le premier passage échoue : la file garde son écriture ancienne.
        const service = await chargé()
        expect(service.queue).toHaveLength(1)

        await service.patch('/api/v1/sets/1', { weight: 120 })

        expect(request).toHaveBeenCalledTimes(3)
        expect(request.mock.calls[1][0].data).toEqual({ weight: 100 })
        expect(request.mock.calls[2][0].data).toEqual({ weight: 120 })
        expect(service.queue).toEqual([])
    })

    it('range la nouvelle écriture derrière la file quand celle-ci ne se vide pas', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch('/api/v1/sets/1')]))
        request.mockRejectedValue({ code: 'ERR_NETWORK' })

        const service = await chargé()

        await expect(service.patch('/api/v1/sets/1', { weight: 120 })).rejects.toMatchObject({ isOffline: true })
        expect(service.queue).toHaveLength(2)
        expect(service.queue[0].data).toEqual({ weight: 100 })
        expect(service.queue[1].data).toEqual({ weight: 120 })
        // Seule l'écriture en file a été tentée ; la nouvelle n'a pas doublé.
        expect(request.mock.calls.every((call) => call[0].data.weight === 100)).toBe(true)
    })

    it('laisse passer une lecture même quand la file attend', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch()]))
        request.mockRejectedValueOnce({ code: 'ERR_NETWORK' }).mockResolvedValue({ data: { ok: true } })

        const service = await chargé()
        const response = await service.get('/api/v1/workouts')

        expect(response.data).toEqual({ ok: true })
        expect(service.queue).toHaveLength(1)
    })
})

/**
 * Le stockage n'est pas fiable : une écriture coupée le corrompt, un quota le
 * ferme. Ni l'un ni l'autre ne doit empêcher la page de démarrer ou la file
 * de se vider.
 */
describe('SyncService stockage', () => {
    it('démarre avec une file vide quand le stockage est illisible', async () => {
        localStorage.setItem('offline_sync_queue', '{corrompu')
        localStorage.setItem('offline_sync_failed', '"pas une liste"')

        const service = await chargé()

        expect(service.queue).toEqual([])
        expect(service.failedRequests()).toEqual([])
    })

    it('continue de vider la file quand le stockage refuse d écrire, et le dit', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch(), aQueuedPatch('/api/v1/sets/2')]))
        request.mockResolvedValue({ data: {} })

        const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new DOMException('quota', 'QuotaExceededError')
        })
        const listener = vi.fn()
        window.addEventListener('sync:storage-full', listener)

        const service = await chargé()

        setItem.mockRestore()
        window.removeEventListener('sync:storage-full', listener)

        expect(request).toHaveBeenCalledTimes(2)
        expect(service.queue).toEqual([])
        expect(listener).toHaveBeenCalled()
    })
})
