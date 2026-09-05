import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref } from 'vue'
import { flushPromises } from '@vue/test-utils'

const sync = vi.hoisted(() => ({ post: vi.fn(), patch: vi.fn(), delete: vi.fn() }))
vi.mock('@/Utils/SyncService', () => ({ default: sync }))

import { useSeriesDeLaSeance } from '@/composables/useSeriesDeLaSeance'
import { createWriteQueue, createWriteSequencer } from '@/Utils/writeOrdering'
import { PendingIds } from '@/Utils/pendingIds'

const reponse = (data) => ({ data: { data } })

const ligneDe = (type = 'strength', extra = {}) => ({
    id: 1,
    _rowKey: 'row-1',
    exercise: { type },
    sets: [],
    recommended_values: null,
    ...extra,
})

const monter = (ligne = ligneDe()) => {
    const localWorkout = ref({ id: 5, workout_lines: [ligne] })
    const pendingIds = new PendingIds()
    const { next: nextWrite, isLatest: isLatestWrite } = createWriteSequencer()
    const rapport = {
        markUnsynced: vi.fn(),
        clearUnsynced: vi.fn(),
        reportSyncFailure: vi.fn(),
        reportEditFailure: vi.fn(),
    }
    const brouillons = {
        lastConfirmed: vi.fn((set, field, repli) => repli),
        rememberConfirmed: vi.fn(),
        writeDraftField: vi.fn(),
        clearDraftField: vi.fn(),
        oublierLaSerie: vi.fn(),
    }
    const apresValidation = vi.fn()
    let compteur = 0
    let clefs = 10

    const series = useSeriesDeLaSeance({
        localWorkout,
        pendingIds,
        queuedLineIds: new Set(),
        nouvelIdTemporaire: () => `temp-${++compteur}`,
        newRowKey: () => `row-${++clefs}`,
        rowKey: (rangee) => rangee._rowKey ?? rangee.id,
        nextWrite,
        isLatestWrite,
        fieldWrites: createWriteQueue(),
        ...brouillons,
        ...rapport,
        apresValidation,
    })

    return {
        series,
        localWorkout,
        pendingIds,
        rapport,
        brouillons,
        apresValidation,
        ligne: () => localWorkout.value.workout_lines[0],
    }
}

beforeEach(() => {
    vi.clearAllMocks()
    globalThis.route = (nom, params = {}) => `/${nom}/${Object.values(params).join('/')}`
})

afterEach(() => {
    vi.useRealTimers()
})

