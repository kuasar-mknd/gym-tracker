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

/*
 * Démarrer une séance sans réseau (#2049), depuis la vraie page d'accueil et
 * la vraie barre du bas, avec le vrai client d'Inertia et la vraie file hors
 * ligne.
 *
 * Le bouton « Séance » faisait une visite d'Inertia (`workouts.store`) qui ne
 * disait sans réseau que « Il faut le réseau pour démarrer une séance. »
 * (#2048). Il passe désormais par la file : sans réseau, la séance s'ouvre
 * aussitôt, construite depuis l'entrée de sa création, à l'adresse de sa clé ;
 * en ligne, un POST JSON puis la visite de la séance créée.
 *
 * Le client se monte une fois pour le fichier, après la file, et la page
 * s'importe après elle, dans le même graphe (`.ai/rules/tests-js.md`). La
 * séance poussée est rendue par un composant du test, qui montre ce qu'elle
 * reçoit : c'est la page que l'accueil pousse qui est ici en question. Les
 * écritures de l'API passent par un faux `Utils/http`, les visites d'Inertia
 * par le réseau simulé.
 */

// Les graphiques de l'accueil et de ses cartes : chargés à la demande, ils arriveraient après le fichier.
vi.mock('@/Components/Stats/ActiveGoalsChart.vue', () => ({ __esModule: true, default: { render: () => null } }))
vi.mock('@/Components/Stats/DashboardDurationChart.vue', () => ({ __esModule: true, default: { render: () => null } }))
vi.mock('@/Components/Stats/DurationDistributionChart.vue', () => ({
    __esModule: true,
    default: { render: () => null },
}))
vi.mock('@/Components/Stats/RecentPRsChart.vue', () => ({ __esModule: true, default: { render: () => null } }))
vi.mock('@/Components/Stats/RecentWorkoutsChart.vue', () => ({ __esModule: true, default: { render: () => null } }))
vi.mock('@/Components/Stats/RecentWorkoutsExercisesChart.vue', () => ({
    __esModule: true,
    default: { render: () => null },
}))
vi.mock('@/Components/Stats/RecentWorkoutsTimelineChart.vue', () => ({
    __esModule: true,
    default: { render: () => null },
}))
vi.mock('@/Components/Stats/TimeOfDayChart.vue', () => ({ __esModule: true, default: { render: () => null } }))
vi.mock('@/Components/Stats/WeeklyVolumeChart.vue', () => ({ __esModule: true, default: { render: () => null } }))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: () => {} }))

