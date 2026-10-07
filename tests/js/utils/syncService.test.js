import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import {
    COMPTE,
    chargerSyncService,
    naviguer,
    poserLaPage,
    restaurerDepuisLHistorique,
    retirerLesEcouteursDuService,
} from './fileHorsLigne'

const request = vi.fn()

vi.mock('@/Utils/http', () => ({ http: (...args) => request(...args) }))

const setOnline = (value) => {
    Object.defineProperty(navigator, 'onLine', { writable: true, configurable: true, value })
}

/**
 * The module exports a singleton built in its constructor from localStorage, so
 * each case needs a fresh import after the storage is arranged.
 */
const freshService = (options) => chargerSyncService(options)

const aQueuedPatch = (url = '/api/v1/sets/1') => ({
    method: 'patch',
    url,
    data: { weight: 100 },
    id: 'queued-1',
    timestamp: '2026-07-29T10:00:00.000Z',
    compte: COMPTE,
})

beforeEach(() => {
    localStorage.clear()
    request.mockReset()
    setOnline(true)
})

afterEach(() => {
    localStorage.clear()
    poserLaPage(null)
    retirerLesEcouteursDuService()
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
    it.each([422, 403])('does not lose a mutation the server refused with %i', async (status) => {
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
            queueId: 'queued-1',
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

    afterEach(() => {
        vi.useRealTimers()
    })

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

    /**
     * L'écran attend la réponse d'une écriture directe. L'en-tête est de nouveau
     * lisible (#1963), et la limite de l'API demande jusqu'à une minute :
     * l'écriture ne la fait pas attendre plus de cinq secondes. Sans en-tête,
     * elle attend deux secondes, comme avant.
     */
    it('n’attend pas plus de cinq secondes avant son nouvel essai, quoi que demande Retry-After', async () => {
        vi.useFakeTimers()
        request
            .mockRejectedValueOnce({ response: { status: 429, headers: { 'retry-after': '60' } }, request: {} })
            .mockResolvedValueOnce({ data: {} })
            .mockRejectedValueOnce({ response: { status: 429, headers: {} }, request: {} })
            .mockResolvedValueOnce({ data: {} })

        const service = await freshService()

        const premiere = service.post('/api/v1/sets', { reps: 10 })
        await vi.advanceTimersByTimeAsync(4999)
        expect(request).toHaveBeenCalledTimes(1)
        await vi.advanceTimersByTimeAsync(1)
        expect(request).toHaveBeenCalledTimes(2)
        await premiere

        const seconde = service.post('/api/v1/sets', { reps: 8 })
        await vi.advanceTimersByTimeAsync(1999)
        expect(request).toHaveBeenCalledTimes(3)
        await vi.advanceTimersByTimeAsync(1)
        expect(request).toHaveBeenCalledTimes(4)
        await seconde
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
        compte: COMPTE,
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
            compte: COMPTE,
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

/**
 * Un 5xx ou un 429 n'est pas un refus : le serveur redémarre, ou demande de
 * ralentir. Le vidage classait pourtant l'écriture refusée au premier essai et
 * passait à la suivante, si bien qu'un redémarrage pendant une mise en
 * production faisait passer toute la file d'une séance aux refusées (#1963).
 */
describe('SyncService erreurs passagères', () => {
    const troisCreations = () =>
        [1, 2, 3].map((n) => ({
            method: 'post',
            url: `/api/v1/sets?n=${n}`,
            data: { reps: n },
            id: `q${n}`,
            timestamp: '2026-10-05T10:00:00.000Z',
            compte: COMPTE,
        }))

    const tentees = () => request.mock.calls.map(([config]) => config.data.reps)

    afterEach(() => {
        vi.useRealTimers()
    })

    it.each([500, 502, 503, 429])('garde l’écriture en tête, et celles qui la suivent, sur un %i', async (status) => {
        vi.useFakeTimers()
        localStorage.setItem('offline_sync_queue', JSON.stringify(troisCreations()))
        request
            .mockResolvedValueOnce({ data: {} })
            .mockRejectedValueOnce({ response: { status, headers: {} } })
            .mockResolvedValue({ data: {} })

        const service = await chargé()

        expect(tentees()).toEqual([1, 2])
        expect(service.queue.map((entree) => entree.id)).toEqual(['q2', 'q3'])
        expect(JSON.parse(localStorage.getItem('offline_sync_queue')).map((entree) => entree.id)).toEqual(['q2', 'q3'])
        expect(service.failedRequests()).toEqual([])
    })

    it('repart seule après une attente, sans attendre un autre déclencheur', async () => {
        vi.useFakeTimers()
        localStorage.setItem('offline_sync_queue', JSON.stringify(troisCreations()))
        request.mockRejectedValueOnce({ response: { status: 503 } }).mockResolvedValue({ data: {} })

        const service = await chargé()
        expect(tentees()).toEqual([1])

        await vi.advanceTimersByTimeAsync(4999)
        expect(tentees()).toEqual([1])

        await vi.advanceTimersByTimeAsync(1)
        await service.pending

        expect(tentees()).toEqual([1, 1, 2, 3])
        expect(service.queue).toEqual([])
    })

    it('double l’attente à chaque échec passager', async () => {
        vi.useFakeTimers()
        localStorage.setItem('offline_sync_queue', JSON.stringify(troisCreations().slice(0, 1)))
        request.mockRejectedValue({ response: { status: 502 } })

        await chargé()
        await vi.advanceTimersByTimeAsync(5000)
        expect(request).toHaveBeenCalledTimes(2)

        await vi.advanceTimersByTimeAsync(9999)
        expect(request).toHaveBeenCalledTimes(2)

        await vi.advanceTimersByTimeAsync(1)
        expect(request).toHaveBeenCalledTimes(3)
    })

    it.each([
        ['un nombre de secondes', '30'],
        ['une date', () => new Date(Date.now() + 30_000).toUTCString()],
    ])('respecte le Retry-After d’un 429 donné en %s', async (_forme, valeur) => {
        vi.useFakeTimers()
        localStorage.setItem('offline_sync_queue', JSON.stringify(troisCreations().slice(0, 1)))
        request
            .mockRejectedValueOnce({
                response: {
                    status: 429,
                    headers: { 'retry-after': typeof valeur === 'function' ? valeur() : valeur },
                },
            })
            .mockResolvedValue({ data: {} })

        const service = await chargé()

        await vi.advanceTimersByTimeAsync(29_000)
        expect(request).toHaveBeenCalledTimes(1)

        await vi.advanceTimersByTimeAsync(1000)
        await service.pending

        expect(request).toHaveBeenCalledTimes(2)
        expect(service.queue).toEqual([])
    })

    it('ne laisse pas une écriture directe devancer l’attente', async () => {
        vi.useFakeTimers()
        localStorage.setItem('offline_sync_queue', JSON.stringify(troisCreations().slice(0, 1)))
        request.mockRejectedValueOnce({ response: { status: 500 } }).mockResolvedValue({ data: {} })

        const service = await chargé()

        await expect(service.patch('/api/v1/sets/1', { weight: 120 })).rejects.toMatchObject({ isOffline: true })

        // Rien n'est reparti : ni l'écriture en tête, ni la nouvelle.
        expect(request).toHaveBeenCalledTimes(1)
        expect(service.queue.map((entree) => entree.url)).toEqual(['/api/v1/sets?n=1', '/api/v1/sets/1'])

        await vi.advanceTimersByTimeAsync(5000)
        await service.pending

        expect(request.mock.calls.map(([config]) => config.url)).toEqual([
            '/api/v1/sets?n=1',
            '/api/v1/sets?n=1',
            '/api/v1/sets/1',
        ])
    })

    it('classe l’écriture refusée après six échecs passagers, l’annonce, et passe à la suivante', async () => {
        vi.useFakeTimers()
        localStorage.setItem('offline_sync_queue', JSON.stringify(troisCreations().slice(0, 2)))
        request.mockImplementation((config) =>
            config.data.reps === 1 ? Promise.reject({ response: { status: 503 } }) : Promise.resolve({ data: {} }),
        )
        const annonce = vi.fn()
        window.addEventListener('sync:failed', annonce)

        const service = await chargé()
        await vi.advanceTimersByTimeAsync(5000 + 10_000 + 20_000 + 40_000 + 80_000)
        await service.pending

        window.removeEventListener('sync:failed', annonce)

        expect(tentees()).toEqual([1, 1, 1, 1, 1, 1, 2])
        expect(service.failedRequests().map((entree) => entree.id)).toEqual(['q1'])
        expect(service.failedRequests()[0].status).toBe(503)
        expect(annonce).toHaveBeenCalledTimes(1)
        expect(service.queue).toEqual([])
    })

    it('garde le compte des essais à travers un rechargement', async () => {
        vi.useFakeTimers()
        localStorage.setItem('offline_sync_queue', JSON.stringify(troisCreations().slice(0, 1)))
        request.mockRejectedValue({ response: { status: 500 } })

        await chargé()

        expect(JSON.parse(localStorage.getItem('offline_sync_queue'))[0]).toMatchObject({
            transientAttempts: 1,
            prochainEssai: Date.now() + 5000,
        })
    })
})

/**
 * La file appartenait à l'appareil : une entrée ne disait pas qui l'avait
 * écrite, et le vidage partait sous la session connectée à ce moment-là. Sur un
 * appareil partagé, les préférences d'un compte s'appliquaient au suivant, et
 * ses séries revenaient en 403 annoncées au mauvais compte (#1964).
 */
describe('SyncService une file par compte', () => {
    const envoyees = () => request.mock.calls.map(([config]) => `${config.method} ${config.url}`)

    it('note sur chaque écriture le compte qui l’a faite', async () => {
        request.mockRejectedValue({ code: 'ERR_NETWORK', request: {} })

        const service = await chargé()
        await expect(service.patch('/profile/preferences', { preferences: {} })).rejects.toMatchObject({
            isOffline: true,
        })

        expect(JSON.parse(localStorage.getItem('offline_sync_queue'))[0].compte).toBe(COMPTE)
    })

    it('ne rejoue jamais l’écriture d’un compte sous la session d’un autre', async () => {
        // A modifie ses préférences sans réseau ; au rejeu, sa session a expiré.
        request.mockRejectedValueOnce({ code: 'ERR_NETWORK', request: {} })
        const service = await chargé()
        await expect(
            service.patch('/profile/preferences', { preferences: { personal_record: false } }),
        ).rejects.toMatchObject({ isOffline: true })

        request.mockRejectedValueOnce({ response: { status: 401 } })
        await service.processQueue()
        expect(service.queue[0].authAttempts).toBe(1)

        // B se connecte sur le même appareil et coche une série.
        request.mockReset()
        request.mockResolvedValue({ data: {} })
        naviguer(2)
        await service.pending
        await service.patch('/api/v1/sets/900', { is_completed: true })

        // Un nouveau chargement sous B ne rejoue rien non plus.
        const rechargee = await freshService({ compte: 2 })
        await rechargee.pending

        expect(envoyees()).toEqual(['patch /api/v1/sets/900'])
        expect(rechargee.enAttente()).toBe(0)
        expect(rechargee.queue).toHaveLength(1)

        // A revient : son écriture part sous sa propre session.
        naviguer(1)
        await rechargee.pending

        expect(envoyees()).toEqual(['patch /api/v1/sets/900', 'patch /profile/preferences'])
        expect(rechargee.queue).toEqual([])
    })

    it('ne fait pas attendre l’écriture directe d’un compte derrière la file d’un autre', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([{ ...aQueuedPatch(), compte: '7' }]))
        request.mockResolvedValue({ data: { ok: true } })

        const service = await chargé()
        const reponse = await service.patch('/api/v1/sets/5', { reps: 8 })

        expect(reponse.data).toEqual({ ok: true })
        expect(envoyees()).toEqual(['patch /api/v1/sets/5'])
    })

    it('ne rejoue pas, et efface, une écriture qui ne dit pas à qui elle appartient', async () => {
        const { compte: _sansCompte, ...ancienne } = aQueuedPatch()
        localStorage.setItem('offline_sync_queue', JSON.stringify([ancienne]))
        localStorage.setItem('offline_sync_failed', JSON.stringify([{ ...ancienne, status: 422 }]))
        request.mockResolvedValue({ data: {} })

        const service = await chargé()

        expect(request).not.toHaveBeenCalled()
        expect(service.queue).toEqual([])
        expect(localStorage.getItem('offline_sync_queue')).toBe('[]')
        expect(localStorage.getItem('offline_sync_failed')).toBeNull()
    })

    /*
     * Effacées sans un mot, alors que la version précédente les rejouait : une
     * séance faite hors ligne, rouverte après la mise à jour, perdait ses
     * dernières séries sans que personne le sache. Le nombre, et lui seul,
     * attend que le premier écran authentifié le dise.
     */
    it('note combien d’écritures sans compte le chargement a effacées, une seule fois', async () => {
        const { compte: _sansCompte, ...ancienne } = aQueuedPatch()
        localStorage.setItem('offline_sync_queue', JSON.stringify([ancienne, { ...ancienne, id: 'queued-2' }]))
        localStorage.setItem('offline_sync_failed', JSON.stringify([{ ...ancienne, status: 422 }]))

        await chargé()

        expect(request).not.toHaveBeenCalled()
        expect(JSON.parse(localStorage.getItem('gym-tracker:ecritures-effacees'))).toBe(3)
        expect(localStorage.getItem('gym-tracker:ecritures-effacees')).not.toContain('/api')

        // Un second chargement n'a plus rien à effacer, ni à compter de nouveau.
        await chargé()

        const { reprendreLesEcrituresEffacees } = await import('@/Utils/ecrituresEffacees')
        expect(reprendreLesEcrituresEffacees()).toBe(3)
        expect(reprendreLesEcrituresEffacees()).toBe(0)
    })

    it('ne note rien quand chaque écriture dit à qui elle appartient', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch()]))
        request.mockResolvedValue({ data: {} })

        await chargé()

        expect(localStorage.getItem('gym-tracker:ecritures-effacees')).toBeNull()
    })

    it('ne met rien en file quand personne n’est connecté, et rend l’échec tel quel', async () => {
        request.mockRejectedValue({ code: 'ERR_NETWORK', request: {} })

        const service = await freshService({ compte: null })

        await expect(service.patch('/api/v1/sets/1', { reps: 8 })).rejects.toMatchObject({ code: 'ERR_NETWORK' })
        expect(service.queue).toEqual([])
    })

    it('ne vide rien tant que personne n’est connecté, et vide la file du compte qui se connecte', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch()]))
        request.mockResolvedValue({ data: {} })

        const service = await freshService({ compte: null })
        await service.pending
        expect(request).not.toHaveBeenCalled()

        naviguer(1)
        await service.pending

        expect(request).toHaveBeenCalledTimes(1)
        expect(service.queue).toEqual([])
    })

    it('lit une page illisible comme une page sans compte', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch()]))

        const service = await freshService({ compte: null, page: '{coupé' })
        await service.pending

        expect(service.compte).toBeNull()
        expect(request).not.toHaveBeenCalled()
    })

    it('ne montre et n’efface que les refus du compte connecté', async () => {
        localStorage.setItem(
            'offline_sync_failed',
            JSON.stringify([
                { ...aQueuedPatch('/api/v1/sets/1'), status: 422 },
                { ...aQueuedPatch('/api/v1/sets/2'), compte: '2', status: 422 },
            ]),
        )

        const service = await chargé()

        expect(service.failedRequests().map((refus) => refus.url)).toEqual(['/api/v1/sets/1'])

        service.clearFailedRequests()

        expect(service.failedRequests()).toEqual([])
        expect(JSON.parse(localStorage.getItem('offline_sync_failed')).map((refus) => refus.url)).toEqual([
            '/api/v1/sets/2',
        ])
    })

    it('ne compte en attente que les écritures du compte connecté', async () => {
        localStorage.setItem(
            'offline_sync_queue',
            JSON.stringify([aQueuedPatch('/a'), { ...aQueuedPatch('/b'), compte: '2' }, aQueuedPatch('/c')]),
        )
        request.mockRejectedValue({ code: 'ERR_NETWORK', request: {} })

        const service = await chargé()

        expect(service.enAttente()).toBe(2)

        naviguer(null)

        expect(service.enAttente()).toBe(0)
    })
})

