import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

import { retirerLesEcouteursDuService, poserLaPage } from '../utils/fileHorsLigne'
import { creerUnFauxServeur, monterLaSeance, routeDeTest, seances } from './seanceHorsLigne'

const reseau = vi.hoisted(() => ({ serveur: null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn(), patch: vi.fn() },
    useForm: (champs) => ({ ...champs, transform: vi.fn(), patch: vi.fn() }),
}))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

/**
 * Les séances que `monterLaSeance` montait restaient montées après leur test,
 * avec leurs écouteurs de `window` : ils répondaient au vidage de la file des
 * tests suivants, jusqu'à dix séances d'un même fichier à la fois (#2003).
 * L'aide retient désormais chaque séance, que `seances.demonterToutes()`
 * démonte en tête de l'`afterEach`.
 */

const seance = () => ({
    id: 5,
    workout_lines: [
        {
            id: 1,
            exercise_id: 7,
            exercise: { id: 7, name: 'Squat', type: 'strength' },
            recommended_values: null,
            sets: [{ id: 3, weight: 80, reps: 5, is_completed: true, is_warmup: false }],
        },
    ],
})

/** Le refus d'une série, tel que `SyncService` l'annonce à la page. */
const refuserLaSerie = (id) =>
    window.dispatchEvent(new CustomEvent('sync:failed', { detail: { url: `/api/v1/sets/${id}` } }))

beforeEach(() => {
    localStorage.clear()
    reseau.serveur = creerUnFauxServeur()
    globalThis.route = routeDeTest
})

afterEach(async () => {
    await seances.demonterToutes()
    retirerLesEcouteursDuService()
    poserLaPage(null)
})

describe('les séances montées par monterLaSeance', () => {
    it('sont démontées par seances.demonterToutes, et n’entendent plus la file ensuite', async () => {
        const page = await monterLaSeance(seance())

        refuserLaSerie(3)
        expect([...page.unsyncedSetIds.value]).toEqual(['3'])

        await seances.demonterToutes()
        refuserLaSerie(4)

        expect(page.wrapper.exists()).toBe(false)
        expect([...page.unsyncedSetIds.value]).toEqual(['3'])
    })
})