/** Le serveur de l'API, derrière `Utils/http` : ce qu'il a reçu, et ce qu'il rendra à chaque écriture. */
const api = vi.hoisted(() => ({
    requetes: [],
    /** @type {Array<(config: object) => Promise<object>>} */
    reponses: [],
    repondre: async (config) => {
        api.requetes.push({ method: config.method, url: config.url, data: config.data, headers: config.headers })

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
    'notifications.index': '/notifications',
    'calendar.index': '/calendar',
    'exercises.index': '/exercises',
    'tools.index': '/tools',
    'achievements.index': '/achievements',
    'shortcuts.index': '/shortcuts',
    'api.v1.workouts.store': '/api/v1/workouts',
    // Ce que l'ancien bouton visitait, pour que la contre-épreuve le joue jusqu'au bout.
    'workouts.store': '/workouts',
}

/** Ziggy : chaque nom à son chemin, la séance à son identifiant, et la page courante d'après l'adresse. */
const route = (nom, parametres) => {
    if (nom === undefined) {
        return { current: (motif) => (motif === undefined ? null : window.location.pathname === ROUTES[motif]) }
    }

    if (nom === 'workouts.show') {
        return `/workouts/${typeof parametres === 'object' ? parametres.workout : parametres}`
    }

    return ROUTES[nom]
}

/** L'accueil d'un compte sans séance en cours, comme le serveur le rend (sans ses props différées). */
const accueil = (props = {}) => ({
    component: 'Dashboard',
    url: '/dashboard',
    version: 'v1',
    props: {
        errors: {},
        flash: {},
        auth: { user: { id: 1, name: 'Samuel', unread_notifications_count: 0, active_workout: null } },
        recentWorkouts: [],
        recentPRs: [],
        activeGoals: [],
        ...props,
    },
    encryptHistory: true,
})

/** La séance que le serveur rend après la création. */
const seanceDuServeur = (id) => ({
    component: 'Workouts/Show',
    url: `/workouts/${id}`,
    version: 'v1',
    props: {
        auth: { user: { id: 1, name: 'Samuel' } },
        workout: { id, name: '19/10/2026', started_at: '2026-10-19T08:15:00.000000Z', workout_lines: [] },
        exercises: [],
    },
    encryptHistory: true,
})

/** La séance poussée, telle que le test la voit : ce qu'elle reçoit, et rien d'autre. */
const SeanceDuTest = {
    props: ['workout', 'exercises', 'bornesDUneSerie'],
    render() {
        return h('p', { id: 'seance' }, `séance ${this.workout.id} ${this.workout.name}`)
    },
}

let routeur
let file
let etatDuReseau
let application
let relais

/** La page qu'Inertia montre, telle que `usePage()` la lit : visites du client comprises. */
let page = null

const bouton = () => document.querySelector('nav[aria-label="Navigation principale"] button')
const seanceAffichee = () => document.getElementById('seance')?.textContent ?? null
const toasts = () =>
    [...document.querySelectorAll('[role="alert"][aria-live]')].map((toast) => toast.textContent.trim())
const fileStockee = () => JSON.parse(localStorage.getItem('offline_sync_queue') ?? '[]')

/** Remet l'accueil à l'écran, par une visite du client seul (#2044). */
const remettreLAccueil = async (props) => {
    let remise = false

    routeur.replace({ ...accueil(props), preserveState: false, onFinish: () => (remise = true) })
    await vi.waitUntil(() => remise && document.querySelector('nav[aria-label="Navigation principale"]') !== null)
}

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.stubGlobal('route', route)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

    localStorage.clear()
    sessionStorage.clear()
    window.history.replaceState(null, '', '/dashboard')

    // La file d'abord, puis tout le reste dans son graphe : la page, le client, l'état du réseau.
    file = await chargerSyncService({ compte: 1 })
    await file.pending

    etatDuReseau = await import('@/composables/useEtatDuReseau')

    const inertia = await import('@inertiajs/vue3')
    const { default: Accueil } = await import('@/Pages/Dashboard.vue')
    const { installerLesVisitesHorsLigne } = await import('@/Utils/visitesHorsLigne')
    routeur = inertia.router
    page = inertia.usePage()

    installerLesVisitesHorsLigne({ routeur, lireUneCopie: async () => null })

    document.body.replaceChildren(Object.assign(document.createElement('div'), { id: 'app' }))

    await inertia.createInertiaApp({
        page: accueil(),
        resolve: (nom) => (nom === 'Workouts/Show' ? SeanceDuTest : Accueil),
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) })
            application.config.globalProperties.route = route
            application.directive('press', {})
            application.use(plugin).mount(el)
        },
    })

    await vi.waitUntil(() => page.component === 'Dashboard')
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
    await remettreLAccueil()
    requetes.length = 0
    relais = relayerLesRejetsNonGeres()
})

afterEach(async () => {
    relais.retirer()
    vi.useRealTimers()
    await uneTache()
})

afterAll(() => {
    application.unmount()
    retirerLesEcouteursDuService()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    localStorage.clear()
})