describe('SyncService une écriture partie juste avant une déconnexion', () => {
    it('reste au compte qui l’a faite, même si le réseau tombe après son départ', async () => {
        let echouer
        request.mockImplementationOnce(() => new Promise((_, reject) => (echouer = reject)))

        const service = await chargé()
        const ecriture = service.patch('/api/v1/sets/3', { reps: 6 })

        service.definirLeCompte(null)
        echouer({ code: 'ERR_NETWORK', request: {} })
        await expect(ecriture).rejects.toMatchObject({ isOffline: true })

        expect(service.queue.map((entree) => entree.compte)).toEqual([COMPTE])
    })
})

/**
 * Une série créée hors ligne n'a pas d'identifiant à donner à un PATCH ni à un
 * DELETE : sa saisie, sa coche et sa suppression passent par l'entrée de file
 * qui la crée, tant qu'elle attend (#1960).
 */
describe('SyncService une écriture qui attend encore', () => {
    const uneCreation = (id = 'q1', compte = COMPTE) => ({
        method: 'post',
        url: '/api/v1/sets',
        data: { workout_line_id: 1, is_completed: false, reps: 5 },
        id,
        timestamp: '2026-10-05T10:00:00.000Z',
        compte,
    })

    it('fond une saisie dans sa charge, et l’écrit', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([uneCreation()]))
        request.mockRejectedValue({ code: 'ERR_NETWORK', request: {} })

        const service = await chargé()

        expect(service.modifierEnFile('q1', { reps: 3, is_completed: true })).toBe(true)
        expect(JSON.parse(localStorage.getItem('offline_sync_queue'))[0].data).toEqual({
            workout_line_id: 1,
            is_completed: true,
            reps: 3,
        })
    })

    it('la retire, et dit lesquelles sont retirées', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([uneCreation('q1'), uneCreation('q2')]))
        request.mockRejectedValue({ code: 'ERR_NETWORK', request: {} })
        const annonce = vi.fn()
        window.addEventListener('sync:retired', annonce)

        const service = await chargé()
        const retiree = service.retirerDeLaFile('q1')

        window.removeEventListener('sync:retired', annonce)

        expect(retiree).toBe(true)
        expect(JSON.parse(localStorage.getItem('offline_sync_queue')).map((entree) => entree.id)).toEqual(['q2'])
        expect(annonce.mock.calls[0][0].detail).toEqual({ queueIds: ['q1'] })
    })

    it('ne touche ni à une entrée inconnue, ni à celle d’un autre compte', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([uneCreation('q1', '2')]))

        const service = await chargé()

        expect(service.modifierEnFile('q1', { reps: 3 })).toBe(false)
        expect(service.modifierEnFile('inconnue', { reps: 3 })).toBe(false)
        expect(service.retirerDeLaFile('q1')).toBe(false)
        expect(service.queue[0].data.reps).toBe(5)
    })

    it('ne touche pas à celle qui vole : elle est partie avec sa charge', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([uneCreation()]))
        let repondre
        request.mockImplementationOnce(() => new Promise((resolve) => (repondre = resolve)))

        const service = await freshService()
        await new Promise((resolve) => setTimeout(resolve, 0))

        expect(service.modifierEnFile('q1', { reps: 3 })).toBe(false)
        expect(service.retirerDeLaFile('q1')).toBe(false)

        repondre({ data: { data: { id: 100 } } })
        await service.pending

        expect(request.mock.calls[0][0].data.reps).toBe(5)
    })

    it('annonce ce qu’elle a produit et ce qu’elle a emporté, une fois la file réécrite', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([uneCreation()]))
        request.mockResolvedValue({ data: { data: { id: 100 } } })
        let fileAuMomentDeLAnnonce
        const annonce = vi.fn(() => {
            fileAuMomentDeLAnnonce = localStorage.getItem('offline_sync_queue')
        })
        window.addEventListener('sync:replayed', annonce)

        await chargé()

        window.removeEventListener('sync:replayed', annonce)

        expect(annonce.mock.calls[0][0].detail).toEqual({
            queueId: 'q1',
            url: '/api/v1/sets',
            data: { id: 100 },
            envoye: { workout_line_id: 1, is_completed: false, reps: 5 },
            ajustement: null,
        })
        expect(fileAuMomentDeLAnnonce).toBe('[]')
    })

    it('n’envoie au serveur que la requête, son délai et le compte qui l’a faite, sans ce que la file note pour elle-même', async () => {
        localStorage.setItem(
            'offline_sync_queue',
            JSON.stringify([{ ...uneCreation(), headers: { 'Idempotency-Key': 'k' }, authAttempts: 1 }]),
        )
        request.mockResolvedValue({ data: {} })

        await chargé()
        const { DELAI_DE_REJEU_MS } = await import('@/Utils/SyncService')

        expect(request.mock.calls[0][0]).toEqual({
            method: 'post',
            url: '/api/v1/sets',
            data: { workout_line_id: 1, is_completed: false, reps: 5 },
            headers: { 'Idempotency-Key': 'k', 'X-Compte-De-L-Ecriture': COMPTE },
            timeout: DELAI_DE_REJEU_MS,
        })
    })
})

