import { describe, it, expect, vi, beforeAll, beforeEach, afterEach, afterAll } from 'vitest'
import { createApp, h } from 'vue'
import {
    ECRITURE_NON_CONFIRMEE,
    SUPPRESSION_NON_CONFIRMEE,
    installerLesVisitesHorsLigne,
} from '@/Utils/visitesHorsLigne'
import {
    RequeteSimulee,
    relayerLesRejetsNonGeres,
    reponseInertia,
    requetes,
    reseau,
    uneTache,
} from '../utils/reseauSimule'

/*
 * Une suppression partie sans réseau, depuis une vraie page et avec le vrai
 * client d'Inertia (#2048).
 *
 * Le filet des visites disait de toute écriture que « l'enregistrement n'est
 * pas confirmé », suppression comprise. Décision du propriétaire du
 * 2026-10-15 : une suppression dit que la suppression n'est pas confirmée.
 * Les pages qui suppriment (`useConfirmation` puis `router.delete` ou
 * `useForm().delete`) n'ont pas de message à elles : c'est le filet qui parle
 * pour toutes, d'après la méthode de la visite.
 *
 * Le message part vers le toast du layout : la fenêtre de confirmation doit
 * donc se refermer à la fin de la visite, quelle qu'en soit l'issue, puisque
 * la fenêtre que `showModal()` ouvre passe au-dessus de toute la page. Celle
 * des objectifs ne se refermait que sur un succès.
 */

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
                    rendre('div', [slots['header-actions']?.(), slots.default?.(), rendre(ToastsDuLayout)]),
        },
    }
})

/*
 * Le graphique des objectifs n'a rien à voir avec la suppression, et il
 * chargerait Chart.js. `defineAsyncComponent` ne prend `default` que d'un
 * module qui se dit module (`__esModule`).
 */
vi.mock('@/Components/Stats/GoalTypeChart.vue', () => ({
    __esModule: true,
    default: { name: 'GoalTypeChart', render: () => null },
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
let pages

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
    pages = {
        'Workouts/Templates/Index': (await import('@/Pages/Workouts/Templates/Index.vue')).default,
        'Tools/IntervalTimer': (await import('@/Pages/Tools/IntervalTimer.vue')).default,
        'Goals/Index': (await import('@/Pages/Goals/Index.vue')).default,
        Dashboard: { render: () => h('p', 'Accueil') },
    }

    installerLesVisitesHorsLigne({ routeur })

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
    reseau.mode = 'coupe'
    reseau.prochains.length = 0
    relais = relayerLesRejetsNonGeres()
})

afterEach(() => {
    relais.retirer()
})

afterAll(() => {
    application.unmount()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    HTMLDialogElement.prototype.showModal = showModalDOrigine
    HTMLDialogElement.prototype.close = closeDOrigine
})

/** Ouvre `composant` comme le serveur l'enverrait, puis coupe le réseau. */
const ouvrirLaPage = async (composant, adresse, props) => {
    reseau.mode = 'repond'
    reseau.reponse = reponseInertia({ component: composant, url: adresse, version: 'v', props })

    await new Promise((resoudre) => routeur.visit(adresse, { onFinish: resoudre }))
    await uneTache()

    requetes.length = 0
    reseau.mode = 'coupe'
}

/** Attend la fin de la prochaine visite, puis une tâche. */
const attendreLaFinDeLaVisite = () => {
    let finie = false
    const retirer = routeur.on('finish', () => {
        finie = true
    })

    return async () => {
        await vi.waitUntil(() => finie)
        retirer()
        await uneTache()
    }
}

/** Les toasts du layout ; l'erreur cachée d'un champ porte aussi `role="alert"`, mais pas `aria-live`. */
const toastsAffiches = () =>
    [...document.querySelectorAll('[role="alert"][aria-live] p')].map((p) => p.textContent.trim())

const ongletNomme = (texte) =>
    [...document.querySelectorAll('button')].find((bouton) => bouton.textContent.trim() === texte)

const boutonNomme = (nom) =>
    [...document.querySelectorAll('button')].find((bouton) => bouton.getAttribute('aria-label') === nom)

/** Les fenêtres ouvertes par `showModal()`, au-dessus de tout le reste de la page. */
const fenetresOuvertes = () => [...document.querySelectorAll('dialog')].filter((fenetre) => fenetre.open)

describe('une suppression confirmée sans réseau', () => {
    it.each([
        [
            'Workouts/Templates/Index',
            '/templates',
            { templates: [{ id: 5, name: 'Jambes', workout_template_lines: [] }] },
            null,
            'Supprimer Jambes',
            '[dusk="confirm-dialog-confirm"]',
            'DELETE /templates.destroy/5',
        ],
        [
            'Tools/IntervalTimer',
            '/tools/interval-timer',
            {
                timers: [{ id: 9, name: 'Tabata', work_seconds: 20, rest_seconds: 10, rounds: 8, warmup_seconds: 10 }],
            },
            'Préréglages',
            'Supprimer le minuteur',
            '[dusk="confirm-dialog-confirm"]',
            'DELETE /tools.interval-timer.destroy/9',
        ],
        [
            'Goals/Index',
            '/goals',
            {
                goals: [
                    {
                        id: 4,
                        title: 'Squat 140kg',
                        type: 'weight',
                        unit: 'kg',
                        start_value: 100,
                        current_value: 120,
                        target_value: 140,
                        progress_pct: 50,
                        deadline: null,
                        completed_at: null,
                    },
                ],
                exercises: [],
                measurementTypes: [],
            },
            null,
            "Supprimer l'objectif Squat 140kg",
            '[dusk="confirm-delete-goal"]',
            'DELETE /goals.destroy/4',
        ],
    ])(
        '%s dit que la suppression n’est pas confirmée, fenêtre refermée',
        async (composant, adresse, props, onglet, bouton, confirmation, requete) => {
            await ouvrirLaPage(composant, adresse, { errors: {}, ...props })

            if (onglet !== null) {
                ongletNomme(onglet).click()
                await uneTache()
            }

            boutonNomme(bouton).click()
            await uneTache()

            expect(fenetresOuvertes()).toHaveLength(1)

            const fin = attendreLaFinDeLaVisite()
            document.querySelector(confirmation).click()
            await fin()

            expect(requetes.map((r) => `${r.methode.toUpperCase()} ${new URL(r.url).pathname}`)).toEqual([requete])
            expect(toastsAffiches()).toEqual([SUPPRESSION_NON_CONFIRMEE])
            expect(toastsAffiches()).not.toContain(ECRITURE_NON_CONFIRMEE)
            expect(relais.rejets).toEqual([])

            // La fenêtre se referme après son fondu de 200 ms ; ouverte, elle couvrirait le toast.
            await vi.waitUntil(() => fenetresOuvertes().length === 0).catch(() => {})
            expect(fenetresOuvertes(), 'la fenêtre de confirmation couvre encore le toast').toEqual([])
            expect(toastsAffiches()).toEqual([SUPPRESSION_NON_CONFIRMEE])
        },
    )
})
