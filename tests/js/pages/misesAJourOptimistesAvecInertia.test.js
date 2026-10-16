import { describe, it, expect, vi, beforeAll, beforeEach, afterEach, afterAll } from 'vitest'
import { createApp, h } from 'vue'
import {
    EVENEMENT_SANS_RESEAU,
    SUPPRESSION_NON_CONFIRMEE,
    installerLesVisitesHorsLigne,
} from '@/Utils/visitesHorsLigne'
import { REPOS_AUTOMATIQUE_SANS_RESEAU, useMinuteurDeRepos } from '@/composables/useMinuteurDeRepos'
import {
    RequeteSimulee,
    relayerLesRejetsNonGeres,
    reponseInertia,
    requetes,
    reseau,
    uneTache,
} from '../utils/reseauSimule'

/*
 * Une mise à jour optimiste revient sur tout échec, avec le vrai client
 * d'Inertia (#2077).
 *
 * Les séances et la bibliothèque retiraient la ligne avant la réponse et ne la
 * remettaient que dans `onError`, qu'Inertia n'appelle que sur des erreurs de
 * validation. Sans réseau (`networkError`), sur une réponse en erreur sans
 * erreurs de validation (`httpException` : 403, 419, 500…), sur une visite
 * annulée (`router.cancelAll()`) ou interrompue par une autre visite, la
 * ligne restait partie alors que rien n'était supprimé, jusqu'au prochain
 * chargement. Le calendrier, la période des statistiques et l'interrupteur du
 * repos automatique avaient le même défaut : leur état ne revenait que sur
 * `onError`, sur `onNetworkError`, ou jamais.
 *
 * Le client d'Inertia se monte une fois pour le fichier ; chaque cas rouvre sa
 * page par une visite, comme le serveur l'enverrait.
 */

const vibrations = vi.hoisted(() => [])

vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: (type) => vibrations.push(type) }))

/** Le layout réduit à ce que la page y pose, et aux vrais toasts du layout. */
vi.mock('@/Layouts/AuthenticatedLayout.vue', async () => {
    const { h: rendre } = await vi.importActual('vue')
    const ToastsDuLayout = (await import('@/Components/UI/ToastsDuLayout.vue')).default

    return {
        default: {
            name: 'AuthenticatedLayout',
            setup:
                (_, { slots }) =>
                () =>
                    rendre('div', [
                        slots.header?.(),
                        slots['header-actions']?.(),
                        slots.default?.(),
                        rendre(ToastsDuLayout),
                    ]),
        },
    }
})

/*
 * Les graphiques n'ont rien à voir avec ce qui se joue ici, et ils
 * chargeraient Chart.js. `defineAsyncComponent` ne prend `default` que d'un
 * module qui se dit module (`__esModule`).
 */
