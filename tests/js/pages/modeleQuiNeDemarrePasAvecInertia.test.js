import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'

import { chargerSyncService, retirerLesEcouteursDuService } from '../utils/fileHorsLigne'
import {
    RequeteSimulee,
    relayerLesRejetsNonGeres,
    reponseInertia,
    requetes,
    reseau,
    uneTache,
} from '../utils/reseauSimule'

vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: () => {} }))

/*
 * Lancer un modèle depuis la vraie page des modèles et le vrai layout, avec le
 * vrai client d'Inertia et la vraie file hors ligne (#2049) : ce qui ne
 * démarre pas, et le lancement en ligne.
 *
 * - Une copie de la page qui ne porte pas le contenu du modèle (`lancements`)
 *   ne peut rien recopier dans la séance : rien n'entre en file, et la page le
 *   dit (« Ce modèle n'est pas disponible sans réseau. »).
 * - Une séance déjà en cours s'ouvre au lieu du modèle, et la séance dit que
 *   le modèle n'a pas été démarré, comme le serveur le disait à la page des
 *   modèles : connue de la page sans serveur, ou nommée par le serveur (409).
 * - En ligne, une seule requête, avec le modèle, puis la visite de la séance
 *   créée.
 *
 * La page des modèles est la vraie, sous le vrai layout, dont les toasts se
 * lisent dans le DOM ; la séance est un composant du test, qui montre ce
 * qu'elle reçoit et le message posé sur sa page. Les écritures de l'API
 * passent par un faux `Utils/http`, les visites d'Inertia par le réseau
 * simulé. Le client se monte une fois pour le fichier (`.ai/rules/tests-js.md`).
 */

/** Le serveur de l'API, derrière `Utils/http` : ce qu'il a reçu, et ce qu'il rendra à chaque écriture. */
const api = vi.hoisted(() => ({
    requetes: [],
    /** @type {Array<(config: object) => Promise<object>>} */
    reponses: [],
    repondre: async (config) => {
        api.requetes.push({ method: config.method, url: config.url, data: config.data })

        const reponse = api.reponses.shift()

        if (reponse === undefined) {
            throw { code: 'ERR_NETWORK', request: {} }
        }

        return reponse(config)
    },
}))

vi.mock('@/Utils/http', () => ({ http: (config) => api.repondre(config) }))

const ROUTES = {
    dashboard: '/dashboard',
    'stats.index': '/stats',
    'workouts.index': '/workouts',
    'profile.index': '/profile',
    'profile.edit': '/profile/edit',
    'templates.index': '/templates',
    'templates.create': '/templates/create',
    'notifications.index': '/notifications',
    'calendar.index': '/calendar',
    'exercises.index': '/exercises',
    'tools.index': '/tools',
    'achievements.index': '/achievements',
    'shortcuts.index': '/shortcuts',
    'api.v1.workouts.store': '/api/v1/workouts',
    'api.v1.workout-lines.store': '/api/v1/workout-lines',
    'api.v1.sets.store': '/api/v1/sets',
}

/** Ziggy : chaque nom à son chemin, une séance ou un modèle à son identifiant, la page courante d'après l'adresse. */
const route = (nom, parametres) => {
    if (nom === undefined) {
        return { current: (motif) => (motif === undefined ? null : window.location.pathname === ROUTES[motif]) }
    }

    const identifiant = typeof parametres === 'object' ? Object.values(parametres)[0] : parametres

    if (nom === 'workouts.show') {
        return `/workouts/${identifiant}`
    }

    if (nom === 'templates.edit' || nom === 'templates.destroy') {
        return `/templates/${identifiant}`
    }

    if (nom === 'api.v1.sets.update') {
        return `/api/v1/sets/${identifiant}`
    }

    return ROUTES[nom]
}

const LANCEMENT = {
    name: 'Push A',
    lignes: [
        {
            exercise: { id: 8, name: 'Développé couché', type: 'strength' },
            order: 0,
            cle: 'modele-ligne:0:8',
            series: [{ reps: 8, weight: 80, is_warmup: false, cle: 'modele-serie:0:0' }],
        },
    ],
}

