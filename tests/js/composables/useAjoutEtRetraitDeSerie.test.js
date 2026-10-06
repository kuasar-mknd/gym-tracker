import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { flushPromises } from '@vue/test-utils'

const sync = vi.hoisted(() => ({ post: vi.fn(), patch: vi.fn() }))
vi.mock('@/Utils/SyncService', () => ({ default: sync }))

import { useAjoutEtRetraitDeSerie } from '@/composables/useAjoutEtRetraitDeSerie'
import { createWriteQueue } from '@/Utils/writeOrdering'
import { PendingIds } from '@/Utils/pendingIds'

const reponse = (data) => ({ data: { data } })

const BORNES = { weight: 100000, reps: 999, distance_km: 1000, duration_seconds: 86400 }

const monter = ({ bornesDUneSerie = null } = {}) => {
    const ligne = { id: 1, _rowKey: 'row-1', exercise: { type: 'strength' }, sets: [], recommended_values: null }
    const localWorkout = ref({ id: 5, workout_lines: [ligne] })
    const pendingIds = new PendingIds()
    const fieldWrites = createWriteQueue()
    const oublierLesRafales = vi.fn()
    const deleteSet = vi.fn(() => Promise.resolve())
    const reportSyncFailure = vi.fn()
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
        reportSyncFailure,
        bornesDUneSerie,
    })

    return { series, ligne: () => localWorkout.value.workout_lines[0], oublierLesRafales, deleteSet, reportSyncFailure }
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

    /*
     * Une série enregistrée avant les plafonds d'une série peut les dépasser.
     * Recopiée telle quelle, chaque ajout était refusé, et l'exercice ne
     * pouvait plus recevoir de série.
     */
    it('ramène sous les plafonds la dernière série qu’elle recopie', async () => {
        const { series, ligne } = monter({ bornesDUneSerie: BORNES })
        ligne().sets = [{ id: 40, weight: 150000, reps: 1500, is_completed: true }]
        sync.post.mockResolvedValue(reponse({ id: 41, created_at: 'c', updated_at: 'u', personal_record: null }))

        series.addSet(1)
        await flushPromises()

        expect(sync.post).toHaveBeenCalledWith(expect.any(String), {
            workout_line_id: 1,
            is_completed: false,
            weight: 100000,
            reps: 999,
        })
        expect(ligne().sets.at(-1)).toMatchObject({ id: 41, weight: 100000, reps: 999 })
        expect(ligne().sets[0]).toMatchObject({ weight: 150000, reps: 1500 })
    })

    it('ramène sous les plafonds la recommandation qu’elle reprend, et laisse une valeur dans les bornes telle quelle', async () => {
        const { series, ligne } = monter({ bornesDUneSerie: BORNES })
        ligne().recommended_values = { weight: 62.5, reps: 1500, distance_km: 0, duration_seconds: 30 }
        sync.post.mockResolvedValue(reponse({ id: 42, created_at: 'c', updated_at: 'u', personal_record: null }))

        series.addSet(1)
        await flushPromises()

        expect(sync.post).toHaveBeenCalledWith(expect.any(String), {
            workout_line_id: 1,
            is_completed: false,
            weight: 62.5,
            reps: 999,
        })
    })

    it('cite la raison d’un refus qui nomme un champ, au lieu d’inviter à réessayer', async () => {
        const { series, ligne, reportSyncFailure } = monter()
        sync.post.mockRejectedValueOnce({
            isOffline: false,
            response: { status: 422, data: { errors: { reps: ['Une série compte au plus 999 répétitions.'] } } },
        })

        series.addSet(1)
        await flushPromises()

        expect(ligne().sets).toEqual([])
        expect(reportSyncFailure).toHaveBeenCalledWith(
            'La série n’a pas pu être ajoutée. Une série compte au plus 999 répétitions.',
        )

        sync.post.mockRejectedValueOnce({ isOffline: false, response: { status: 500, data: {} } })
        series.addSet(1)
        await flushPromises()

        expect(reportSyncFailure).toHaveBeenLastCalledWith('La série n’a pas pu être ajoutée. Réessaie.')
    })
})