vi.mock('@/Components/Stats/WorkoutHistoryTimelineChart.vue', () => ({
    __esModule: true,
    default: { name: 'WorkoutHistoryTimelineChart', render: () => null },
}))
vi.mock('@/Components/Stats/WorkoutFrequencyChart.vue', () => ({
    __esModule: true,
    default: { name: 'WorkoutFrequencyChart', render: () => null },
}))
vi.mock('@/Components/Stats/WorkoutsPerMonthChart.vue', () => ({
    __esModule: true,
    default: { name: 'WorkoutsPerMonthChart', render: () => null },
}))
vi.mock('@/Components/Stats/MonthlyVolumeChart.vue', () => ({
    __esModule: true,
    default: { name: 'MonthlyVolumeChart', render: () => null },
}))
vi.mock('@/Components/Stats/WorkoutDurationChart.vue', () => ({
    __esModule: true,
    default: { name: 'WorkoutDurationChart', render: () => null },
}))
vi.mock('@/Components/Stats/VolumePerWorkoutChart.vue', () => ({
    __esModule: true,
    default: { name: 'VolumePerWorkoutChart', render: () => null },
}))
vi.mock('@/Components/Stats/ExerciseCategoryChart.vue', () => ({
    __esModule: true,
    default: { name: 'ExerciseCategoryChart', render: () => null },
}))
vi.mock('@/Components/Stats/WeightHistoryChart.vue', () => ({
    __esModule: true,
    default: { name: 'WeightHistoryChart', render: () => null },
}))
vi.mock('@/Components/Stats/BodyFatChart.vue', () => ({
    __esModule: true,
    default: { name: 'BodyFatChart', render: () => null },
}))
vi.mock('@/Components/Stats/VolumeTrendChart.vue', () => ({
    __esModule: true,
    default: { name: 'VolumeTrendChart', render: () => null },
}))
vi.mock('@/Components/Stats/DurationHistoryChart.vue', () => ({
    __esModule: true,
    default: { name: 'DurationHistoryChart', render: () => null },
}))
vi.mock('@/Components/Stats/MuscleDistributionChart.vue', () => ({
    __esModule: true,
    default: { name: 'MuscleDistributionChart', render: () => null },
}))
vi.mock('@/Components/Stats/OneRepMaxChart.vue', () => ({
    __esModule: true,
    default: { name: 'OneRepMaxChart', render: () => null },
}))

/*
 * jsdom n'a pas de `<dialog>` modal : ces deux-là jouent ce qui compte ici,
 * `open` que `showModal()` et `close()` basculent, comme dans
 * `modalScrollLock.test.js`.
 */
const showModalDOrigine = HTMLDialogElement.prototype.showModal
const closeDOrigine = HTMLDialogElement.prototype.close

let routeur
let application
let relais

/** Ce que les visites ont annoncé au toast d'erreur des layouts. */
const annonces = []
const ecouterLesAnnonces = (evenement) => {
    if (evenement.detail.message !== null) {
        annonces.push(evenement.detail.message)
    }
}

/** Une page de test qui tient le réglage du repos automatique, comme la séance. */
const ReglageDuRepos = {
    name: 'ReglageDuRepos',
    setup() {
        const { autoRestTimer, setAutoRestTimer } = useMinuteurDeRepos()

        return () =>
            h(
                'button',
                { id: 'repos-automatique', onClick: () => setAutoRestTimer(!autoRestTimer.value) },
                autoRestTimer.value ? 'activé' : 'coupé',
            )
    },
}

beforeAll(async () => {
    HTMLDialogElement.prototype.showModal = function showModal() {
        this.open = true
    }
    HTMLDialogElement.prototype.close = function close() {
        if (!this.open) {
            return
        }

        this.open = false
        this.dispatchEvent(new Event('close'))
    }
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    // jsdom ne fait pas défiler la page, et le dit à chaque visite.
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', '/dashboard')
    // Un paramètre seul (`route('…destroy', id)`) ou un objet de paramètres, comme Ziggy.
    globalThis.route = (nom, parametres = {}) =>
        `/${nom}${(typeof parametres === 'object' ? Object.values(parametres) : [parametres])
            .map((valeur) => `/${valeur}`)
            .join('')}`

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router
    const pages = {
        'Workouts/Index': (await import('@/Pages/Workouts/Index.vue')).default,
        'Exercises/Index': (await import('@/Pages/Exercises/Index.vue')).default,
        'Calendar/Index': (await import('@/Pages/Calendar/Index.vue')).default,
        'Stats/Index': (await import('@/Pages/Stats/Index.vue')).default,
        ReglageDuRepos,
        Dashboard: { render: () => h('p', 'Accueil') },
    }

    installerLesVisitesHorsLigne({ routeur })
    window.addEventListener(EVENEMENT_SANS_RESEAU, ecouterLesAnnonces)

    await inertia.createInertiaApp({
        page: { component: 'Dashboard', url: '/dashboard', version: 'v', props: { errors: {} } },
        resolve: (nom) => pages[nom],
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) })
            application.directive('press', {})
            application.config.globalProperties.route = globalThis.route
            application.use(plugin).mount(el)
        },
    })
})