/** La page des modèles : le modèle 3, ce qui le lance, la séance en cours, la marque d'une copie. */
const pageDesModeles = ({ lancements = { 3: LANCEMENT }, activeWorkout = null, horsLigne } = {}) => ({
    component: 'Workouts/Templates/Index',
    url: '/templates',
    version: 'v1',
    props: {
        errors: {},
        flash: {},
        auth: { user: { id: 1, name: 'Samuel', unread_notifications_count: 0, active_workout: activeWorkout } },
        templates: [
            {
                id: 3,
                name: 'Push A',
                description: null,
                workout_template_lines_count: 1,
                workout_template_lines: [
                    { id: 31, exercise: { name: 'Développé couché' }, workout_template_sets_count: 1 },
                ],
            },
        ],
        lancements,
        ...(horsLigne === undefined ? {} : { horsLigne }),
    },
    encryptHistory: true,
})

/** Une copie, celle que la page servirait sans réseau. */
const COPIE = () => ({ copieDu: Date.now() - 60_000, raison: 'reseau', datesDesProps: {} })

/** La séance que le serveur rend. */
const seanceDuServeur = (id) => ({
    component: 'Workouts/Show',
    url: `/workouts/${id}`,
    version: 'v1',
    props: {
        auth: { user: { id: 1, name: 'Samuel' } },
        flash: {},
        workout: { id, name: 'Jambes', started_at: '2026-10-19T08:15:00.000000Z', workout_lines: [] },
        exercises: [],
    },
    encryptHistory: true,
})

let page = null

/** La séance, telle que le test la voit : ce qu'elle reçoit, et le message posé sur sa page. */
const SeanceDuTest = {
    props: ['workout'],
    render() {
        return h('div', [
            h('p', { id: 'seance' }, `séance ${this.workout.id}`),
            h('p', { id: 'message-de-la-seance' }, page?.props.flash?.error ?? ''),
        ])
    },
}

let routeur
let file
let etatDuReseau
let application
let relais

const toasts = () =>
    [...document.querySelectorAll('[role="alert"][aria-live] p')].map((toast) => toast.textContent.trim())
const fileStockee = () => JSON.parse(localStorage.getItem('offline_sync_queue') ?? '[]')
const lancer = () => document.querySelector('[dusk="execute-template-3"]').click()

/** Remet la page des modèles à l'écran, par une visite du client seul (#2044). */
const remettreLaPageDesModeles = async (options) => {
    let remise = false

    routeur.replace({ ...pageDesModeles(options), preserveState: false, onFinish: () => (remise = true) })
    await vi.waitUntil(() => remise && document.querySelector('[dusk="execute-template-3"]') !== null)
}

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.stubGlobal('route', route)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

    localStorage.clear()
    sessionStorage.clear()
    window.history.replaceState(null, '', '/templates')

    // La file d'abord, puis tout le reste dans son graphe : la page, le client, l'état du réseau.
    file = await chargerSyncService({ compte: 1 })
    await file.pending

    etatDuReseau = await import('@/composables/useEtatDuReseau')

    const inertia = await import('@inertiajs/vue3')
    const { default: PageDesModeles } = await import('@/Pages/Workouts/Templates/Index.vue')
    const { installerLesVisitesHorsLigne } = await import('@/Utils/visitesHorsLigne')
    routeur = inertia.router
    page = inertia.usePage()

    installerLesVisitesHorsLigne({ routeur, lireUneCopie: async () => null })

    document.body.replaceChildren(Object.assign(document.createElement('div'), { id: 'app' }))

    await inertia.createInertiaApp({
        page: pageDesModeles(),
        resolve: (nom) => (nom === 'Workouts/Show' ? SeanceDuTest : PageDesModeles),
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) })
            application.config.globalProperties.route = route
            application.directive('press', {})
            application.use(plugin).mount(el)
        },
    })

    await vi.waitUntil(() => page.component === 'Workouts/Templates/Index')
})