describe('démarrer une séance sans réseau, depuis l’accueil', () => {
    it('ouvre aussitôt la séance en attente, à l’adresse de sa clé, sans requête, l’entrée en file avec l’heure du geste', async () => {
        vi.useFakeTimers({ toFake: ['Date'] })
        vi.setSystemTime(new Date('2026-10-19T08:15:00.000Z'))
        etatDuReseau.noterQueLeServeurNeRepondPas()

        bouton().click()
        // La séance, ou ce que disait l'ancien bouton : « Il faut le réseau pour démarrer une séance. »
        await vi.waitUntil(() => page.component === 'Workouts/Show' || toasts().length > 0)

        expect(toasts()).toEqual([])

        const [entree] = fileStockee()
        const cle = entree.headers['Idempotency-Key']

        expect(fileStockee()).toHaveLength(1)
        expect(entree).toMatchObject({
            method: 'post',
            url: '/api/v1/workouts',
            data: { started_at: '2026-10-19T08:15:00.000Z' },
            compte: '1',
        })
        expect(cle).toMatch(/^[0-9A-Za-z-]{1,64}$/)
        expect(window.location.pathname).toBe(`/workouts/en-attente/${cle}`)
        expect(page.url).toBe(`/workouts/en-attente/${cle}`)
        expect(page.props.workout).toMatchObject({ id: `temp-q${entree.id}`, idempotency_key: cle })
        expect(page.props).not.toHaveProperty('horsLigne')
        expect(seanceAffichee()).toBe(`séance temp-q${entree.id} ${page.props.workout.name}`)

        // L'entrée d'historique est chiffrée, comme celle de toute page d'un compte.
        await vi.waitUntil(() => window.history.state?.page instanceof ArrayBuffer)

        expect(requetes).toHaveLength(0)
        expect(api.requetes).toHaveLength(0)
        expect(relais.rejets).toEqual([])
    })

    it('ouvre la même séance au second appui, sans seconde création', async () => {
        etatDuReseau.noterQueLeServeurNeRepondPas()

        bouton().click()
        await vi.waitUntil(() => page.component === 'Workouts/Show')
        const premiere = page.url

        await remettreLAccueil()
        bouton().click()
        await vi.waitUntil(() => page.component === 'Workouts/Show')

        expect(page.url).toBe(premiere)
        expect(fileStockee()).toHaveLength(1)
        expect(api.requetes).toHaveLength(0)
    })

    it('depuis une copie de l’accueil, met la séance en file sans la tenter, le serveur joignable ou non', async () => {
        await remettreLAccueil({ horsLigne: { copieDu: Date.now(), raison: 'lent', datesDesProps: {} } })

        expect(bouton().disabled).toBe(false)

        bouton().click()
        await vi.waitUntil(() => page.component === 'Workouts/Show')

        expect(fileStockee().map((entree) => entree.url)).toEqual(['/api/v1/workouts'])
        expect(api.requetes).toHaveLength(0)
        expect(requetes).toHaveLength(0)
    })
})

describe('démarrer une séance en ligne', () => {
    it('crée la séance par un POST JSON, sous sa clé et à l’heure du geste, puis visite la séance créée', async () => {
        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(seanceDuServeur(42))
        api.reponses.push(async () => ({ data: { data: { id: 42, name: '19/10/2026' } }, status: 201 }))

        bouton().click()
        await vi.waitUntil(() => page.url === '/workouts/42')

        expect(api.requetes).toEqual([
            {
                method: 'post',
                url: '/api/v1/workouts',
                data: { started_at: expect.stringMatching(/^\d{4}-\d{2}-\d{2}T/) },
                headers: { 'Idempotency-Key': expect.stringMatching(/^[0-9A-Za-z-]{1,64}$/) },
            },
        ])
        expect(
            requetes.map((requete) => `${requete.methode} ${new URL(requete.url, window.location.href).pathname}`),
        ).toEqual(['GET /workouts/42'])
        expect(seanceAffichee()).toBe('séance 42 19/10/2026')
        expect(fileStockee()).toEqual([])
    })

    it('ouvre la séance déjà en cours quand le serveur la nomme (409)', async () => {
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

        bouton().click()
        await vi.waitUntil(() => page.url === '/workouts/9')

        expect(api.requetes).toHaveLength(1)
        expect(fileStockee()).toEqual([])
    })

    it('sans réponse, ouvre la séance en attente : la création, tentée, attend en file', async () => {
        bouton().click()
        await vi.waitUntil(() => page.component === 'Workouts/Show')

        const [entree] = fileStockee()

        expect(api.requetes).toHaveLength(1)
        expect(entree).toMatchObject({ url: '/api/v1/workouts', tentee: true })
        expect(entree).not.toHaveProperty('timeout')
        expect(page.url).toBe(`/workouts/en-attente/${entree.headers['Idempotency-Key']}`)
    })
})