beforeEach(() => {
    requetes.length = 0
    vibrations.length = 0
    annonces.length = 0
    reseau.mode = 'coupe'
    reseau.prochains.length = 0
    reseau.reponse = null
    relais = relayerLesRejetsNonGeres()
})

afterEach(() => {
    relais.retirer()
    // La fenêtre d'erreur qu'Inertia ouvre sur une réponse qui n'est pas une page.
    document.getElementById('inertia-error-dialog')?.remove()
    document.body.style.overflow = ''
})

afterAll(() => {
    window.removeEventListener(EVENEMENT_SANS_RESEAU, ecouterLesAnnonces)
    application.unmount()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    HTMLDialogElement.prototype.showModal = showModalDOrigine
    HTMLDialogElement.prototype.close = closeDOrigine
})

/** Ouvre `composant` comme le serveur l'enverrait. */
const ouvrirLaPage = async (composant, adresse, props) => {
    reseau.mode = 'repond'
    reseau.reponse = reponseInertia({ component: composant, url: adresse, version: 'v', props })

    await new Promise((resoudre) => routeur.visit(adresse, { onFinish: resoudre }))
    await uneTache()

    requetes.length = 0
    annonces.length = 0
    reseau.reponse = null
    reseau.mode = 'muet'
}

/** Attend que toutes les visites en vol soient finies, puis une tâche. */
const attendreLesVisites = async () => {
    await vi.waitUntil(() => requetes.every((requete) => requete.finie))
    await uneTache()
}

/** La réponse d'une page d'erreur de Laravel : du HTML, pas une page d'Inertia. */
const pageDErreur = (statut) => ({
    statut,
    entetes: { 'content-type': 'text/html; charset=UTF-8' },
    corps: `<!DOCTYPE html><html><body><h1>${statut}</h1></body></html>`,
})

/** Les toasts du layout ; l'erreur cachée d'un champ porte aussi `role="alert"`, mais pas `aria-live`. */
const toastsAffiches = () =>
    [...document.querySelectorAll('[role="alert"][aria-live] p')].map((p) => p.textContent.trim())

const seance = (id) => ({
    id,
    name: `Séance ${id}`,
    started_at: `2026-03-0${id}T10:00:00Z`,
    workout_lines: [],
})

const exercice = (id) => ({ id, name: `Exercice ${id}`, type: 'strength', category: 'Pectoraux' })

/** Les deux pages qui retirent une ligne avant la réponse. */
const PAGES = [
    {
        nom: 'Workouts/Index',
        adresse: '/workouts',
        props: (ids = [1, 2, 3, 4]) => ({
            errors: {},
            workouts: { data: ids.map(seance), total: ids.length, current_page: 1, last_page: 1 },
            totalExercises: 0,
        }),
        lignes: () =>
            [...document.querySelectorAll('[dusk^="delete-workout-"]')].map((bouton) =>
                Number(bouton.getAttribute('dusk').split('-').pop()),
            ),
        bouton: (id) => `[dusk="delete-workout-${id}"]`,
        requete: (id) => `DELETE /workouts.destroy/${id}`,
    },
    {
        nom: 'Exercises/Index',
        adresse: '/exercises',
        props: (ids = [1, 2, 3, 4]) => ({ errors: {}, exercises: ids.map(exercice) }),
        lignes: () =>
            [...document.querySelectorAll('[dusk^="exercise-card-"]')].map((carte) =>
                Number(carte.getAttribute('dusk').split('-').pop()),
            ),
        bouton: (id) => `[dusk="delete-exercise-btn-${id}"]`,
        requete: (id) => `DELETE /exercises.destroy/${id}`,
    },
]