beforeEach(async () => {
    localStorage.removeItem('offline_sync_queue')
    file.relireLaFile()
    api.requetes.length = 0
    api.reponses.length = 0
    requetes.length = 0
    reseau.mode = 'coupe'
    reseau.prochains.length = 0
    etatDuReseau.noterQueLeServeurRepond()
    await remettreLaPageDesModeles()
    requetes.length = 0
    relais = relayerLesRejetsNonGeres()
})

afterEach(async () => {
    relais.retirer()
    await uneTache()
})

afterAll(() => {
    application.unmount()
    retirerLesEcouteursDuService()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    localStorage.clear()
})

describe('lancer un modèle qui ne démarre pas', () => {
    it('sur une copie sans le contenu du modèle, ne met rien en file et dit que le modèle n’est pas disponible sans réseau', async () => {
        await remettreLaPageDesModeles({ lancements: {}, horsLigne: COPIE() })

        lancer()
        // Ce que dit la page, ou ce que disait l'ancien lancement : « Il faut le réseau pour démarrer une séance. »
        await vi.waitUntil(() => toasts().length > 0)

        expect(toasts()).toEqual(["Ce modèle n'est pas disponible sans réseau."])
        expect(fileStockee()).toEqual([])
        expect(api.requetes).toEqual([])
        expect(requetes).toHaveLength(0)
        expect(page.component).toBe('Workouts/Templates/Index')
        expect(relais.rejets).toEqual([])
    })

    it('sur une copie, ouvre la séance déjà en cours, et la séance dit que le modèle n’a pas été démarré', async () => {
        await remettreLaPageDesModeles({
            activeWorkout: { id: 9, name: 'Jambes', idempotency_key: 'cle-9' },
            horsLigne: COPIE(),
        })
        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(seanceDuServeur(9))

        lancer()
        await vi.waitUntil(() => document.getElementById('seance')?.textContent === 'séance 9')
        await vi.waitUntil(() => document.getElementById('message-de-la-seance')?.textContent !== '')

        expect(document.getElementById('message-de-la-seance').textContent).toBe(
            "Le modèle « Push A » n'a pas été démarré : une séance est déjà en cours. Terminez-la avant d'en commencer une autre.",
        )
        expect(fileStockee()).toEqual([])
        expect(api.requetes).toEqual([])
    })

    it('en ligne, ouvre la séance que le serveur dit en cours (409), avec le message du modèle, sans rien mettre en file', async () => {
        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(seanceDuServeur(9))
        api.reponses.push(async () => {
            throw {
                response: {
                    status: 409,
                    data: {
                        message: 'Une séance est déjà en cours.',
                        raison: 'seance-deja-en-cours',
                        seance: { id: 9 },
                    },
                },
            }
        })

        lancer()
        await vi.waitUntil(() => document.getElementById('seance')?.textContent === 'séance 9')
        await vi.waitUntil(() => document.getElementById('message-de-la-seance')?.textContent !== '')

        expect(api.requetes).toEqual([
            { method: 'post', url: '/api/v1/workouts', data: { started_at: expect.any(String), template_id: 3 } },
        ])
        expect(document.getElementById('message-de-la-seance').textContent).toContain("« Push A » n'a pas été démarré")
        expect(fileStockee()).toEqual([])
    })
})

describe('lancer un modèle en ligne', () => {
    it('le lance par une seule requête, avec le modèle, puis visite la séance créée', async () => {
        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(seanceDuServeur(42))
        api.reponses.push(async () => ({ data: { data: { id: 42, name: 'Push A' } }, status: 201 }))

        lancer()
        await vi.waitUntil(() => page.url === '/workouts/42')

        expect(api.requetes).toEqual([
            { method: 'post', url: '/api/v1/workouts', data: { started_at: expect.any(String), template_id: 3 } },
        ])
        expect(
            requetes.map((requete) => `${requete.methode} ${new URL(requete.url, window.location.href).pathname}`),
        ).toEqual(['GET /workouts/42'])
        expect(document.getElementById('message-de-la-seance').textContent).toBe('')
        expect(fileStockee()).toEqual([])
    })
})