/**
 * `fetch` n'a pas de délai maximum. Le vidage reconstruisait la requête sans
 * celui que l'appelant avait donné à son écriture, et une requête restée sans
 * réponse figeait la file, et derrière elle toute écriture directe, qui attend
 * la file avant de partir (#1963).
 */
describe('SyncService le délai d’une écriture rejouée', () => {
    afterEach(() => {
        vi.useRealTimers()
    })

    it('garde celui que l’appelant avait donné à son écriture', async () => {
        const service = await chargé()

        request.mockRejectedValueOnce({ code: 'ERR_NETWORK', request: {} })
        await expect(
            service.patch('/profile/push-preferences', { types: ['personal_record'] }, { timeout: 8000 }),
        ).rejects.toMatchObject({ isOffline: true })

        request.mockResolvedValue({ data: {} })
        await service.processQueue()

        expect(request.mock.calls[1][0]).toMatchObject({ url: '/profile/push-preferences', timeout: 8000 })
    })

    it('borne les autres : une requête du vidage sans réponse ne retient plus l’écriture suivante', async () => {
        const service = await chargé()
        const { DELAI_DE_REJEU_MS } = await import('@/Utils/SyncService')
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })

        request.mockRejectedValueOnce({ code: 'ERR_NETWORK', request: {} })
        await expect(service.patch('/api/v1/sets/3', { reps: 6 })).rejects.toMatchObject({ isOffline: true })

        // Comme `Utils/http` : sans délai, la requête attend indéfiniment ; avec, elle échoue à son terme.
        request.mockImplementation(
            (config) =>
                new Promise((_resolve, reject) => {
                    if (config.timeout !== undefined) {
                        setTimeout(() => reject({ code: 'ERR_NETWORK', request: {} }), config.timeout)
                    }
                }),
        )

        let issue = 'en attente'
        service.patch('/api/v1/sets/3', { reps: 7 }).catch((erreur) => (issue = erreur))

        await vi.advanceTimersByTimeAsync(DELAI_DE_REJEU_MS)

        // La file n'a pas répondu : la nouvelle écriture se range derrière, au lieu d'attendre pour toujours.
        expect(issue).toMatchObject({ isOffline: true })
        expect(service.queue.map((entree) => entree.data)).toEqual([{ reps: 6 }, { reps: 7 }])
    })
})