describe('ajouter une série', () => {
    it('ne pré-remplit que ce que l’exercice mesure, et prend l’identifiant que le serveur rend', async () => {
        sync.post.mockResolvedValue(reponse({ id: 42, created_at: 'c', updated_at: 'u', personal_record: null }))
        const { series, ligne, rapport } = monter(ligneDe('cardio'))

        series.addSet(1)
        const serie = ligne().sets[0]
        expect(serie).toMatchObject({ id: 'temp-1', distance_km: 0, duration_seconds: 30, is_completed: false })
        expect(serie).not.toHaveProperty('weight')

        await flushPromises()

        expect(sync.post).toHaveBeenCalledWith('/api.v1.sets.store/', {
            workout_line_id: 1,
            is_completed: false,
            distance_km: 0,
            duration_seconds: 30,
        })
        expect(serie.id).toBe(42)
        expect(rapport.clearUnsynced).toHaveBeenCalledWith('temp-1', 42)
    })

    it('copie la dernière série, et envoie ce qui a été tapé pendant que la création volait', async () => {
        let repondre
        sync.post.mockReturnValue(new Promise((resolve) => (repondre = resolve)))
        sync.patch.mockResolvedValue(reponse({}))
        const { series, ligne } = monter(ligneDe('strength', { sets: [{ id: 3, weight: 80, reps: 5 }] }))

        series.addSet(1)
        const serie = ligne().sets[1]
        expect(serie).toMatchObject({ weight: 80, reps: 5 })

        // La charge utile est partie avec 80 ; la correction arrive pendant le vol.
        await flushPromises()
        expect(sync.post).toHaveBeenCalledTimes(1)
        serie.weight = 85
        repondre(reponse({ id: 43 }))
        await flushPromises()

        expect(sync.patch).toHaveBeenCalledWith('/api.v1.sets.update/43', { weight: 85 })
    })

    it('reprend la recommandation arrivée avec la ligne pour une série encore intacte', async () => {
        let ligneCreee
        const creationDeLaLigne = new Promise((resolve) => (ligneCreee = resolve))
        const { series, ligne, pendingIds } = monter(ligneDe('strength', { id: 'temp-L' }))
        pendingIds.track('temp-L', creationDeLaLigne)
        sync.post.mockResolvedValue(reponse({ id: 44 }))

        series.addSet('temp-L')
        ligne().recommended_values = { weight: 110, reps: 3 }
        ligneCreee(7)
        await flushPromises()

        expect(ligne().sets[0]).toMatchObject({ id: 44, weight: 110, reps: 3 })
        expect(sync.post).toHaveBeenCalledWith('/api.v1.sets.store/', {
            workout_line_id: 7,
            is_completed: false,
            weight: 110,
            reps: 3,
        })
    })

    it('retire la série et le dit quand le serveur refuse ; la garde quand la file l’a prise', async () => {
        sync.post.mockRejectedValueOnce({ isOffline: false })
        const { series, ligne, rapport } = monter()

        series.addSet(1)
        await flushPromises()

        expect(ligne().sets).toEqual([])
        expect(rapport.reportSyncFailure).toHaveBeenCalledWith('La série n’a pas pu être ajoutée. Réessaie.')

        sync.post.mockRejectedValueOnce({ isOffline: true })
        series.addSet(1)
        await flushPromises()

        expect(ligne().sets).toHaveLength(1)
        expect(rapport.markUnsynced).toHaveBeenCalledWith('temp-2')
    })

    it('fait attendre le second ajout derrière le premier', async () => {
        let repondre
        sync.post
            .mockReturnValueOnce(new Promise((resolve) => (repondre = resolve)))
            .mockResolvedValueOnce(reponse({ id: 46 }))
        const { series } = monter()

        series.addSet(1)
        series.addSet(1)
        await flushPromises()
        expect(sync.post).toHaveBeenCalledTimes(1)

        repondre(reponse({ id: 45 }))
        await flushPromises()
        expect(sync.post).toHaveBeenCalledTimes(2)
    })
})

describe('saisir une valeur', () => {
    it('fond une rafale en une seule écriture après le debounce, et retient la valeur confirmée', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
        sync.patch.mockResolvedValue(reponse({ weight: 80, updated_at: 'u2' }))
        const serie = { id: 9, weight: 50, reps: 5 }
        const { series, brouillons } = monter(ligneDe('strength', { sets: [serie] }))

        series.updateSet(serie, 'weight', '8')
        series.updateSet(serie, 'weight', '80')
        expect(serie.weight).toBe(80)
        expect(sync.patch).not.toHaveBeenCalled()

        await vi.advanceTimersByTimeAsync(1000)
        await flushPromises()

        expect(sync.patch).toHaveBeenCalledTimes(1)
        expect(sync.patch).toHaveBeenCalledWith('/api.v1.sets.update/9', { weight: 80 })
        expect(brouillons.writeDraftField).toHaveBeenLastCalledWith(9, 'weight', 80)
        expect(brouillons.rememberConfirmed).toHaveBeenCalledWith(9, 'weight', 80)
        expect(brouillons.clearDraftField).toHaveBeenCalledWith(9, 'weight')
        expect(serie.updated_at).toBe('u2')
    })

    it('rétablit la valeur d’avant la rafale quand le serveur refuse, et le dit', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
        sync.patch.mockRejectedValue({ response: { status: 422 } })
        const serie = { id: 9, weight: 50, reps: 5 }
        const { series, rapport } = monter(ligneDe('strength', { sets: [serie] }))

        series.updateSet(serie, 'weight', '9')
        series.updateSet(serie, 'weight', '99')
        await vi.advanceTimersByTimeAsync(1000)
        await flushPromises()

        expect(serie.weight).toBe(50)
        expect(rapport.reportEditFailure).toHaveBeenCalledWith(
            'Cette valeur a été refusée. La précédente est rétablie.',
        )
    })

    it('n’écrit rien pour une série provisoire ni pour une valeur qui n’en est pas une', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
        const provisoire = { id: 'temp-1', weight: 0, reps: 10 }
        const { series } = monter(ligneDe('strength', { sets: [provisoire] }))

        series.updateSet(provisoire, 'weight', '70')
        series.updateSet(provisoire, 'reps', 'abc')
        await vi.advanceTimersByTimeAsync(1000)

        expect(provisoire).toMatchObject({ weight: 70, reps: 10 })
        expect(sync.patch).not.toHaveBeenCalled()
    })

    it('ignore une frappe vide, et un blur qui n’apporte rien de neuf', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
        const serie = { id: 9, weight: 50, reps: 5 }
        const { series } = monter(ligneDe('strength', { sets: [serie] }))

        series.saisieEnCours(serie, 'weight', '')
        series.saisieTerminee(serie, 'weight', '50')
        await vi.advanceTimersByTimeAsync(1000)

        expect(sync.patch).not.toHaveBeenCalled()
    })
})

