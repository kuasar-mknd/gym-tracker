import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { flushPromises } from '@vue/test-utils'

const sync = vi.hoisted(() => ({ post: vi.fn(), patch: vi.fn() }))
vi.mock('@/Utils/SyncService', () => ({ default: sync }))

import { useAjoutEtRetraitDeSerie } from '@/composables/useAjoutEtRetraitDeSerie'
import { createWriteQueue } from '@/Utils/writeOrdering'
import { PendingIds } from '@/Utils/pendingIds'

const reponse = (data) => ({ data: { data } })

const monter = () => {
    const ligne = { id: 1, _rowKey: 'row-1', exercise: { type: 'strength' }, sets: [], recommended_values: null }
    const localWorkout = ref({ id: 5, workout_lines: [ligne] })
    const pendingIds = new PendingIds()
    const fieldWrites = createWriteQueue()
    const oublierLesRafales = vi.fn()
    const deleteSet = vi.fn(() => Promise.resolve())
    let compteur = 0

    const series = useAjoutEtRetraitDeSerie({
        localWorkout,
        pendingIds,
        queuedLineIds: new Set(),
        nouvelIdTemporaire: () => `temp-${++compteur}`,
        newRowKey: () => `row-${10 + compteur}`,
        rowKey: (rangee) => rangee._rowKey ?? rangee.id,
        fieldWrites,
        deleteSet,
        oublierLesRafales,
        oublierLaSerie: vi.fn(),
        markUnsynced: vi.fn(),
        clearUnsynced: vi.fn(),
        reportSyncFailure: vi.fn(),
    })

    return { series, ligne: () => localWorkout.value.workout_lines[0], oublierLesRafales, deleteSet }
}

beforeEach(() => {
    vi.clearAllMocks()
    globalThis.route = (nom, params = {}) => `/${nom}/${Object.values(params).join('/')}`
})

describe('l’ajout et le retrait d’une série', () => {
    it('fait attendre le second ajout derrière le premier, sauf si la ligne a été oubliée', async () => {
        const { series, ligne } = monter()
        let premiereCreation
        sync.post.mockImplementationOnce(
            () =>
                new Promise((r) => {
                    premiereCreation = r
                }),
        )
        sync.post.mockResolvedValue(reponse({ id: 22, created_at: 'c', updated_at: 'u', personal_record: null }))

        series.addSet(1)
        series.addSet(1)
        await flushPromises()
        expect(sync.post).toHaveBeenCalledTimes(1)

        series.oublierLesEcrituresDeLaLigne(ligne())
        series.addSet(1)
        await flushPromises()
        expect(sync.post).toHaveBeenCalledTimes(2)

        premiereCreation(reponse({ id: 21, created_at: 'c', updated_at: 'u', personal_record: null }))
        await flushPromises()
        expect(sync.post).toHaveBeenCalledTimes(3)
        expect(ligne().sets.map((s) => s.id)).toEqual([21, 22, 22])
    })

    it('retire la série en oubliant ses rafales et sa file, et la remet à sa place si le serveur refuse', async () => {
        const { series, ligne, oublierLesRafales, deleteSet } = monter()
        ligne().sets = [{ id: 31 }, { id: 32 }, { id: 33 }]
        deleteSet.mockRejectedValueOnce({ isOffline: false })

        series.removeSet(32)
        expect(ligne().sets.map((s) => s.id)).toEqual([31, 33])
        expect(oublierLesRafales).toHaveBeenCalledWith(32)

        await flushPromises()
        expect(ligne().sets.map((s) => s.id)).toEqual([31, 32, 33])

        deleteSet.mockRejectedValueOnce({ isOffline: true })
        series.removeSet(33)
        await flushPromises()
        expect(ligne().sets.map((s) => s.id)).toEqual([31, 32])
    })
})