/**
 * La série d'un exercice encore en file n'a pas d'identifiant d'exercice à
 * donner. Elle entre en file tout de suite, derrière lui, et le nomme par son
 * entrée : le vidage le remplace par l'identifiant réel (#1962).
 */
describe('SyncService une écriture qui en attend une autre', () => {
    const ligneEnFile = {
        method: 'post',
        url: '/api/v1/workout-lines',
        data: { workout_id: 5, exercise_id: 7 },
        id: 'qL',
        timestamp: '2026-10-05T10:00:00.000Z',
        compte: COMPTE,
    }

    const serieDe = (reps) => ({
        method: 'post',
        url: '/api/v1/sets',
        data: { workout_line_id: { enAttenteDe: 'qL' }, reps },
    })

    const horsLigne = async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([ligneEnFile]))
        request.mockRejectedValue({ code: 'ERR_NETWORK', request: {} })

        return chargé()
    }

    it('entre en file sans être tentée, derrière ce dont elle dépend, avec sa clé d’idempotence', async () => {
        const service = await horsLigne()

        const queueId = service.mettreEnFile(serieDe(8))

        expect(request).toHaveBeenCalledTimes(1)
        expect(JSON.parse(localStorage.getItem('offline_sync_queue'))).toEqual([
            // La ligne, elle, a été tentée au chargement : sans réponse, elle a pu aboutir.
            { ...ligneEnFile, tentee: true },
            expect.objectContaining({
                id: queueId,
                compte: COMPTE,
                data: { workout_line_id: { enAttenteDe: 'qL' }, reps: 8 },
                headers: { 'Idempotency-Key': expect.any(String) },
            }),
        ])
    })

    it('part avec l’identifiant que son parent a produit', async () => {
        const service = await horsLigne()
        service.mettreEnFile(serieDe(8))
        service.mettreEnFile(serieDe(6))

        request.mockReset()
        request.mockResolvedValueOnce({ data: { data: { id: 70 } } }).mockResolvedValue({ data: { data: {} } })
        await service.processQueue()

        expect(request.mock.calls.map(([config]) => config.data)).toEqual([
            { workout_id: 5, exercise_id: 7 },
            { workout_line_id: 70, reps: 8 },
            { workout_line_id: 70, reps: 6 },
        ])
    })

    it('écrit l’identifiant dans la file en même temps qu’il retire le parent', async () => {
        const service = await horsLigne()
        service.mettreEnFile(serieDe(8))

        request.mockReset()
        request.mockResolvedValueOnce({ data: { data: { id: 70 } } }).mockRejectedValue({ code: 'ERR_NETWORK' })
        await service.processQueue()

        expect(JSON.parse(localStorage.getItem('offline_sync_queue')).map((entree) => entree.data)).toEqual([
            { workout_line_id: 70, reps: 8 },
        ])
    })

    it('prend l’identifiant réel quand elle n’entre en file qu’après le rejeu de son parent', async () => {
        const service = await horsLigne()

        request.mockReset()
        request.mockResolvedValueOnce({ data: { data: { id: 70 } } })
        await service.processQueue()

        request.mockRejectedValue({ code: 'ERR_NETWORK' })
        service.mettreEnFile(serieDe(8))

        expect(service.queue.map((entree) => entree.data)).toEqual([{ workout_line_id: 70, reps: 8 }])
    })

    it('n’entre pas quand son parent est sorti de la file sans rien produire', async () => {
        const service = await horsLigne()
        service.retirerDeLaFile('qL')

        expect(service.mettreEnFile(serieDe(8))).toBeNull()
        expect(service.queue).toEqual([])
    })

    it('est refusée sans partir, et annoncée, quand son parent est refusé', async () => {
        const service = await horsLigne()
        service.mettreEnFile(serieDe(8))
        const annonce = vi.fn()
        window.addEventListener('sync:failed', annonce)

        request.mockReset()
        request.mockRejectedValueOnce({ response: { status: 422 } })
        await service.processQueue()

        window.removeEventListener('sync:failed', annonce)

        expect(request).toHaveBeenCalledTimes(1)
        expect(annonce).toHaveBeenCalledTimes(2)
        expect(service.failedRequests().map((refus) => [refus.url, refus.status])).toEqual([
            ['/api/v1/workout-lines', 422],
            ['/api/v1/sets', null],
        ])
        expect(service.queue).toEqual([])
    })

    it('sort de la file avec son parent, et le dit', async () => {
        const service = await horsLigne()
        const serie = service.mettreEnFile(serieDe(8))
        const autre = service.mettreEnFile({ method: 'patch', url: '/api/v1/sets/3', data: { reps: 4 } })
        const annonce = vi.fn()
        window.addEventListener('sync:retired', annonce)

        expect(service.retirerDeLaFile('qL')).toBe(true)

        window.removeEventListener('sync:retired', annonce)

        expect(annonce.mock.calls[0][0].detail).toEqual({ queueIds: ['qL', serie] })
        expect(service.queue.map((entree) => entree.id)).toEqual([autre])
    })

    it('ne s’attribue à personne quand personne n’est connecté', async () => {
        const service = await freshService({ compte: null })

        expect(service.mettreEnFile({ method: 'post', url: '/api/v1/sets', data: { reps: 8 } })).toBeNull()
    })
})

