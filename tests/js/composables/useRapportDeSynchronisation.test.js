import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

const sync = vi.hoisted(() => ({ failedRequests: vi.fn(() => []), clearFailedRequests: vi.fn() }))
const haptics = vi.hoisted(() => ({ triggerHaptic: vi.fn() }))

vi.mock('@/Utils/SyncService', () => ({ default: sync }))
vi.mock('@/composables/useHaptics', () => haptics)

import { useRapportDeSynchronisation } from '@/composables/useRapportDeSynchronisation'

const monter = (surcharges = {}) => {
    const page = { props: {} }
    const rapport = useRapportDeSynchronisation({
        page,
        exercices: () => [{ id: 3, name: 'Développé couché' }],
        lignes: () => [
            { id: 7, exercise: { name: 'Squat' }, sets: [{}, {}] },
            { id: 8, sets: [] },
        ],
        ...surcharges,
    })

    return { page, ...rapport }
}

beforeEach(() => {
    vi.useFakeTimers()
    sync.failedRequests.mockReturnValue([])
})

afterEach(() => {
    vi.useRealTimers()
    vi.clearAllMocks()
})

describe('les séries non synchronisées', () => {
    it('se marquent par leur identifiant en chaîne, et se libèrent plusieurs à la fois', () => {
        const { unsyncedSetIds, markUnsynced, clearUnsynced } = monter()

        markUnsynced(12)
        markUnsynced('temp-3')
        expect(unsyncedSetIds.value.has('12')).toBe(true)

        clearUnsynced(12, 'temp-3')
        expect(unsyncedSetIds.value.size).toBe(0)
    })
})

describe('les deux canaux du rapport', () => {
    it('emprunte le toast de la mise en page, même quand la page n’a pas encore de flash', () => {
        const { page, reportSyncFailure } = monter()

        reportSyncFailure('Réessaie.')

        expect(page.props.flash.error).toBe('Réessaie.')
        expect(haptics.triggerHaptic).toHaveBeenCalledWith('error')
    })

    it('pose le message d’une correction refusée six secondes, et repart de zéro à chaque nouveau message', () => {
        const { editError, reportEditFailure } = monter()

        reportEditFailure('Premier')
        vi.advanceTimersByTime(5000)
        reportEditFailure('Second')
        vi.advanceTimersByTime(5000)
        expect(editError.value).toBe('Second')

        vi.advanceTimersByTime(1000)
        expect(editError.value).toBeNull()
    })
})

describe('ce que dit la file hors ligne', () => {
    it('accorde le message d’expiration de session au nombre de modifications', () => {
        const { editError, handleSyncAuthRequired } = monter()

        handleSyncAuthRequired({ detail: { pending: 1 } })
        expect(editError.value).toContain('ta modification en attente repartira')

        handleSyncAuthRequired({ detail: { pending: 3 } })
        expect(editError.value).toContain('tes 3 modifications en attente repartiront')
    })

    it('accorde de même le message de stockage plein', () => {
        const { editError, handleSyncStorageFull } = monter()

        handleSyncStorageFull({ detail: { pending: 2 } })
        expect(editError.value).toBe(
            'Le stockage du téléphone est plein : 2 modifications en attente ne survivront pas à un rechargement.',
        )

        handleSyncStorageFull({})
        expect(editError.value).toContain('0 modification en attente ne survivra')
    })
})

describe('un refus du serveur', () => {
    it('marque la série quand l’adresse en porte une', () => {
        const { unsyncedSetIds, editError, handleSyncFailure } = monter()

        handleSyncFailure({ detail: { url: '/api/v1/sets/42' } })

        expect(unsyncedSetIds.value.has('42')).toBe(true)
        expect(editError.value).toBeNull()
    })

    it('nomme la série et son exercice pour une création refusée', () => {
        const { editError, handleSyncFailure } = monter()

        handleSyncFailure({ detail: { url: '/api/v1/sets', data: JSON.stringify({ workout_line_id: 7 }) } })

        expect(editError.value).toBe('La série 2 de « Squat » n’a pas pu être enregistrée.'.replace('’', "'"))
    })

    it('retombe sur la première série d’une ligne vide, et sur la formule vague sans ligne', () => {
        const { describeFailedCreate } = monter()

        expect(describeFailedCreate('/api/v1/sets', { workout_line_id: 8 })).toContain('La série 1 de « cet exercice »')
        expect(describeFailedCreate('/api/v1/sets?x=1', { workout_line_id: 99 })).toBe(
            "Un élément de la séance n'a pas pu être enregistré.",
        )
        expect(describeFailedCreate('/api/v1/sets', '{pas du json')).toBe(
            "Un élément de la séance n'a pas pu être enregistré.",
        )
    })

    it('nomme l’exercice qui n’a pas pu être ajouté, ou le dit sans nom', () => {
        const { describeFailedCreate } = monter()

        expect(describeFailedCreate('/api/v1/workout-lines', { exercise_id: 3 })).toBe(
            "« Développé couché » n'a pas pu être ajouté à la séance.",
        )
        expect(describeFailedCreate('/api/v1/workout-lines', null)).toBe("Un exercice n'a pas pu être ajouté.")
    })

    it('ne dit rien d’une adresse qui ne concerne ni série ni ligne', () => {
        const { editError, unsyncedSetIds, handleSyncFailure } = monter()

        handleSyncFailure({ detail: { url: '/api/v1/workouts/5/line-order' } })
        handleSyncFailure({})

        expect(editError.value).toBeNull()
        expect(unsyncedSetIds.value.size).toBe(0)
    })
})

describe('les refus survenus pendant l’absence de la page', () => {
    it('ne touche pas au seau quand il est vide', () => {
        const { markQueuedFailuresOnMount } = monter()

        markQueuedFailuresOnMount()

        expect(sync.clearFailedRequests).not.toHaveBeenCalled()
    })

    it('les annonce une fois, puis vide le seau', () => {
        sync.failedRequests.mockReturnValue([
            { url: '/api/v1/sets/9', data: null },
            { url: '/api/v1/workout-lines', data: { exercise_id: 3 } },
        ])
        const { unsyncedSetIds, editError, markQueuedFailuresOnMount } = monter()

        markQueuedFailuresOnMount()

        expect(unsyncedSetIds.value.has('9')).toBe(true)
        expect(editError.value).toContain('Développé couché')
        expect(sync.clearFailedRequests).toHaveBeenCalledTimes(1)
    })
})
