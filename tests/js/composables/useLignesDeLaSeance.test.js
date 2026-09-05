import { describe, it, expect, vi, beforeEach } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'

const sync = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn() }))
vi.mock('@/Utils/SyncService', () => ({ default: sync }))

import { useLignesDeLaSeance } from '@/composables/useLignesDeLaSeance'
import { PendingIds } from '@/Utils/pendingIds'

const reponse = (data) => ({ data: { data } })

const monter = ({ lignes = [] } = {}) => {
    const pendingIds = new PendingIds()
    const queuedLineIds = new Set()
    const oublierLesEcrituresDeLaLigne = vi.fn()
    const reportSyncFailure = vi.fn()
    let compteur = 0
    let lignesRef
    let showAddExercise
    let composable

    const composant = defineComponent({
        setup() {
            const localWorkout = ref({ id: 5, workout_lines: lignes })
            lignesRef = localWorkout
            showAddExercise = ref(true)

            composable = useLignesDeLaSeance({
                localWorkout,
                pendingIds,
                queuedLineIds,
                nouvelIdTemporaire: () => `temp-${++compteur}`,
                newRowKey: () => `row-${compteur}`,
                localExercises: ref([{ id: 3, name: 'Squat', type: 'strength' }]),
                showAddExercise,
                oublierLesEcrituresDeLaLigne,
                reportSyncFailure,
            })

            return () => h('div')
        },
    })

    const wrapper = mount(composant)

    return {
        wrapper,
        lignes: () => lignesRef.value.workout_lines,
        showAddExercise: () => showAddExercise.value,
        pendingIds,
        queuedLineIds,
        oublierLesEcrituresDeLaLigne,
        reportSyncFailure,
        ...composable,
    }
}

const ligneDeSquat = () => ({ id: 10, exercise: { id: 3, name: 'Squat' }, sets: [] })

beforeEach(() => {
    vi.clearAllMocks()
    globalThis.route = (nom, params = {}) => `/${nom}/${Object.values(params).join('/')}`
})

describe('ajouter un exercice', () => {
    it('pose la ligne tout de suite, ferme le choix, puis prend ce que le serveur rend sans changer d’objet', async () => {
        sync.post.mockResolvedValue(
            reponse({ id: 7, order: 0, notes: null, recommended_values: { weight: 60 }, sets: [] }),
        )
        const page = monter()

        page.addExercise(3)
        const ligne = page.lignes()[0]
        expect(ligne).toMatchObject({ id: 'temp-1', _rowKey: 'row-1', exercise: { name: 'Squat' }, sets: [] })
        expect(page.showAddExercise()).toBe(false)

        await flushPromises()

        expect(sync.post).toHaveBeenCalledWith('/api.v1.workout-lines.store/', { workout_id: 5, exercise_id: 3 })
        expect(page.lignes()[0]).toBe(ligne)
        expect(ligne).toMatchObject({ id: 7, _rowKey: 'row-1', recommended_values: { weight: 60 } })
        await expect(page.pendingIds.resolve('temp-1')).resolves.toBe(7)
    })

    it('retire la ligne et le dit quand le serveur refuse', async () => {
        sync.post.mockRejectedValue({ isOffline: false })
        const page = monter()

        page.addExercise(3)
        await flushPromises()

        expect(page.lignes()).toEqual([])
        expect(page.reportSyncFailure).toHaveBeenCalledWith('L’exercice n’a pas pu être ajouté à la séance. Réessaie.')
    })

    it('garde une ligne partie dans la file, et lui donne son identifiant quand la file rejoue', async () => {
        sync.post.mockRejectedValue({ isOffline: true, queueId: 'q1' })
        const page = monter()

        page.addExercise(3)
        await flushPromises()

        expect(page.lignes()).toHaveLength(1)
        expect(page.queuedLineIds.has('temp-1')).toBe(true)

        const resolution = page.pendingIds.resolve('temp-1')
        window.dispatchEvent(new CustomEvent('sync:replayed', { detail: { queueId: 'autre', data: { id: 99 } } }))
        window.dispatchEvent(new CustomEvent('sync:replayed', { detail: { queueId: 'q1', data: { id: 8 } } }))

        await expect(resolution).resolves.toBe(8)
    })

    it('n’écoute plus la file une fois la page quittée', async () => {
        sync.post.mockRejectedValue({ isOffline: true, queueId: 'q1' })
        const retire = vi.spyOn(window, 'removeEventListener')
        const page = monter()

        page.addExercise(3)
        await flushPromises()
        page.wrapper.unmount()

        expect(retire).toHaveBeenCalledWith('sync:replayed', expect.any(Function))
        retire.mockRestore()
    })
})

describe('retirer un exercice', () => {
    it('pose la question d’abord, et ne retire rien tant qu’elle est ouverte', async () => {
        const page = monter({ lignes: [ligneDeSquat()] })

        page.removeLine(10)

        expect(page.retraitDemande.value).toBe(true)
        expect(page.titreDuRetrait.value).toBe('Supprimer Squat ?')
        expect(page.lignes()).toHaveLength(1)

        page.annulerLeRetrait()
        expect(page.retraitDemande.value).toBe(false)
        expect(sync.delete).not.toHaveBeenCalled()
    })

    it('retire la ligne sur-le-champ une fois confirmé, oublie ses écritures et prévient le serveur', async () => {
        sync.delete.mockResolvedValue({})
        const page = monter({ lignes: [ligneDeSquat()] })

        page.removeLine(10)
        page.confirmerLeRetrait()

        expect(page.retraitDemande.value).toBe(false)
        expect(page.lignes()).toEqual([])
        expect(page.oublierLesEcrituresDeLaLigne).toHaveBeenCalledWith(expect.objectContaining({ id: 10 }))

        await flushPromises()
        expect(sync.delete).toHaveBeenCalledWith('/api.v1.workout-lines.destroy/10')
    })

    it('la remet à sa place quand le serveur refuse, la laisse partie quand la file l’a prise', async () => {
        sync.delete.mockRejectedValueOnce({ isOffline: false })
        const page = monter({ lignes: [{ id: 9, exercise: { name: 'Fentes' }, sets: [] }, ligneDeSquat()] })

        page.removeLine(10)
        page.confirmerLeRetrait()
        await flushPromises()

        expect(page.lignes().map((l) => l.id)).toEqual([9, 10])
        expect(page.reportSyncFailure).toHaveBeenCalledWith('L’exercice n’a pas pu être retiré de la séance. Réessaie.')

        sync.delete.mockRejectedValueOnce({ isOffline: true })
        page.removeLine(10)
        page.confirmerLeRetrait()
        await flushPromises()

        expect(page.lignes().map((l) => l.id)).toEqual([9])
    })

    it('n’envoie rien pour une ligne que le serveur n’a jamais reçue', async () => {
        const page = monter({ lignes: [{ id: 'temp-4', exercise: { name: 'Squat' }, sets: [] }] })
        page.pendingIds.track('temp-4', Promise.resolve(null))

        page.removeLine('temp-4')
        page.confirmerLeRetrait()
        await flushPromises()

        expect(page.lignes()).toEqual([])
        expect(sync.delete).not.toHaveBeenCalled()
        expect(page.pendingIds.isPending('temp-4')).toBe(false)
    })
})