/**
 * Le compte que l'onglet croit connecté vient de la dernière page qu'il a
 * reçue. Un autre onglet, ou la PWA, qui partagent les cookies, ont pu ouvrir
 * depuis la session d'un autre compte : seul le serveur sait laquelle
 * accompagne la requête. Le vidage lui envoie donc le compte de chaque
 * écriture, et il refuse sans l'exécuter celle d'un autre (#1964).
 */
describe('SyncService une écriture rejouée sous la session d’un autre compte', () => {
    const refusPourUnAutreCompte = { response: { status: 409, data: { raison: 'compte-different' } } }

    it('reste en tête pour son compte, sans essai consommé ni refus annoncé', async () => {
        localStorage.setItem(
            'offline_sync_queue',
            JSON.stringify([
                aQueuedPatch('/profile/preferences'),
                { ...aQueuedPatch('/api/v1/sets/2'), id: 'queued-2' },
            ]),
        )
        request.mockRejectedValueOnce(refusPourUnAutreCompte)
        const annonces = vi.fn()
        window.addEventListener('sync:failed', annonces)
        window.addEventListener('sync:auth-required', annonces)

        const service = await chargé()

        window.removeEventListener('sync:failed', annonces)
        window.removeEventListener('sync:auth-required', annonces)

        expect(request).toHaveBeenCalledTimes(1)
        expect(request.mock.calls[0][0].headers).toEqual({ 'X-Compte-De-L-Ecriture': COMPTE })
        expect(service.queue.map((entree) => entree.id)).toEqual(['queued-1', 'queued-2'])
        expect(service.queue[0]).not.toHaveProperty('authAttempts')
        expect(service.queue[0]).not.toHaveProperty('transientAttempts')
        expect(service.queue[0]).not.toHaveProperty('prochainEssai')
        expect(service.failedRequests()).toEqual([])
        expect(annonces).not.toHaveBeenCalled()

        // Sous la session de son compte, elle part, et celle qui la suit aussi.
        request.mockResolvedValue({ data: {} })
        await service.processQueue()

        expect(request.mock.calls.map(([config]) => config.url)).toEqual([
            '/profile/preferences',
            '/profile/preferences',
            '/api/v1/sets/2',
        ])
        expect(service.queue).toEqual([])
    })

    it('classe refusé un autre conflit, comme avant', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch()]))
        request.mockRejectedValueOnce({ response: { status: 409, data: { message: 'Conflit' } } })

        const service = await chargé()

        expect(service.queue).toEqual([])
        expect(service.failedRequests()).toHaveLength(1)
    })

    it('ne croit pas revenu le compte d’une page que le bouton Retour restaure', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch('/profile/preferences')]))
        request.mockResolvedValue({ data: {} })

        // B est connecté ; la page de A revient de l'historique, sans réponse du serveur.
        const service = await freshService({ compte: 2 })
        await service.pending
        restaurerDepuisLHistorique(1)
        await service.pending

        expect(service.compte).toBe('2')
        expect(request).not.toHaveBeenCalled()

        // A se reconnecte : c'est une réponse du serveur qui le dit.
        naviguer(1)
        await service.pending

        expect(request).toHaveBeenCalledTimes(1)
        expect(service.queue).toEqual([])
    })
})

