import { describe, it, expect, vi, beforeEach } from 'vitest'
import { defineComponent, h, ref, computed } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'

const sync = vi.hoisted(() => ({ patch: vi.fn() }))
const haptics = vi.hoisted(() => ({ triggerHaptic: vi.fn() }))

vi.mock('@/Utils/SyncService', () => ({ default: sync }))
vi.mock('@/composables/useHaptics', () => haptics)
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

import { useOrdreDeLaSeance } from '@/composables/useOrdreDeLaSeance'
import { createWriteQueue, createWriteSequencer } from '@/Utils/writeOrdering'

const seance = () => ({
    id: 5,
    workout_lines: [
        { id: 1, exercise: { name: 'Développé couché' }, sets: [{ id: 11 }, { id: 12 }] },
        { id: 2, exercise: { name: 'Course' }, sets: [{ id: 21 }] },
    ],
})

const monter = ({ finie = false } = {}) => {
    const reportSyncFailure = vi.fn()
    let ordre

    const composant = defineComponent({
        setup() {
            const localWorkout = ref(seance())
            const isFinished = computed(() => finie)
            const { next: nextWrite, isLatest: isLatestWrite } = createWriteSequencer()

            ordre = useOrdreDeLaSeance({
                localWorkout,
                isFinished,
                pendingIds: { resolve: (id) => Promise.resolve(id) },
                nextWrite,
                isLatestWrite,
                fieldWrites: createWriteQueue(),
                reportSyncFailure,
            })

            return { localWorkout, ...ordre }
        },
        render() {
            return h('div', { ref: 'listeDesExercices' })
        },
    })

    const wrapper = mount(composant)

    return { wrapper, ordre, reportSyncFailure, lignes: () => wrapper.vm.localWorkout.workout_lines }
}

beforeEach(() => {
    vi.clearAllMocks()
    globalThis.route = (nom, params) => `/${nom}/${Object.values(params).join('/')}`
})

describe('l’ordre des exercices', () => {
    it('déplace, annonce la nouvelle position et envoie l’ordre entier', async () => {
        sync.patch.mockResolvedValue({})
        const { ordre, lignes } = monter()

        ordre.deplacerExercice(0, 1)
        await flushPromises()

        expect(lignes().map((l) => l.id)).toEqual([2, 1])
        expect(ordre.annonceReorganisation.value).toBe('Développé couché déplacé en position 2 sur 2')
        expect(sync.patch).toHaveBeenCalledWith('/api.v1.workouts.line-order/5', { lines: [2, 1] })
        expect(ordre.ordreConfirme.value).toEqual([2, 1])
    })

    it('refuse un rang hors liste ou identique, sans rien écrire', async () => {
        const { ordre, lignes } = monter()

        ordre.deplacerExercice(0, -1)
        ordre.deplacerExercice(1, 2)
        ordre.deplacerExercice(1, 1)
        await flushPromises()

        expect(lignes().map((l) => l.id)).toEqual([1, 2])
        expect(sync.patch).not.toHaveBeenCalled()
    })

    it('revient à l’ordre confirmé quand le serveur refuse, et le dit', async () => {
        sync.patch.mockRejectedValue({ isOffline: false })
        const { ordre, lignes, reportSyncFailure } = monter()

        ordre.deplacerExercice(0, 1)
        await flushPromises()

        expect(lignes().map((l) => l.id)).toEqual([1, 2])
        expect(reportSyncFailure).toHaveBeenCalledWith('L’ordre des exercices n’a pas pu être enregistré. Réessaie.')
        expect(ordre.ordreEnVol.value).toBe(0)
    })

    it('garde le nouvel ordre quand l’écriture est seulement mise en file', async () => {
        sync.patch.mockRejectedValue({ isOffline: true })
        const { ordre, lignes, reportSyncFailure } = monter()

        ordre.deplacerExercice(0, 1)
        await flushPromises()

        expect(lignes().map((l) => l.id)).toEqual([2, 1])
        expect(reportSyncFailure).not.toHaveBeenCalled()
    })
})

describe('l’ordre des séries', () => {
    it('déplace une série et envoie l’ordre entier de sa ligne', async () => {
        sync.patch.mockResolvedValue({})
        const { ordre, lignes } = monter()

        ordre.deplacerSerie(lignes()[0], 0, 1)
        await flushPromises()

        expect(lignes()[0].sets.map((s) => s.id)).toEqual([12, 11])
        expect(sync.patch).toHaveBeenCalledWith('/api.v1.workout-lines.set-order/1', { sets: [12, 11] })
    })

    it('remet les séries dans l’ordre confirmé quand le serveur refuse', async () => {
        sync.patch.mockRejectedValue({ isOffline: false })
        const { ordre, lignes, reportSyncFailure } = monter()

        ordre.deplacerSerie(lignes()[0], 0, 1)
        await flushPromises()

        expect(lignes()[0].sets.map((s) => s.id)).toEqual([11, 12])
        expect(reportSyncFailure).toHaveBeenCalledWith('L’ordre des séries n’a pas pu être enregistré. Réessaie.')
    })

    it('ne réordonne ni une ligne d’une seule série ni une séance close', () => {
        const { ordre, lignes } = monter()
        expect(ordre.peutReordonner(lignes()[0])).toBe(true)
        expect(ordre.peutReordonner(lignes()[1])).toBe(false)

        const close = monter({ finie: true })
        expect(close.ordre.peutReordonner(close.lignes()[0])).toBe(false)
    })
})

describe('les commandes de la rangée', () => {
    const evenement = (cible) => ({ target: cible, stopPropagation: vi.fn() })

    it('arrête le geste sur une commande, sauf sur la pastille du clavier', () => {
        const { ordre } = monter()
        const bouton = document.createElement('button')
        const pastille = document.createElement('button')
        pastille.setAttribute('data-poignee-clavier', '')
        const texte = document.createElement('span')

        const e1 = evenement(bouton)
        const e2 = evenement(pastille)
        const e3 = evenement(texte)
        ordre.ecarterLesCommandes(e1)
        ordre.ecarterLesCommandes(e2)
        ordre.ecarterLesCommandes(e3)

        expect(e1.stopPropagation).toHaveBeenCalled()
        expect(e2.stopPropagation).not.toHaveBeenCalled()
        expect(e3.stopPropagation).not.toHaveBeenCalled()
    })

    it('enregistre le conteneur des séries d’une ligne, et l’oublie quand il disparaît', () => {
        const { ordre } = monter()
        const element = document.createElement('div')

        ordre.poserLeConteneurDeSeries(1)(element)
        ordre.poserLeConteneurDeSeries(1)(null)

        expect(ordre.generationDesSeries.value.get(1)).toBeUndefined()
    })
})
