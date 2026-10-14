import { it, expect, vi, beforeAll, afterEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { h } from 'vue'

import { chargerSyncService, poserLaPage, retirerLesEcouteursDuService } from '../utils/fileHorsLigne'
import { creerUnFauxServeur, fileDurable, routeDeTest } from '../composables/seanceHorsLigne'
import { suivreLesPagesMontees } from './pagesMontees'

const reseau = vi.hoisted(() => ({ serveur: null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

// La mise en page et les modales, fermées ici, n'ont rien à montrer : la séance seule compte.
vi.mock('@/Layouts/AuthenticatedLayout.vue', async () => {
    const { h: rendre } = await import('vue')

    return {
        default: {
            name: 'AuthenticatedLayout',
            setup:
                (_props, { slots }) =>
                () =>
                    rendre('div', [slots['header-actions']?.(), slots.default?.()]),
        },
    }
})
vi.mock('@/Components/Workout/AjoutDExerciceModal.vue', () => ({
    default: { name: 'AjoutDExerciceModal', render: () => null },
}))
vi.mock('@/Components/Workout/WorkoutSettingsModal.vue', () => ({
    default: { name: 'WorkoutSettingsModal', render: () => null },
}))
vi.mock('@/Components/Workout/WorkoutFinishModal.vue', () => ({
    default: { name: 'WorkoutFinishModal', render: () => null },
}))
vi.mock('@/Components/UI/ConfirmDialog.vue', () => ({ default: { name: 'ConfirmDialog', render: () => null } }))
vi.mock('@/Components/Workout/RestTimer.vue', () => ({ default: { name: 'RestTimer', render: () => null } }))

/*
 * Les boutons Retour et Avance du navigateur, sans réseau, avec le vrai client
 * d'Inertia et la vraie page de séance (#2048).
 *
 * Inertia relit la séance dans son historique chiffré, sans rien demander au
 * serveur : les props sont celles de la visite, d'avant la série ajoutée hors
 * ligne. La page reconstruisait sa copie depuis ces seules props, et la série
 * revenait absente, sans `cloud_off`, alors que la file la gardait. Elle ne
 * réapparaissait qu'au retour du réseau, après un rechargement.
 *
 * jsdom ne joint pas de serveur : les visites d'Inertia passent par une
 * `XMLHttpRequest` simulée qui rend la page de chaque adresse, les écritures de
 * la séance par le faux serveur de `seanceHorsLigne.js`, qu'on coupe. jsdom
 * recopie aussi `history.state` dans un autre domaine, où le `instanceof
 * ArrayBuffer` d'Inertia ne reconnaît plus l'entrée chiffrée : le test la
 * reconnaît à sa marque, comme un navigateur (`.ai/rules/tests-js.md`).
 */

const SQUAT = { id: 7, name: 'Squat', type: 'strength', category: 'Jambes', default_rest_time: 90 }

/** La séance telle que le serveur la rend maintenant : ce qu'il a en base. */
const seanceDuServeur = () => ({
    id: 5,
    name: 'Séance du soir',
    started_at: '2026-10-12T17:00:00.000000Z',
    ended_at: null,
    notes: null,
    workout_lines: [
        {
            id: 1,
            exercise_id: 7,
            exercise: SQUAT,
            order: 0,
            notes: null,
            recommended_values: null,
            sets: [...reseau.serveur.series.values()]
                .filter((serie) => serie.workout_line_id === 1)
                .sort((a, b) => a.id - b.id)
                .map((serie) => ({ is_warmup: false, personal_record: null, ...serie })),
        },
    ],
})

const PAGES = {
    '/workouts': () => ({
        component: 'Workouts/Index',
        url: '/workouts',
        version: 'v1',
        props: { errors: {}, flash: {}, auth: { user: { id: 1 } } },
        encryptHistory: true,
    }),
    '/workouts/5': () => ({
        component: 'Workouts/Show',
        url: '/workouts/5',
        version: 'v1',
        props: {
            errors: {},
            flash: {},
            auth: { user: { id: 1 } },
            workout: seanceDuServeur(),
            exercises: [SQUAT],
            bornesDUneSerie: null,
        },
        encryptHistory: true,
    }),
}

/** Les chemins que les visites d'Inertia ont demandés au serveur. */
const visites = []

class RequeteSimulee {
    upload = {}
    status = 0
    responseText = ''

    open(_methode, url) {
        this.url = url
    }

    setRequestHeader() {}

    getAllResponseHeaders() {
        return 'x-inertia: true\r\ncontent-type: application/json'
    }

    abort() {
        this.onabort?.()
    }

    send() {
        const chemin = new URL(this.url, window.location.href).pathname
        visites.push(chemin)

        queueMicrotask(() => {
            this.status = 200
            this.responseText = JSON.stringify(PAGES[chemin]())
            this.onload?.()
        })
    }
}

const ListeDesSeances = { name: 'ListeDesSeances', render: () => h('p', { id: 'liste-des-seances' }, 'Séances') }

const pages = suivreLesPagesMontees()
const reconnaitreUnArrayBuffer = Object.getOwnPropertyDescriptor(ArrayBuffer, Symbol.hasInstance)

let routeur
let sync

const present = (selecteur) => document.querySelector(selecteur) !== null
const rangees = () => document.querySelectorAll('[dusk^="complete-set-0-"]').length

/** Une visite d'Inertia, jusqu'à la page affichée. */
const visiter = async (adresse, selecteur) => {
    routeur.visit(adresse)
    await vi.waitUntil(() => present(selecteur))
    await flushPromises()
}

/** Un bouton du navigateur, jusqu'à la page affichée. */
const naviguerDansLHistorique = async (sens, selecteur) => {
    window.history[sens]()
    await vi.waitUntil(() => present(selecteur), { timeout: 3000 })
    await flushPromises()
}

const cliquer = async (selecteur) => {
    document.querySelector(selecteur).click()
    await flushPromises()
}

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    Object.defineProperty(ArrayBuffer, Symbol.hasInstance, {
        value: (valeur) => Object.prototype.toString.call(valeur) === '[object ArrayBuffer]',
        configurable: true,
    })

    localStorage.clear()
    sessionStorage.clear()
    globalThis.route = routeDeTest
    reseau.serveur = creerUnFauxServeur({
        series: [{ id: 3, workout_line_id: 1, weight: 80, reps: 5, is_completed: true }],
    })
    reseau.serveur.lignes.set(1, { id: 1, workout_id: 5, exercise_id: 7 })

    // Un chargement de page : le service de synchronisation d'abord, puis Inertia, qui partagent ce chargement.
    sync = await chargerSyncService({ compte: 1 })
    await sync.pending

    window.history.replaceState(null, '', '/workouts')
    const initiale = document.createElement('script')
    initiale.dataset.page = 'app'
    initiale.type = 'application/json'
    initiale.textContent = JSON.stringify(PAGES['/workouts']())
    document.body.replaceChildren(initiale, Object.assign(document.createElement('div'), { id: 'app' }))

    const inertia = await import('@inertiajs/vue3')
    const { default: Seance } = await import('@/Pages/Workouts/Show.vue')
    routeur = inertia.router

    await inertia.createInertiaApp({
        resolve: (nom) => (nom === 'Workouts/Show' ? Seance : ListeDesSeances),
        setup: ({ el, App, props, plugin }) => {
            pages.retenir(mount(App, { props, attachTo: el, global: { plugins: [plugin], directives: { press: {} } } }))
        },
    })

    await vi.waitUntil(() => present('#liste-des-seances'))
})

afterEach(async () => {
    await pages.demonterToutes()
    retirerLesEcouteursDuService()
    poserLaPage(null)

    if (reconnaitreUnArrayBuffer) {
        Object.defineProperty(ArrayBuffer, Symbol.hasInstance, reconnaitreUnArrayBuffer)
    } else {
        delete ArrayBuffer[Symbol.hasInstance]
    }

    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    localStorage.clear()
    sessionStorage.clear()
})

it('montre, au retour par Avance, la série ajoutée et cochée sans réseau, et la saisie suivante rejoint la même entrée', async () => {
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')
    expect(rangees()).toBe(1)

    // Plus de réseau : la série ajoutée part en file, puis elle est cochée.
    reseau.serveur.enLigne = false
    await cliquer('[dusk="add-set-0"]')
    await cliquer('[dusk="complete-set-0-1"]')

    const [entree] = fileDurable()
    expect(fileDurable()).toHaveLength(1)
    expect(entree.data).toMatchObject({ workout_line_id: 1, is_completed: true })

    visites.length = 0

    await naviguerDansLHistorique('back', '#liste-des-seances')
    await naviguerDansLHistorique('forward', '[dusk="exercise-card-0"]')

    // Relue dans l'historique, sans le serveur : la série est là, cochée, marquée.
    expect(visites).toEqual([])
    expect(rangees()).toBe(2)
    expect(present('[dusk="set-unsynced-0-1"]')).toBe(true)
    expect(document.querySelector('[dusk="set-unsynced-0-1"]').getAttribute('aria-label')).toBe(
        'Série 2 non enregistrée',
    )
    expect(document.querySelector('[dusk="complete-set-0-1"]').getAttribute('aria-label')).toBe('Annuler la série')

    // Saisie après le retour : elle se fond dans la même entrée, qui reste seule.
    const poids = document.querySelector('[dusk="weight-input-0-1"]')
    poids.value = '82.5'
    poids.dispatchEvent(new Event('input'))
    poids.dispatchEvent(new Event('change'))
    await flushPromises()

    expect(fileDurable()).toHaveLength(1)
    expect(fileDurable()[0]).toMatchObject({
        id: entree.id,
        data: { workout_line_id: 1, is_completed: true, weight: 82.5 },
    })

    // Le réseau revient : une seule création, une seule série en base, et la séance rechargée ne la double pas.
    reseau.serveur.enLigne = true
    await sync.processQueue()
    await vi.waitUntil(() => visites.length > 0)
    await vi.waitUntil(() => !present('[dusk="set-unsynced-0-1"]'))
    await flushPromises()

    expect(reseau.serveur.resume()).toEqual([
        'post /api/v1/sets {"workout_line_id":1,"is_completed":true,"weight":82.5,"reps":5}',
    ])
    expect([...reseau.serveur.series.values()].filter((serie) => serie.workout_line_id === 1)).toHaveLength(2)
    expect(visites).toEqual(['/workouts/5'])
    expect(rangees()).toBe(2)
    expect(fileDurable()).toEqual([])
})