describe('valider une série', () => {
    it('vide d’abord la saisie en attente, valide ensuite, puis lance le repos', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
        sync.patch
            .mockResolvedValueOnce(reponse({ weight: 60 }))
            .mockResolvedValueOnce(reponse({ is_completed: true, personal_record: { id: 1 }, updated_at: 'u3' }))
        const serie = { id: 9, weight: 50, reps: 5, is_completed: false }
        const { series, apresValidation } = monter(ligneDe('strength', { sets: [serie] }))

        series.updateSet(serie, 'weight', '60')
        series.toggleSetCompletion(serie, 120)
        expect(serie.is_completed).toBe(true)
        expect(apresValidation).toHaveBeenCalledWith(120)

        await flushPromises()

        expect(sync.patch.mock.calls.map(([, charge]) => charge)).toEqual([{ weight: 60 }, { is_completed: true }])
        expect(serie.personal_record).toEqual({ id: 1 })
    })

    it('rétablit la coche quand le serveur refuse, et le dit', async () => {
        sync.patch.mockRejectedValue({ isOffline: false })
        const serie = { id: 9, weight: 50, reps: 5, is_completed: false }
        const { series, rapport, apresValidation } = monter(ligneDe('strength', { sets: [serie] }))

        series.toggleSetCompletion(serie)
        await flushPromises()

        expect(serie.is_completed).toBe(false)
        expect(rapport.reportSyncFailure).toHaveBeenCalledWith('La série n’a pas pu être validée. Réessaie.')
        expect(apresValidation).toHaveBeenCalledTimes(1)
    })
})

describe('retirer une série', () => {
    it('la retire sur-le-champ et la remet à sa place si le serveur refuse', async () => {
        sync.delete.mockRejectedValue({ isOffline: false })
        const serie = { id: 9, weight: 50, reps: 5 }
        const { series, ligne, rapport, brouillons } = monter(
            ligneDe('strength', { sets: [{ id: 8 }, serie, { id: 10 }] }),
        )

        series.removeSet(9)
        expect(ligne().sets.map((s) => s.id)).toEqual([8, 10])
        expect(brouillons.oublierLaSerie).toHaveBeenCalledWith(9)

        await flushPromises()

        expect(ligne().sets.map((s) => s.id)).toEqual([8, 9, 10])
        expect(rapport.reportSyncFailure).toHaveBeenCalledWith('La série n’a pas pu être supprimée. Réessaie.')
    })

    it('oublie les rafales d’une ligne retirée, et vide tout ce qui attend quand on le lui demande', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
        sync.patch.mockResolvedValue(reponse({}))
        const gardee = { id: 9, weight: 50, reps: 5 }
        const retiree = { id: 11, weight: 50, reps: 5 }
        const { series } = monter(ligneDe('strength', { sets: [gardee] }))

        series.updateSet(retiree, 'weight', '70')
        series.oublierLesEcrituresDeLaLigne({ _rowKey: 'row-2', sets: [retiree] })
        series.updateSet(gardee, 'weight', '60')
        await series.flushAllPendingUpdates()
        await vi.advanceTimersByTimeAsync(1000)

        expect(sync.patch).toHaveBeenCalledTimes(1)
        expect(sync.patch).toHaveBeenCalledWith('/api.v1.sets.update/9', { weight: 60 })
    })
})
