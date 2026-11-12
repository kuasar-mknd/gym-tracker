import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

const sync = vi.hoisted(() => ({
    failedRequests: vi.fn(() => []),
    clearFailedRequests: vi.fn(),
    oublierDesRefus: vi.fn(),
    enAttente: vi.fn(() => 0),
}))
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
    it('garde le message de session expirée jusqu’à sa fermeture, avec le lien de reconnexion', () => {
        const { erreurDeSaisie, handleSyncAuthRequired } = monter()

        handleSyncAuthRequired({ detail: { pending: 2 } })
        vi.advanceTimersByTime(60000)

        expect(erreurDeSaisie.value).toMatchObject({ persistant: true, reconnexion: true })
        expect(erreurDeSaisie.value.message).toContain('Ta session a expiré')

        erreurDeSaisie.value.onFermer()

        expect(erreurDeSaisie.value).toMatchObject({ message: null, persistant: false, reconnexion: false })
    })

    it('garde le message de stockage plein jusqu’à sa fermeture, sans lien de reconnexion', () => {
        const { editError, erreurDeSaisie, handleSyncStorageFull } = monter()

        handleSyncStorageFull({ detail: { pending: 1 } })
        vi.advanceTimersByTime(60000)

        expect(editError.value).toContain('stockage du téléphone est plein')
        expect(erreurDeSaisie.value).toMatchObject({ persistant: true, reconnexion: false })
    })

    it('ne laisse pas un refus ordinaire remplacer un message persistant, et le montre à la fermeture', () => {
        const { editError, erreurDeSaisie, handleSyncStorageFull, handleSyncFailure } = monter()

        handleSyncStorageFull({ detail: { pending: 1 } })
        handleSyncFailure({ detail: { url: '/api/v1/workout-lines', data: { exercise_id: 3 } } })
        vi.advanceTimersByTime(60000)

        expect(editError.value).toContain('stockage du téléphone est plein')

        erreurDeSaisie.value.onFermer()

        expect(editError.value).toBe("« Développé couché » n'a pas pu être ajouté à la séance.")

        vi.advanceTimersByTime(6000)

        expect(editError.value).toBeNull()
    })

    it('dit ensemble la session expirée et le stockage plein, avec le lien de connexion', () => {
        const { erreurDeSaisie, handleSyncAuthRequired, handleSyncStorageFull } = monter()

        handleSyncStorageFull({ detail: { pending: 2 } })
        handleSyncAuthRequired({ detail: { pending: 2 } })

        expect(erreurDeSaisie.value.message).toContain('session a expiré')
        expect(erreurDeSaisie.value.message).toContain('ne survivront pas à un rechargement')
        expect(erreurDeSaisie.value.message).toContain('Reconnecte-toi sans recharger la page')
        expect(erreurDeSaisie.value.reconnexion).toBe(true)
        expect(erreurDeSaisie.value.persistant).toBe(true)
    })

    it('dit de même la session expirée suivie du stockage plein', () => {
        const { erreurDeSaisie, handleSyncAuthRequired, handleSyncStorageFull } = monter()

        handleSyncAuthRequired({ detail: { pending: 1 } })
        handleSyncStorageFull({ detail: { pending: 1 } })

        expect(erreurDeSaisie.value.message).toContain('session a expiré')
        expect(erreurDeSaisie.value.message).toContain('stockage du téléphone est plein')
        expect(erreurDeSaisie.value.reconnexion).toBe(true)
    })

    it('un message persistant remplace un refus ordinaire et son minuteur ne l’efface pas', () => {
        const { editError, handleSyncStorageFull, reportEditFailure } = monter()

        reportEditFailure('Refus ordinaire')
        handleSyncStorageFull({ detail: { pending: 1 } })
        vi.advanceTimersByTime(60000)

        expect(editError.value).toContain('stockage du téléphone est plein')
    })

    it('accorde le message d’expiration de session au nombre de modifications', () => {
        const { editError, handleSyncAuthRequired } = monter()

        handleSyncAuthRequired({ detail: { pending: 1 } })
        expect(editError.value).toContain('ta modification en attente repartira')

        handleSyncAuthRequired({ detail: { pending: 3 } })
        expect(editError.value).toContain('tes 3 modifications en attente repartiront')
    })

    it('ne promet pas le départ d’une écriture que la troisième porte fermée vient de refuser', () => {
        const { editError, handleSyncAuthRequired } = monter()

        handleSyncAuthRequired({ detail: { pending: 1, refusee: true } })

        expect(editError.value).toBe(
            "Ta session a expiré : reconnecte-toi. Une modification n'a pas pu être envoyée et a été écartée.",
        )
        expect(editError.value).not.toContain('repartira')

        handleSyncAuthRequired({ detail: { pending: 3, refusee: false } })

        expect(editError.value).toContain("Une modification n'a pas pu être envoyée et a été écartée")
        expect(editError.value).toContain('les 3 autres modifications en attente repartiront ensuite')

        handleSyncAuthRequired({ detail: { pending: 2, refusee: true } })

        expect(editError.value).toContain("2 modifications n'ont pas pu être envoyées et ont été écartées")
        expect(editError.value).toContain("l'autre modification en attente repartira ensuite")
    })

    it('garde dans le message combiné le compte des écritures écartées', () => {
        const { editError, handleSyncAuthRequired, handleSyncStorageFull } = monter()

        handleSyncStorageFull({ detail: { pending: 2 } })
        handleSyncAuthRequired({ detail: { pending: 2, refusee: true } })

        expect(editError.value).toContain('Reconnecte-toi sans recharger la page. 1 modification a été écartée.')
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

describe('quand la file se vide', () => {
    it('ferme le message de session expirée une fois plus rien n’attend, et seulement alors', () => {
        const { editError, erreurDeSaisie, handleSyncAuthRequired, handleSyncReplayed } = monter()

        handleSyncAuthRequired({ detail: { pending: 2 } })

        sync.enAttente.mockReturnValue(1)
        handleSyncReplayed()
        expect(editError.value).toContain('Ta session a expiré')

        sync.enAttente.mockReturnValue(0)
        handleSyncReplayed()
        expect(erreurDeSaisie.value).toMatchObject({ message: null, persistant: false, reconnexion: false })
    })

    it('ferme de même le stockage plein, puis laisse paraître le refus mis de côté', () => {
        const { editError, handleSyncStorageFull, handleSyncFailure, handleSyncReplayed } = monter()

        handleSyncStorageFull({ detail: { pending: 1 } })
        handleSyncFailure({ detail: { url: '/api/v1/workout-lines', data: { exercise_id: 3 } } })

        handleSyncReplayed()

        expect(editError.value).toBe("« Développé couché » n'a pas pu être ajouté à la séance.")
    })

    it('ne touche pas à un refus ordinaire à l’écran', () => {
        const { editError, reportEditFailure, handleSyncReplayed } = monter()

        reportEditFailure('Refus ordinaire')
        handleSyncReplayed()

        expect(editError.value).toBe('Refus ordinaire')
    })

    it('oublie la session expirée fermée : le stockage plein qui suit ne la ressuscite pas', () => {
        const { editError, handleSyncAuthRequired, handleSyncStorageFull, handleSyncReplayed } = monter()

        handleSyncAuthRequired({ detail: { pending: 1 } })
        handleSyncReplayed()
        handleSyncStorageFull({ detail: { pending: 1 } })

        expect(editError.value).not.toContain('session')
    })
})

describe('les refus mis de côté', () => {
    const refusDeLigne = (rapport) =>
        rapport.handleSyncFailure({ detail: { url: '/api/v1/sets', data: { workout_line_id: 7 } } })

    it('vibrent quand ils s’affichent, pas quand ils attendent', () => {
        const rapport = monter()

        rapport.handleSyncStorageFull({ detail: { pending: 1 } })
        haptics.triggerHaptic.mockClear()

        refusDeLigne(rapport)
        expect(haptics.triggerHaptic).not.toHaveBeenCalled()

        rapport.fermerEditError()
        expect(haptics.triggerHaptic).toHaveBeenCalledTimes(1)
        expect(haptics.triggerHaptic).toHaveBeenCalledWith('error')
    })

    it('ne fondent pas deux refus qui se disent pareil : ils se comptent', () => {
        const rapport = monter()

        rapport.handleSyncStorageFull({ detail: { pending: 1 } })
        refusDeLigne(rapport)
        refusDeLigne(rapport)
        rapport.handleSyncFailure({ detail: { url: '/api/v1/workout-lines', data: { exercise_id: 3 } } })
        rapport.fermerEditError()

        expect(rapport.editError.value).toBe(
            "3 refus : La série 2 de « Squat » n'a pas pu être enregistrée. (×2) « Développé couché » n'a pas pu être ajouté à la séance.",
        )
    })

    it('laissent à la liste le temps d’être lue', () => {
        const rapport = monter()

        rapport.handleSyncStorageFull({ detail: { pending: 1 } })
        refusDeLigne(rapport)
        refusDeLigne(rapport)
        rapport.fermerEditError()

        vi.advanceTimersByTime(6000)
        expect(rapport.editError.value).not.toBeNull()

        vi.advanceTimersByTime(3000)
        expect(rapport.editError.value).toBeNull()
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

    /**
     * La séance démarrée sans réseau que le serveur a refusée emporte ses
     * écritures (#2049) : le layout dit ce refus, une fois ; la série refusée
     * faute de parent ne dit rien d'elle-même.
     */
    it('ne dit rien d’une écriture refusée avec la séance qui l’emporte', () => {
        const { editError, unsyncedSetIds, handleSyncFailure } = monter()

        handleSyncFailure({
            detail: { url: '/api/v1/sets', data: { workout_line_id: 7 }, parentRefuse: true, parent: 'q1' },
        })
        handleSyncFailure({ detail: { url: '/api/v1/sets/9', parentRefuse: true, parent: 'q1' } })

        expect(editError.value).toBeNull()
        expect(unsyncedSetIds.value.size).toBe(0)
    })
})

describe('les refus survenus pendant l’absence de la page', () => {
    it('ne touche pas au seau quand il est vide', () => {
        const { markQueuedFailuresOnMount } = monter()

        markQueuedFailuresOnMount()

        expect(sync.clearFailedRequests).not.toHaveBeenCalled()
        expect(sync.oublierDesRefus).not.toHaveBeenCalled()
    })

    /**
     * Elle vidait tout le seau du compte (#2049) : le refus d'une séance
     * démarrée sans réseau, que le layout annonce, disparaissait avec. Elle
     * n'oublie plus que ce qu'elle a dit.
     */
    it('les annonce une fois, puis oublie ceux-là seulement', () => {
        sync.failedRequests.mockReturnValue([
            { id: 'q9', method: 'patch', url: '/api/v1/sets/9', data: null },
            { id: 'q3', method: 'post', url: '/api/v1/workout-lines', data: { exercise_id: 3 } },
            { id: 'qS', method: 'post', url: '/api/v1/workouts', data: { started_at: '2026-10-19T08:15:00.000Z' } },
            {
                id: 'qL',
                method: 'post',
                url: '/api/v1/workout-lines',
                data: { exercise_id: 3, workout_id: { enAttenteDe: 'qS' } },
                parentRefuse: true,
                parent: 'qS',
            },
        ])
        const { unsyncedSetIds, editError, markQueuedFailuresOnMount } = monter()

        markQueuedFailuresOnMount()

        expect(unsyncedSetIds.value.has('9')).toBe(true)
        expect(editError.value).toContain('Développé couché')
        expect(sync.oublierDesRefus).toHaveBeenCalledTimes(1)
        expect(sync.oublierDesRefus).toHaveBeenCalledWith(['q9', 'q3'])
        expect(sync.clearFailedRequests).not.toHaveBeenCalled()
    })
})