/** Demande la suppression de la ligne `id`, puis la confirme. */
const supprimer = async (page, id) => {
    document.querySelector(page.bouton(id)).click()
    await uneTache()
    document.querySelector('[dusk="confirm-dialog-confirm"]').click()
    await uneTache()
}

const requetesParties = () => requetes.map((r) => `${r.methode.toUpperCase()} ${new URL(r.url).pathname}`)

/*
 * Chaque requête simulée se dit finie quand elle a répondu, échoué ou été
 * abandonnée : le test attend ainsi la fin de toutes les visites, celle qui
 * supprime comme celle qui l'interrompt.
 */
beforeAll(() => {
    const envoyer = RequeteSimulee.prototype.send
    const repondre = RequeteSimulee.prototype.repondre
    const abandonner = RequeteSimulee.prototype.abort

    RequeteSimulee.prototype.send = function send(corps) {
        const onerror = () => {
            this.finie = true
            this.surErreur?.()
        }
        this.surErreur = this.onerror
        this.onerror = onerror
        envoyer.call(this, corps)
    }
    RequeteSimulee.prototype.repondre = function repondreEtFinir(reponse) {
        this.finie = true
        repondre.call(this, reponse)
    }
    RequeteSimulee.prototype.abort = function abandonnerEtFinir() {
        this.finie = true
        abandonner.call(this)
    }
})

const ECHECS = [
    ['sans réseau', (requete) => requete.onerror()],
    ['sur un 500', (requete) => requete.repondre(pageDErreur(500))],
    ['sur un 403', (requete) => requete.repondre(pageDErreur(403))],
    ['sur un 419', (requete) => requete.repondre(pageDErreur(419))],
    ['sur un 503', (requete) => requete.repondre(pageDErreur(503))],
]

describe.each(PAGES)('$nom : une ligne retirée avant la réponse', (page) => {
    it.each(ECHECS)('revient à sa place exacte %s, avec la vibration d’erreur', async (_cas, echouer) => {
        await ouvrirLaPage(page.nom, page.adresse, page.props())

        await supprimer(page, 2)

        expect(page.lignes()).toEqual([1, 3, 4])
        expect(requetesParties()).toEqual([page.requete(2)])

        echouer(requetes[0])
        await attendreLesVisites()

        expect(page.lignes()).toEqual([1, 2, 3, 4])
        expect(vibrations).toEqual(['warning', 'error'])
        expect(relais.rejets).toEqual([])
    })

    it('sans réseau, le filet dit une fois que la suppression n’est pas confirmée', async () => {
        await ouvrirLaPage(page.nom, page.adresse, page.props())

        await supprimer(page, 2)
        requetes[0].onerror()
        await attendreLesVisites()

        expect(page.lignes()).toEqual([1, 2, 3, 4])
        expect(annonces).toEqual([SUPPRESSION_NON_CONFIRMEE])
        expect(toastsAffiches()).toEqual([SUPPRESSION_NON_CONFIRMEE])
    })

    it('revient sur un refus de validation, comme avant', async () => {
        await ouvrirLaPage(page.nom, page.adresse, page.props())

        await supprimer(page, 2)
        requetes[0].repondre(
            reponseInertia({
                component: page.nom,
                url: page.adresse,
                version: 'v',
                props: { ...page.props(), errors: { exercise: 'Cet exercice sert encore.' } },
            }),
        )
        await attendreLesVisites()

        expect(page.lignes()).toEqual([1, 2, 3, 4])
        expect(vibrations).toEqual(['warning', 'error'])
    })

    it('revient quand la visite est annulée', async () => {
        await ouvrirLaPage(page.nom, page.adresse, page.props())

        await supprimer(page, 2)
        routeur.cancelAll()
        await attendreLesVisites()

        expect(requetes[0].annulee).toBe(true)
        expect(page.lignes()).toEqual([1, 2, 3, 4])
        expect(vibrations).toEqual(['warning', 'error'])
    })

    it('revient quand une autre visite l’interrompt', async () => {
        await ouvrirLaPage(page.nom, page.adresse, page.props())

        await supprimer(page, 2)
        routeur.visit(`${page.adresse}?page=1`, { preserveState: true, preserveScroll: true })
        await uneTache()

        expect(requetes[0].annulee).toBe(true)
        expect(page.lignes()).toEqual([1, 2, 3, 4])

        routeur.cancelAll()
        await attendreLesVisites()
    })

    /*
     * La seconde suppression interrompt la première (deux visites au premier
     * plan), puis échoue à son tour : chacune revient à sa place, alors
     * qu'un retour par l'indice de départ remettait la seconde avant la
     * première.
     */
    it('deux suppressions qui échouent l’une après l’autre reviennent chacune à sa place', async () => {
        await ouvrirLaPage(page.nom, page.adresse, page.props())

        await supprimer(page, 2)
        await supprimer(page, 3)

        expect(page.lignes()).toEqual([1, 2, 4])

        requetes[1].onerror()
        await attendreLesVisites()

        expect(page.lignes()).toEqual([1, 2, 3, 4])
    })

    it('reste partie quand le serveur l’a supprimée', async () => {
        await ouvrirLaPage(page.nom, page.adresse, page.props())

        await supprimer(page, 2)
        requetes[0].repondre(
            reponseInertia({ component: page.nom, url: page.adresse, version: 'v', props: page.props([1, 3, 4]) }),
        )
        await attendreLesVisites()

        expect(page.lignes()).toEqual([1, 3, 4])
        expect(vibrations).toEqual(['warning'])
        expect(toastsAffiches()).toEqual([])
    })
})

