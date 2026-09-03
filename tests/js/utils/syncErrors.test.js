import { describe, it, expect, afterEach } from 'vitest'
import { classifySyncError, SYNC_AUTH, SYNC_OFFLINE, SYNC_PERMANENT, SYNC_TRANSIENT } from '@/Utils/syncErrors'

const setOnline = (value) => {
    Object.defineProperty(navigator, 'onLine', { writable: true, configurable: true, value })
}

afterEach(() => setOnline(true))

describe('classifySyncError', () => {
    it('treats the shape SyncService rejects with as offline', () => {
        expect(classifySyncError({ isOffline: true, message: 'Offline: Request queued' })).toBe(SYNC_OFFLINE)
    })

    it('treats a dropped connection as offline even without the flag', () => {
        expect(classifySyncError({ code: 'ERR_NETWORK' })).toBe(SYNC_OFFLINE)

        // What axios hands back when the request went out and nothing came
        // home: a request, no response.
        setOnline(false)
        expect(classifySyncError({ request: {}, message: 'Network Error' })).toBe(SYNC_OFFLINE)
    })

    /**
     * `navigator.onLine` used to decide this on its own, and it is not entitled
     * to: it reports the network interface, not reachability, and an iOS PWA
     * reports it false at a cold launch on a working connection.
     *
     * Calling an unattributable error "offline" is not a harmless guess.
     * SYNC_OFFLINE is a promise that SyncService has the write queued, and the
     * draft replay in Workouts/Show acts on it by DELETING the local draft as a
     * duplicate. Say it about an error nothing queued and the edit is gone.
     * Transient is the honest answer: try again, keep the draft.
     */
    it('does not call an unattributable error offline just because the flag is false', () => {
        setOnline(false)

        expect(classifySyncError(new Error('whatever'))).toBe(SYNC_TRANSIENT)
    })

    it('trusts a server that answered over the flag', () => {
        setOnline(false)

        expect(classifySyncError({ response: { status: 422 }, request: {} })).toBe(SYNC_PERMANENT)
    })

    /**
     * The whole point: a 4xx will not become a 2xx on the next attempt, so the
     * edit behind it must stop being retried and be surfaced instead.
     */
    it.each([400, 403, 404, 409, 422])('treats %i as permanent', (status) => {
        expect(classifySyncError({ response: { status } })).toBe(SYNC_PERMANENT)
    })

    it.each([500, 502, 503, 504])('treats %i as transient', (status) => {
        expect(classifySyncError({ response: { status } })).toBe(SYNC_TRANSIENT)
    })

    /**
     * SyncService already retries 429 once on its own, and a rate limit lifts.
     * Classing it with the 4xx family would throw away an edit that would have
     * gone through a moment later.
     */
    it('treats a rate limit as transient despite being a 4xx', () => {
        expect(classifySyncError({ response: { status: 429 } })).toBe(SYNC_TRANSIENT)
    })

    it('treats an unattributable failure as transient rather than losing the edit', () => {
        expect(classifySyncError(new Error('boom'))).toBe(SYNC_TRANSIENT)
        expect(classifySyncError(undefined)).toBe(SYNC_TRANSIENT)
        expect(classifySyncError({ response: {} })).toBe(SYNC_TRANSIENT)
    })
})

describe('classifySyncError, session expirée', () => {
    it.each([401, 419])('traite %i comme une porte fermée, pas comme un refus', (status) => {
        expect(classifySyncError({ response: { status } })).toBe(SYNC_AUTH)
    })
})
