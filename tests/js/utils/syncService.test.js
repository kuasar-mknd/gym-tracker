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
    it.each([422, 419, 403, 500])('does not lose a mutation the server refused with %i', async (status) => {
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

    it('announces the refusal so the interface can say something', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([aQueuedPatch('/api/v1/sets/7')]))
        request.mockRejectedValue({ response: { status: 422 } })

        const listener = vi.fn()
        window.addEventListener('sync:failed', listener)

        const service = await freshService()
        await service.processQueue()

        window.removeEventListener('sync:failed', listener)

        expect(listener).toHaveBeenCalledTimes(1)
        expect(listener.mock.calls[0][0].detail).toEqual({ url: '/api/v1/sets/7', status: 422 })
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