/*
 * Les graphiques des séances arrivent par un rechargement partiel, juste après
 * l'ouverture de la page. L'option `optimistic` d'Inertia y perd sa base
 * (`tests/js/utils/miseAJourOptimiste.test.js`) ; la page, elle, remet la
 * ligne même quand ce rechargement arrive pendant la suppression.
 */
it('Workouts/Index : la ligne revient même quand les graphiques arrivent pendant la suppression', async () => {
    const page = PAGES[0]
    await ouvrirLaPage(page.nom, page.adresse, page.props())

    await supprimer(page, 2)
    routeur.reload({ only: ['deferredData'] })
    await uneTache()
    requetes[1].repondre(
        reponseInertia({
            component: page.nom,
            url: page.adresse,
            version: 'v',
            props: {
                deferredData: {
                    charts: { monthly_frequency: [], monthly_volume: [], duration_history: [], volume_history: [] },
                    exercises: [],
                },
            },
        }),
    )
    await uneTache()

    expect(page.lignes()).toEqual([1, 3, 4])

    requetes[0].repondre(pageDErreur(500))
    await attendreLesVisites()

    expect(page.lignes()).toEqual([1, 2, 3, 4])
})

describe('Calendar/Index : le mois demandé', () => {
    const ouvrirMars = () =>
        ouvrirLaPage('Calendar/Index', '/calendar', { errors: {}, year: 2026, month: 3, workouts: [], journals: [] })

    const moisSuivant = async () => {
        document.querySelector('[aria-label="Mois suivant"]').click()
        await uneTache()
    }

    const moisDemandes = () => requetes.map((requete) => new URL(requete.url).pathname)

    it.each(ECHECS)('se compte de nouveau depuis le mois affiché après un échec %s', async (_cas, echouer) => {
        await ouvrirMars()

        await moisSuivant()
        echouer(requetes[0])
        await attendreLesVisites()
        document.getElementById('inertia-error-dialog')?.remove()

        await moisSuivant()
        routeur.cancelAll()
        await attendreLesVisites()

        expect(moisDemandes()).toEqual(['/calendar.index/2026/4', '/calendar.index/2026/4'])
    })

    it('garde le compte des appuis quand un appui interrompt le précédent, et l’oublie si le dernier échoue', async () => {
        await ouvrirMars()

        await moisSuivant()
        await moisSuivant()
        requetes[1].onerror()
        await attendreLesVisites()

        await moisSuivant()
        routeur.cancelAll()
        await attendreLesVisites()

        expect(moisDemandes()).toEqual(['/calendar.index/2026/4', '/calendar.index/2026/5', '/calendar.index/2026/4'])
    })
})

