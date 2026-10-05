import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

import { retirerLesEcouteursDuService, poserLaPage } from '../utils/fileHorsLigne'
import { creerUnFauxServeur, fileDurable, monterLaSeance, routeDeTest } from './seanceHorsLigne'

const reseau = vi.hoisted(() => ({ serveur: null }))
const inertia = vi.hoisted(() => ({ patch: vi.fn() }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn(), patch: (...args) => inertia.patch(...args) },
    useForm: (champs) => ({ ...champs, transform: vi.fn(), patch: vi.fn() }),
}))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

/**
 * « Terminer » n'attendait que la saisie en cours, pas la file hors ligne. La
 * clôture partait hors de la file ; les écritures qui y restaient revenaient
 * ensuite refusées, puisqu'une séance close n'accepte plus de série, et la page
 * était déjà partie (#1961). Deux cas : le réseau revenu sans évènement
 * `online`, et un vidage déjà en cours au moment de confirmer.
 */

const seance = () => ({
    id: 5,
    name: 'Jambes',
    started_at: '2026-10-05T10:00:00.000000Z',
    notes: null,
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

/** Ce que voyaient la file et le serveur au moment où la clôture est partie. */
let aLaCloture

beforeEach(() => {
    localStorage.clear()
    reseau.serveur = creerUnFauxServeur()
    globalThis.route = routeDeTest
    aLaCloture = []
    inertia.patch.mockReset()
    inertia.patch.mockImplementation((url, donnees) =>
        aLaCloture.push({ url, donnees, enFile: fileDurable().length, recues: reseau.serveur.resume() }),
    )
})

afterEach(() => {
    vi.useRealTimers()
    retirerLesEcouteursDuService()
    poserLaPage(null)
})

const ajouterUneSerieHorsLigne = async (page) => {
    reseau.serveur.enLigne = false
    page.addSet(1)
    await flushPromises()
}

describe('terminer une séance', () => {
    it('vide la file avant de clore, quand le réseau est revenu sans évènement', async () => {
        const page = await monterLaSeance(seance())
        await ajouterUneSerieHorsLigne(page)

        reseau.serveur.enLigne = true
        page.finishWorkout()
        await page.confirmFinishWorkout()

        expect(aLaCloture).toEqual([
            {
                url: '/workouts/5',
                donnees: { is_finished: true },
                enFile: 0,
                recues: ['post /api/v1/sets {"workout_line_id":1,"is_completed":false,"weight":80,"reps":5}'],
            },
        ])
        expect(page.ecrituresEnAttente.value).toBe(0)
    })

    it('attend la fin d’un vidage déjà en cours avant de clore', async () => {
        const page = await monterLaSeance(seance())
        await ajouterUneSerieHorsLigne(page)

        let repondre
        const reponse = new Promise((resolve) => (repondre = resolve))
        reseau.serveur.imposer.push(async (config) => {
            await reponse

            return { data: { data: { id: 100, ...config.data } } }
        })

        // Le retour du réseau a lancé un vidage, qui attend encore le serveur.
        reseau.serveur.enLigne = true
        page.sync.processQueue()
        await flushPromises()

        page.finishWorkout()
        const cloture = page.confirmFinishWorkout()
        await flushPromises()

        expect(inertia.patch).not.toHaveBeenCalled()

        repondre()
        await cloture

        expect(aLaCloture).toHaveLength(1)
        expect(aLaCloture[0].enFile).toBe(0)
        expect(aLaCloture[0].recues).toHaveLength(1)
    })

    it('reste ouverte et dit combien de modifications attendent, tant que la file ne se vide pas', async () => {
        const page = await monterLaSeance(seance())
        await ajouterUneSerieHorsLigne(page)
        page.addSet(1)
        await flushPromises()

        page.finishWorkout()
        await page.confirmFinishWorkout()

        expect(inertia.patch).not.toHaveBeenCalled()
        expect(page.showFinishModal.value).toBe(true)
        expect(page.ecrituresEnAttente.value).toBe(2)

        // Le réseau revient : la tentative suivante vide la file, puis clôt.
        reseau.serveur.enLigne = true
        await page.confirmFinishWorkout()

        expect(aLaCloture).toHaveLength(1)
        expect(aLaCloture[0].enFile).toBe(0)
        expect(page.ecrituresEnAttente.value).toBe(0)
    })

    it('reste ouverte quand le serveur répond une erreur passagère', async () => {
        const page = await monterLaSeance(seance())
        await ajouterUneSerieHorsLigne(page)
        // La relance programmée après l'erreur ne doit pas survivre au test.
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
        reseau.serveur.enLigne = true
        reseau.serveur.imposer.push(() => Promise.reject({ response: { status: 503 } }))

        page.finishWorkout()
        await page.confirmFinishWorkout()

        expect(inertia.patch).not.toHaveBeenCalled()
        expect(page.ecrituresEnAttente.value).toBe(1)
    })
})

describe('la modale de fin', () => {
    const modale = async (enAttente) => {
        const WorkoutFinishModal = (await import('@/Components/Workout/WorkoutFinishModal.vue')).default

        return mount(WorkoutFinishModal, {
            props: { show: true, enAttente },
            global: { stubs: { Modal: { template: '<div><slot /></div>' } } },
        })
    }

    it('dit combien de modifications attendent, et que la séance reste ouverte ; rien quand rien n’attend', async () => {
        expect((await modale(3)).find('[dusk="finish-workout-pending"]').text()).toBe(
            '3 modifications attendent encore d’être envoyées. La séance reste ouverte : réessaie quand le réseau sera revenu.',
        )
        expect((await modale(1)).find('[dusk="finish-workout-pending"]').text()).toBe(
            'Une modification attend encore d’être envoyée. La séance reste ouverte : réessaie quand le réseau sera revenu.',
        )
        expect((await modale(0)).find('[dusk="finish-workout-pending"]').exists()).toBe(false)
    })
})
