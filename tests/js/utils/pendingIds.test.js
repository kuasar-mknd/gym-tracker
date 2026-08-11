import { describe, it, expect } from 'vitest'
import { PendingIds, isTemporaryId } from '@/Utils/pendingIds'

const deferred = () => {
    let resolve
    let reject
    const promise = new Promise((res, rej) => {
        resolve = res
        reject = rej
    })

    return { promise, resolve, reject }
}

describe('isTemporaryId', () => {
    it.each([
        ['temp-1', true],
        ['temp-42', true],
        [12, false],
        ['12', false],
        [null, false],
        [undefined, false],
    ])('reads %s as temporary: %s', (id, expected) => {
        expect(isTemporaryId(id)).toBe(expected)
    })
})

describe('PendingIds.resolve', () => {
    it('passes a real id straight through without waiting', async () => {
        const pending = new PendingIds()

        await expect(pending.resolve(17)).resolves.toBe(17)
    })

    /**
     * The defect. `PATCH /api/v1/sets/temp-3` and `workout_line_id: "temp-1"`
     * were both seen in production traffic; the first 404s, the second 422s.
     */
    it('holds a placeholder until the creation lands, then yields the real id', async () => {
        const pending = new PendingIds()
        const creation = deferred()
        pending.track('temp-1', creation.promise)

        let resolved = false
        const waiting = pending.resolve('temp-1').then((id) => {
            resolved = true

            return id
        })

        expect(resolved).toBe(false)

        creation.resolve(19)

        await expect(waiting).resolves.toBe(19)
    })

    it('answers instantly once the creation has already landed', async () => {
        const pending = new PendingIds()
        pending.track('temp-1', Promise.resolve(19))

        await pending.resolve('temp-1')

        expect(pending.isPending('temp-1')).toBe(false)
        await expect(pending.resolve('temp-1')).resolves.toBe(19)
    })

    /**
     * Queued offline, the creation never returns an id. Callers must be told to
     * send nothing rather than enqueue a payload carrying the placeholder — that
     * payload is what cost workout 8 every one of its sets when the queue drained.
     */
    it('yields null when the creation was queued instead of performed', async () => {
        const pending = new PendingIds()
        pending.track('temp-1', Promise.resolve(null))

        await expect(pending.resolve('temp-1')).resolves.toBeNull()
    })

    it('yields null when the creation was refused', async () => {
        const pending = new PendingIds()
        pending.track('temp-1', Promise.reject(new Error('422')))

        await expect(pending.resolve('temp-1')).resolves.toBeNull()
    })

    it('yields null for a placeholder nobody ever tried to create', async () => {
        const pending = new PendingIds()

        await expect(pending.resolve('temp-99')).resolves.toBeNull()
    })

    it('lets several waiters share one creation', async () => {
        const pending = new PendingIds()
        const creation = deferred()
        pending.track('temp-1', creation.promise)

        const waiters = Promise.all([pending.resolve('temp-1'), pending.resolve('temp-1'), pending.resolve('temp-1')])

        creation.resolve(7)

        await expect(waiters).resolves.toEqual([7, 7, 7])
    })

    it('forgets a placeholder on request', async () => {
        const pending = new PendingIds()
        pending.track('temp-1', Promise.resolve(19))
        await pending.resolve('temp-1')

        pending.forget('temp-1')

        await expect(pending.resolve('temp-1')).resolves.toBeNull()
    })
})