describe('Stats/Index : la période choisie', () => {
    const periodeRetenue = () =>
        [...document.querySelectorAll('button[aria-pressed="true"]')].map((bouton) => bouton.textContent.trim())

    const choisir = async (libelle) => {
        ;[...document.querySelectorAll('button')].find((bouton) => bouton.textContent.trim() === libelle).click()
        await uneTache()
    }

    it.each([...ECHECS, ['annulée', () => routeur.cancelAll()]])(
        'revient à celle que la page montre %s',
        async (_cas, echouer) => {
            await ouvrirLaPage('Stats/Index', '/stats', { errors: {}, exercises: [], selectedPeriod: '30j' })

            await choisir('1 AN')
            expect(periodeRetenue()).toEqual(['1 AN'])

            echouer(requetes[0])
            await attendreLesVisites()

            expect(periodeRetenue()).toEqual(['30 JOURS'])
        },
    )

    it('reste sur la nouvelle quand le serveur l’a rendue', async () => {
        await ouvrirLaPage('Stats/Index', '/stats', { errors: {}, exercises: [], selectedPeriod: '30j' })

        await choisir('1 AN')
        requetes[0].repondre(
            reponseInertia({
                component: 'Stats/Index',
                url: '/stats?period=1a',
                version: 'v',
                props: { exercises: [], selectedPeriod: '1a' },
            }),
        )
        await attendreLesVisites()

        expect(periodeRetenue()).toEqual(['1 AN'])
    })
})

describe('le repos automatique', () => {
    const ouvrirLeReglage = (active = true) =>
        ouvrirLaPage('ReglageDuRepos', '/workouts/12', {
            errors: {},
            auth: { user: { id: 1, auto_rest_timer: active, default_rest_time: 90 } },
        })

    const interrupteur = () => document.getElementById('repos-automatique')

    it.each([
        ...ECHECS,
        ['annulé', () => routeur.cancelAll()],
        [
            'refusé par la validation',
            (requete) =>
                requete.repondre(
                    reponseInertia({
                        component: 'ReglageDuRepos',
                        url: '/workouts/12',
                        version: 'v',
                        props: {
                            errors: { auto_rest_timer: 'Invalide.' },
                            auth: { user: { id: 1, auto_rest_timer: true, default_rest_time: 90 } },
                        },
                    }),
                ),
        ],
    ])('revient où il était %s', async (_cas, echouer) => {
        await ouvrirLeReglage()

        interrupteur().click()
        await uneTache()
        expect(interrupteur().textContent).toBe('coupé')

        echouer(requetes[0])
        await attendreLesVisites()

        expect(interrupteur().textContent).toBe('activé')
    })

    it('sans réseau, le dit une fois', async () => {
        await ouvrirLeReglage()

        interrupteur().click()
        await uneTache()
        requetes[0].onerror()
        await attendreLesVisites()

        expect(interrupteur().textContent).toBe('activé')
        expect(annonces).toEqual([REPOS_AUTOMATIQUE_SANS_RESEAU])
        expect(relais.rejets).toEqual([])
    })

    it('garde le réglage que le serveur a accepté', async () => {
        await ouvrirLeReglage()

        interrupteur().click()
        await uneTache()
        requetes[0].repondre(
            reponseInertia({
                component: 'ReglageDuRepos',
                url: '/workouts/12',
                version: 'v',
                props: { auth: { user: { id: 1, auto_rest_timer: false, default_rest_time: 90 } } },
            }),
        )
        await attendreLesVisites()

        expect(interrupteur().textContent).toBe('coupé')
    })
})
