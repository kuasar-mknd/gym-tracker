import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import { chargerSyncService, poserLaPage, retirerLesEcouteursDuService } from '../utils/fileHorsLigne'
import { creerUnFauxServeur, fileDurable, routeDeTest } from '../composables/seanceHorsLigne'
import { suivreLesPagesMontees } from './pagesMontees'

const reseau = vi.hoisted(() => ({ serveur: null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))
vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    usePage: () => ({ props: { auth: { user: { id: 1, auto_rest_timer: true } }, flash: {} } }),
    router: { visit: vi.fn(), post: vi.fn(), patch: vi.fn(), reload: vi.fn(), delete: vi.fn() },
    useForm: (champs) => ({ ...champs, processing: false, errors: {}, transform: vi.fn(), patch: vi.fn() }),
}))

/*
 * Un brouillon de série est rangé sous l'identifiant de la série. Celui d'une
 * série provisoire ne vaut que pour le montage qui l'a donné : le compteur de
 * la page repart de zéro à chaque montage, et le `temp-1` d'un montage précédent
 * est une autre série dans le suivant. La page le rejoue à son montage
 * (`rejouerLesBrouillons`) : appliqué à la série qui porte ce nom maintenant,
 * il partait au serveur avec elle (#2048).
 *
 * La page est la vraie, montée avec le vrai service de synchronisation, relu
 * depuis le stockage comme après un rechargement, et sans réseau pour les écritures.
 */

const SQUAT = { id: 7, name: 'Squat', type: 'strength', category: 'Jambes', default_rest_time: 90 }

const seanceDuServeur = () => ({
    id: 5,
    name: 'Séance',
    started_at: '2026-10-12T17:00:00.000000Z',
    ended_at: null,
    workout_lines: [
        { id: 10, exercise_id: 7, exercise: SQUAT, sets: [{ id: 42, weight: 80, reps: 5, is_completed: true }] },
    ],
})

/** La série ajoutée et cochée sans réseau avant le rechargement, telle que la file la garde. */
const creationEnFile = {
    method: 'post',
    url: '/api/v1/sets',
    data: { workout_line_id: 10, is_completed: true, weight: 82.5, reps: 7 },
    headers: { 'Idempotency-Key': 'cle-de-la-serie' },
    id: 'q1',
    timestamp: '2026-10-12T18:04:00.000Z',
    compte: '1',
    tentee: true,
    ajusterPar: '/api/v1/sets/__produit__',
}

const passeLeCreneau = { template: '<div><slot /></div>' }

const pages = suivreLesPagesMontees()

const monterLaPage = async () => {
    const sync = await chargerSyncService({ compte: 1 })
    await sync.pending

    const { default: Seance } = await import('@/Pages/Workouts/Show.vue')
    const RangeeDeSerie = (await import('@/Components/Workout/RangeeDeSerie.vue')).default
    const CarteDExercice = (await import('@/Components/Workout/CarteDExercice.vue')).default

    const wrapper = mount(Seance, {
        props: { workout: seanceDuServeur(), exercises: [SQUAT] },
        shallow: true,
        global: {
            directives: { press: {} },
            stubs: {
                AuthenticatedLayout: passeLeCreneau,
                GlassCard: passeLeCreneau,
                SwipeableRow: passeLeCreneau,
                RangeeDeSerie,
                CarteDExercice,
            },
        },
    })
    pages.retenir(wrapper)
    await flushPromises()

    return { wrapper, sync }
}

beforeEach(() => {
    localStorage.clear()
    globalThis.route = routeDeTest
    reseau.serveur = creerUnFauxServeur({ series: [{ id: 42, workout_line_id: 10, weight: 80, reps: 5 }] })
    reseau.serveur.lignes.set(10, { id: 10, workout_id: 5, exercise_id: 7 })
})

afterEach(async () => {
    await pages.demonterToutes()
    retirerLesEcouteursDuService()
    poserLaPage(null)
    localStorage.clear()
})

describe('les brouillons d’un montage précédent', () => {
    it('ne sont jamais appliqués à la série qui attend en file, ni envoyés avec elle', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([creationEnFile]))
        localStorage.setItem('draft_set_temp-1', JSON.stringify({ weight: 999, reps: 99 }))
        localStorage.setItem('draft_set_temp-qpartie', JSON.stringify({ reps: 1 }))
        localStorage.setItem('draft_set_42', JSON.stringify({ weight: 95 }))
        reseau.serveur.enLigne = false

        const { wrapper, sync } = await monterLaPage()
        const [, enFile] = wrapper.vm.localWorkout.workout_lines[0].sets

        expect(enFile).toMatchObject({ id: 'temp-qq1', weight: 82.5, reps: 7, is_completed: true })
        expect(wrapper.find('[dusk="set-unsynced-0-1"]').exists()).toBe(true)
        expect(Object.keys(localStorage).filter((cle) => cle.startsWith('draft_set_temp-'))).toEqual([])

        // Le brouillon de la série enregistrée, lui, est rejoué : sans réseau, il part en file.
        expect(fileDurable().map(({ method, url, data }) => ({ method, url, data }))).toEqual([
            { method: 'post', url: '/api/v1/sets', data: creationEnFile.data },
            { method: 'patch', url: '/api/v1/sets/42', data: { weight: 95 } },
        ])

        reseau.serveur.enLigne = true
        await sync.processQueue()
        await flushPromises()

        expect(reseau.serveur.resume()).toEqual([
            'post /api/v1/sets {"workout_line_id":10,"is_completed":true,"weight":82.5,"reps":7}',
            'patch /api/v1/sets/42 {"weight":95}',
        ])
        expect(enFile).toMatchObject({ id: 100, weight: 82.5, reps: 7 })
    })

    /*
     * La page est morte entre la frappe et l'envoi : la saisie n'a laissé qu'un
     * brouillon, rangé sous l'identifiant que la série porte d'un montage à
     * l'autre tant que sa création attend, `temp-q<queueId>`. Il revient sur
     * cette série, et sur elle seule, et rejoint son entrée de file.
     */
    it('rejouent sur la série en file le brouillon rangé sous son entrée, dans cette même entrée', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([creationEnFile]))
        localStorage.setItem('draft_set_temp-qq1', JSON.stringify({ reps: 9 }))
        reseau.serveur.enLigne = false

        const { wrapper, sync } = await monterLaPage()
        const [, enFile] = wrapper.vm.localWorkout.workout_lines[0].sets

        expect(enFile).toMatchObject({ id: 'temp-qq1', weight: 82.5, reps: 9, is_completed: true })
        expect(wrapper.find('[dusk="set-unsynced-0-1"]').exists()).toBe(true)
        expect(fileDurable()).toEqual([
            expect.objectContaining({
                id: 'q1',
                data: { workout_line_id: 10, is_completed: true, weight: 82.5, reps: 9 },
            }),
        ])
        expect(Object.keys(localStorage).filter((cle) => cle.startsWith('draft_set_'))).toEqual([])

        reseau.serveur.enLigne = true
        await sync.processQueue()
        await flushPromises()

        expect(reseau.serveur.resume()).toEqual([
            'post /api/v1/sets {"workout_line_id":10,"is_completed":true,"weight":82.5,"reps":9}',
        ])
        expect(enFile).toMatchObject({ id: 100, weight: 82.5, reps: 9 })
        expect(wrapper.find('[dusk="set-unsynced-0-1"]').exists()).toBe(false)
    })
})
