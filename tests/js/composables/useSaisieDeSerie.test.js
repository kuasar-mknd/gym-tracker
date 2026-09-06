import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { useSaisieDeSerie } from '@/composables/useSaisieDeSerie'
import { createWriteQueue, createWriteSequencer } from '@/Utils/writeOrdering'

const monter = () => {
    const patchSet = vi.fn((set, payload) =>
        Promise.resolve({ data: { data: { ...set, ...payload, updated_at: 'now' } } }),
    )
    const { next: nextWrite, isLatest: isLatestWrite } = createWriteSequencer()
    const brouillons = {
        lastConfirmed: vi.fn((set, field, repli) => repli),
        rememberConfirmed: vi.fn(),
        writeDraftField: vi.fn(),
        clearDraftField: vi.fn(),
    }
    const reportEditFailure = vi.fn()

    const saisie = useSaisieDeSerie({
        patchSet,
        nextWrite,
        isLatestWrite,
        fieldWrites: createWriteQueue(),
        ...brouillons,
        reportEditFailure,
    })

    return { saisie, patchSet, brouillons, reportEditFailure }
}

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
})

afterEach(() => {
    vi.useRealTimers()
})

describe('la saisie d’une série', () => {
    it('oublie les rafales d’une série qui s’en va : rien ne part plus pour elle', async () => {
        const { saisie, patchSet } = monter()
        const set = { id: 7, weight: 60 }

        saisie.updateSet(set, 'weight', '80')
        saisie.oublierLesRafales(7)
        vi.advanceTimersByTime(1500)
        await flushPromises()

        expect(set.weight).toBe(80)
        expect(patchSet).not.toHaveBeenCalled()
    })

    it('vide tout ce qui attend, série par série ou d’un coup, et dit quand c’est parti', async () => {
        const { saisie, patchSet } = monter()
        const premiere = { id: 7, weight: 60, reps: 10 }
        const seconde = { id: 8, weight: 40, reps: 12 }

        saisie.updateSet(premiere, 'weight', '80')
        saisie.updateSet(seconde, 'reps', '15')

        await saisie.flushPendingUpdates(7)
        expect(patchSet).toHaveBeenCalledTimes(1)
        expect(patchSet).toHaveBeenCalledWith(premiere, { weight: 80 })

        await saisie.flushAllPendingUpdates()
        expect(patchSet).toHaveBeenCalledTimes(2)
        expect(patchSet).toHaveBeenLastCalledWith(seconde, { reps: 15 })

        vi.advanceTimersByTime(1500)
        await flushPromises()
        expect(patchSet).toHaveBeenCalledTimes(2)
    })
})