/**
 * Une création partie sans réponse a pu atteindre le serveur. Le vidage la
 * rejoue avec la même clé d'idempotence, et le serveur rend ce qu'il avait
 * créé sans le doubler. Mais retirée de l'écran pendant l'attente, elle ne
 * pouvait plus être seulement oubliée : la ligne restait sur le serveur (#1960).
 */
describe('SyncService une création tentée sans réponse', () => {
    const creation = (id, autres = {}) => ({
        method: 'post',
        url: '/api/v1/sets',
        data: { workout_line_id: 1, is_completed: false, reps: 5 },
        headers: { 'Idempotency-Key': `cle-${id}` },
        id,
        timestamp: '2026-10-05T10:00:00.000Z',
        compte: COMPTE,
        ...autres,
    })

    const annulerPar = (id) => `/api/v1/sets/${id}`

    it('note qu’elle a été tentée avant de partir, pour qu’une page qui meurt pendant le vol le sache encore', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([creation('q1')]))
        request.mockImplementation(() => new Promise(() => {}))

        await freshService()
        await new Promise((resolve) => setTimeout(resolve, 0))

        expect(JSON.parse(localStorage.getItem('offline_sync_queue'))[0].tentee).toBe(true)
    })

    it('note aussi la création dont la tentative directe n’a pas eu de réponse, et pas une modification', async () => {
        request.mockRejectedValue({ code: 'ERR_NETWORK', request: {} })
        const service = await chargé()

        await expect(service.post('/api/v1/sets', { reps: 5 })).rejects.toMatchObject({ isOffline: true })

        // Notée dès l'échec, avant que le moindre vidage ne la reprenne.
        expect(service.queue[0].tentee).toBe(true)

        await expect(service.patch('/api/v1/sets/3', { reps: 6 })).rejects.toMatchObject({ isOffline: true })

        expect(service.queue.map((entree) => [entree.method, entree.tentee ?? false])).toEqual([
            ['post', true],
            ['patch', false],
        ])
    })

    it('retirée, reste en file pour annuler ce qu’elle a produit, et ses dépendantes sortent', async () => {
        localStorage.setItem(
            'offline_sync_queue',
            JSON.stringify([
                creation('q1', { tentee: true }),
                creation('q2', { data: { workout_line_id: { enAttenteDe: 'q1' }, reps: 8 } }),
                aQueuedPatch('/api/v1/sets/3'),
            ]),
        )
        request.mockRejectedValue({ code: 'ERR_NETWORK', request: {} })
        const retraits = vi.fn()
        window.addEventListener('sync:retired', retraits)

        const service = await chargé()

        expect(service.retirerDeLaFile('q1', { annulerPar })).toBe(true)

        window.removeEventListener('sync:retired', retraits)

        expect(retraits.mock.calls[0][0].detail).toEqual({ queueIds: ['q1', 'q2'] })
        expect(
            JSON.parse(localStorage.getItem('offline_sync_queue')).map((entree) => [entree.id, entree.aAnnuler]),
        ).toEqual([
            ['q1', '/api/v1/sets/__produit__'],
            ['queued-1', undefined],
        ])

        // Elle ne porte plus rien, et rien ne s'appuie plus sur elle.
        expect(service.modifierEnFile('q1', { reps: 3 })).toBe(false)
        expect(
            service.mettreEnFile({
                method: 'post',
                url: '/api/v1/sets',
                data: { workout_line_id: { enAttenteDe: 'q1' } },
            }),
        ).toBeNull()
        expect(service.retirerDeLaFile('q1', { annulerPar })).toBe(true)

        // Le réseau revient : la création repart, puis ce qu'elle a produit est supprimé.
        request.mockReset()
        request.mockResolvedValueOnce({ data: { data: { id: 100 } } }).mockResolvedValue({ data: null })
        const rejeux = vi.fn()
        window.addEventListener('sync:replayed', rejeux)

        await service.processQueue()

        window.removeEventListener('sync:replayed', rejeux)

        expect(request.mock.calls.map(([config]) => `${config.method} ${config.url}`)).toEqual([
            'post /api/v1/sets',
            'delete /api/v1/sets/100',
            'patch /api/v1/sets/3',
        ])
        expect(request.mock.calls[1][0].headers).toEqual({ 'X-Compte-De-L-Ecriture': COMPTE })
        expect(rejeux.mock.calls.map(([event]) => event.detail.queueId)).toEqual(['queued-1'])
        expect(service.queue).toEqual([])
        expect(service.failedRequests()).toEqual([])
    })

    it('garde son annulation en file quand le réseau retombe entre les deux', async () => {
        localStorage.setItem(
            'offline_sync_queue',
            JSON.stringify([creation('q1', { tentee: true, aAnnuler: '/api/v1/sets/__produit__' })]),
        )
        request
            .mockResolvedValueOnce({ data: { data: { id: 100 } } })
            .mockRejectedValueOnce({ code: 'ERR_NETWORK', request: {} })

        await chargé()

        expect(JSON.parse(localStorage.getItem('offline_sync_queue'))).toEqual([
            expect.objectContaining({ method: 'delete', url: '/api/v1/sets/100', compte: COMPTE, annulation: true }),
        ])
    })

    it('n’annonce ni le refus d’une création déjà retirée, ni le 404 de son annulation', async () => {
        localStorage.setItem(
            'offline_sync_queue',
            JSON.stringify([
                creation('q1', { tentee: true, aAnnuler: '/api/v1/sets/__produit__' }),
                creation('q2', { tentee: true, aAnnuler: '/api/v1/sets/__produit__' }),
            ]),
        )
        request
            .mockRejectedValueOnce({ response: { status: 422 } })
            .mockResolvedValueOnce({ data: { data: { id: 101 } } })
            .mockRejectedValueOnce({ response: { status: 404 } })
        const refus = vi.fn()
        window.addEventListener('sync:failed', refus)

        const service = await chargé()

        window.removeEventListener('sync:failed', refus)

        expect(request.mock.calls.map(([config]) => `${config.method} ${config.url}`)).toEqual([
            'post /api/v1/sets',
            'post /api/v1/sets',
            'delete /api/v1/sets/101',
        ])
        expect(refus).not.toHaveBeenCalled()
        expect(service.queue).toEqual([])
        expect(service.failedRequests()).toEqual([])
    })

    /*
     * Le serveur reconnaît la clé d'idempotence d'une création qu'il avait
     * déjà faite, rend la série telle qu'il l'avait enregistrée et ignore la
     * charge rejouée. Seule la page renvoyait ce qu'il avait ignoré : après un
     * rechargement, la saisie et la coche fondues dans l'entrée se perdaient
     * sans bruit (#1960).
     */
    it('fait suivre, à sa place, la modification de ce que le serveur a ignoré, et l’annonce', async () => {
        localStorage.setItem(
            'offline_sync_queue',
            JSON.stringify([
                creation('q1', {
                    tentee: true,
                    ajusterPar: '/api/v1/sets/__produit__',
                    data: { workout_line_id: 1, is_completed: true, weight: 80, reps: 3 },
                }),
                aQueuedPatch('/api/v1/sets/3'),
            ]),
        )
        const existante = { id: 100, workout_line_id: 1, is_completed: false, weight: '80.0', reps: 5 }
        request
            .mockResolvedValueOnce({ data: { data: existante } })
            .mockRejectedValueOnce({ code: 'ERR_NETWORK', request: {} })
        const rejeux = vi.fn()
        window.addEventListener('sync:replayed', rejeux)

        const service = await chargé()

        window.removeEventListener('sync:replayed', rejeux)

        // Écrite d'un seul coup : un rechargement maintenant la retrouve, à la place de la création.
        const durable = JSON.parse(localStorage.getItem('offline_sync_queue'))
        expect(durable.map(({ method, url, data, compte }) => ({ method, url, data, compte }))).toEqual([
            { method: 'patch', url: '/api/v1/sets/100', data: { is_completed: true, reps: 3 }, compte: COMPTE },
            { method: 'patch', url: '/api/v1/sets/3', data: { weight: 100 }, compte: COMPTE },
        ])
        expect(rejeux.mock.calls[0][0].detail).toEqual({
            queueId: 'q1',
            url: '/api/v1/sets',
            data: { ...existante, is_completed: true, reps: 3 },
            envoye: { workout_line_id: 1, is_completed: true, weight: 80, reps: 3 },
            ajustement: durable[0].id,
        })
        expect(service.estEnFile(durable[0].id)).toBe(true)

        // Le réseau revient : la modification part avant ce qui la suivait.
        request.mockReset()
        request.mockResolvedValue({ data: { data: {} } })
        await service.processQueue()

        expect(
            request.mock.calls.map(([config]) => `${config.method} ${config.url} ${JSON.stringify(config.data)}`),
        ).toEqual(['patch /api/v1/sets/100 {"is_completed":true,"reps":3}', 'patch /api/v1/sets/3 {"weight":100}'])
        expect(service.queue).toEqual([])
    })

    it('ne fait rien suivre quand le serveur a gardé ce qui est parti, ou que la création ne dit pas comment se rattraper', async () => {
        localStorage.setItem(
            'offline_sync_queue',
            JSON.stringify([
                creation('q1', { ajusterPar: '/api/v1/sets/__produit__' }),
                creation('q2', { tentee: true }),
            ]),
        )
        request
            .mockResolvedValueOnce({ data: { data: { id: 100, workout_line_id: 1, is_completed: false, reps: '5' } } })
            .mockResolvedValueOnce({ data: { data: { id: 101, workout_line_id: 1, is_completed: true, reps: 9 } } })

        const service = await chargé()

        expect(request).toHaveBeenCalledTimes(2)
        expect(service.queue).toEqual([])
    })

    it('retient l’adresse du rattrapage d’une création mise en file, sans l’envoyer au serveur', async () => {
        request.mockRejectedValue({ code: 'ERR_NETWORK', request: {} })
        const service = await chargé()
        const ajusterPar = (id) => `/api/v1/sets/${id}`

        await expect(
            service.post('/api/v1/sets', { workout_line_id: 1, reps: 5 }, { ajusterPar }),
        ).rejects.toMatchObject({ isOffline: true })
        service.mettreEnFile({ method: 'post', url: '/api/v1/sets', data: { workout_line_id: 2 }, ajusterPar })
        // Une modification n'a rien à rattraper.
        await expect(service.patch('/api/v1/sets/3', { reps: 6 }, { ajusterPar })).rejects.toMatchObject({
            isOffline: true,
        })

        expect(request.mock.calls[0][0]).not.toHaveProperty('ajusterPar')
        expect(JSON.parse(localStorage.getItem('offline_sync_queue')).map((entree) => entree.ajusterPar)).toEqual([
            '/api/v1/sets/__produit__',
            '/api/v1/sets/__produit__',
            undefined,
        ])
    })

    it('oublie simplement une création qui n’est jamais partie', async () => {
        localStorage.setItem(
            'offline_sync_queue',
            JSON.stringify([{ ...aQueuedPatch(), prochainEssai: Date.now() + 60_000 }, creation('q2')]),
        )

        const service = await chargé()

        expect(request).not.toHaveBeenCalled()
        expect(service.retirerDeLaFile('q2', { annulerPar })).toBe(true)
        expect(service.queue.map((entree) => entree.id)).toEqual(['queued-1'])
    })
})

/**
 * « Terminer » ne regardait que la file. Ce que le vidage déclenche lui-même
 * hors de la file (l'adoption d'une série rejouée) volait encore quand la
 * clôture partait, et pouvait revenir refusé d'une séance déjà close (#1961).
 */
describe('SyncService les écritures en cours', () => {
    it('compte une écriture directe jusqu’à sa réponse, et pas une lecture', async () => {
        const service = await chargé()
        let repondre
        request
            .mockImplementationOnce(() => new Promise((resolve) => (repondre = resolve)))
            .mockImplementationOnce(() => new Promise(() => {}))

        const ecriture = service.patch('/api/v1/sets/3', { reps: 6 })
        service.get('/api/v1/sets')

        expect(service.ecrituresEnCours()).toBe(1)

        repondre({ data: {} })
        await ecriture

        expect(service.ecrituresEnCours()).toBe(0)
    })

    it('attend les écritures directes en vol, et revide la file quand l’une y finit', async () => {
        const service = await chargé()
        let echouer
        request.mockImplementationOnce(() => new Promise((_resolve, reject) => (echouer = reject)))
        const ecriture = service.patch('/api/v1/sets/3', { reps: 6 }).catch((erreur) => erreur)

        let reglee = false
        const attente = service.attendreLesEcritures().then(() => (reglee = true))
        await new Promise((resolve) => setTimeout(resolve, 0))

        expect(reglee).toBe(false)

        // Le réseau la refuse : elle finit en file, et le vidage relancé la renvoie.
        request.mockResolvedValue({ data: {} })
        echouer({ code: 'ERR_NETWORK', request: {} })
        await attente

        await expect(ecriture).resolves.toMatchObject({ isOffline: true })
        expect(request.mock.calls.map(([config]) => `${config.method} ${config.url}`)).toEqual([
            'patch /api/v1/sets/3',
            'patch /api/v1/sets/3',
        ])
        expect(service.queue).toEqual([])
        expect(service.ecrituresEnCours()).toBe(0)
    })
})
