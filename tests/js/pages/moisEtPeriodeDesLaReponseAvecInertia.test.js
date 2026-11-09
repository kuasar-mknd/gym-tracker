import { describe, it, expect, vi, beforeAll, beforeEach, afterEach, afterAll } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { noterQueLeServeurRepond } from '@/composables/useEtatDuReseau'
import { nombre } from '@/Utils/nombre'
import { RequeteSimulee, reponseInertia, requetes, reseau, uneTache } from '../utils/reseauSimule'

/*
 * Le mois du calendrier et la période des statistiques se posent dès la
 * réponse (#2148, étape 2a), sous le vrai layout et avec le vrai client
 * d'Inertia.
 *
 * Ces deux visites gardent l'état de la page (`preserveState`) et changent son
 * adresse. Le layout enveloppait son contenu dans une transition `out-in` clée
 * sur l'adresse : la réponse arrivée, l'ancien mois sortait d'abord (200 ms,
 * deux images au moins même sans CSS), puis le nouveau entrait en 300 ms. Les
 * images sont retenues pendant que la réponse se pose : le nouveau contenu
 * doit être là sans qu'aucune passe.
 *
 * Plus rien ne remonte le contenu à chaque adresse : un graphique qui ne
 * suivrait pas ses props garderait l'ancienne période. Les cartes des
 * statistiques posent chaque graphique sous `<Deferred>`, qui revient à son
 * squelette quand la réponse annonce de nouveau `deferredData`, puis monte le
 * graphique avec les données de la nouvelle période ; le cas qui suit la
 * période jusqu'aux props différées le vérifie. Les graphiques sont remplacés
 * par un paragraphe qui écrit ses données : Chart.js n'a rien à faire ici.
 *
 * Le réseau est celui de `reseauSimule.js`, muet : chaque requête attend que le
 * test la serve.
 */

/** Un graphique de statistiques réduit aux données qu'il reçoit. */
const graphique = (nom) => ({
    __esModule: true,
    default: {
        name: nom,
        props: ['data'],
        render() {
            return h('p', { 'data-graphique': nom }, JSON.stringify(this.data ?? null))
        },
    },
})

vi.mock('@/Components/Stats/WeightHistoryChart.vue', () => graphique('WeightHistoryChart'))
vi.mock('@/Components/Stats/BodyFatChart.vue', () => graphique('BodyFatChart'))
vi.mock('@/Components/Stats/VolumeTrendChart.vue', () => graphique('VolumeTrendChart'))
vi.mock('@/Components/Stats/DurationHistoryChart.vue', () => graphique('DurationHistoryChart'))
vi.mock('@/Components/Stats/MuscleDistributionChart.vue', () => graphique('MuscleDistributionChart'))
vi.mock('@/Components/Stats/OneRepMaxChart.vue', () => graphique('OneRepMaxChart'))

/** Les erreurs que Vue a relevées, rendu compris. */
const erreurs = []

let routeur
let application

/** La route que Ziggy dirait courante. */
let routeCourante = 'calendar.index'

const route = (nom, parametres) => {
    if (nom === undefined) {
        return { current: (motif) => (motif === undefined ? routeCourante : motif === routeCourante) }
    }

    if (nom === 'calendar.index' && parametres !== undefined) {
        return `/calendar?year=${parametres.year}&month=${parametres.month}`
    }

    return `/${nom.replace('.index', '').replace(/\./g, '/')}`
}

/** Le compte que chaque page partage, sans séance en cours ni badge à célébrer. */
const partage = () => ({
    errors: {},
    flash: {},
    is_testing: false,
    auth: {
        user: {
            id: 1,
            name: 'Samuel',
            unread_notifications_count: 0,
            active_workout: null,
            latest_achievement: null,
        },
    },
})

/** Le calendrier d'un mois, avec une séance le 10. */
const calendrier = (mois) => ({
    component: 'Calendar/Index',
    url: `/calendar?year=2026&month=${mois}`,
    version: 'v',
    props: {
        ...partage(),
        year: 2026,
        month: mois,
        workouts: [
            {
                id: mois,
                date: `2026-${String(mois).padStart(2, '0')}-10`,
                name: `Séance du mois ${mois}`,
                exercises_count: 1,
                preview_exercises: [],
            },
        ],
        journals: [],
    },
})

/** Les statistiques d'une période, ses props différées annoncées et absentes. */
const statistiques = (periode, poids) => ({
    component: 'Stats/Index',
    url: periode === '30j' ? '/stats' : `/stats?period=${periode}`,
    version: 'v',
    props: {
        ...partage(),
        exercises: [],
        latestWeight: poids,
        weightChange: null,
        bodyFat: null,
        selectedPeriod: periode,
    },
    deferredProps: { default: ['deferredData'] },
})

/** Les props différées d'une période : chaque graphique y a ses propres points. */
const donneesDifferees = (periode) => ({
    performance: {
        volumeTrend: [{ date: periode, volume: periode === '7j' ? 700 : 3000 }],
        durationHistory: [{ date: periode, duration: 45 }],
        muscleDistribution: [{ category: `muscles ${periode}`, volume: 10 }],
        monthlyComparison: null,
    },
    body: {
        weightHistory: [{ date: periode, weight: 80 }],
        bodyFatHistory: [{ date: periode, body_fat: 15 }],
    },
})

/** La réponse partielle qui porte les props différées d'une page. */
const reponseDifferee = (page, periode) =>
    reponseInertia({
        component: page.component,
        url: page.url,
        version: 'v',
        props: { deferredData: donneesDifferees(periode) },
    })

const cheminEtRequete = (requete) => {
    const adresse = new URL(requete.url, window.location.href)

    return `${adresse.pathname}${adresse.search}`
}

/** Les requêtes déjà prises par le test, dans l'ordre de leur départ. */
let prises = 0

/** Attend la requête suivante, partie ou à venir, et la rend. */
const requeteSuivante = async () => {
    prises += 1
    const rang = prises

    await vi.waitUntil(() => requetes.length >= rang, { interval: 1 })

    return requetes[rang - 1]
}

/**
 * Sert la réponse de la requête `requete`, images retenues, puis rend la main
 * une fois la visite finie et ce que son `onSuccess` change rendu : aucune
 * image n'est passée entre la réponse et ce que le test lit ensuite.
 */
const servirSansImage = async (requete, reponse) => {
    const images = vi.spyOn(window, 'requestAnimationFrame').mockImplementation(() => 0)
    let finie = false
    const arreter = routeur.on('finish', () => (finie = true))

    try {
        requete.repondre(reponse)
        await vi.waitUntil(() => finie, { interval: 1 })
        await nextTick()
    } finally {
        arreter()
        images.mockRestore()
    }
}

/** Ouvre une page comme le serveur l'enverrait, sans ses props différées. */
const ouvrir = async (page) => {
    routeur.visit(page.url)
    await servirSansImage(await requeteSuivante(), reponseInertia(page))
}

const textes = (selecteur) => [...document.querySelectorAll(selecteur)].map((noeud) => noeud.textContent.trim())

const enTeteDuCalendrier = () => textes('[dusk="calendar-heading"]').map((texte) => texte.replace(/\s+/g, ' '))

const donneesDuGraphique = (nom) =>
    [...document.querySelectorAll(`[data-graphique="${nom}"]`)].map((noeud) => JSON.parse(noeud.textContent))

/** Le poids que la carte du poids corporel affiche. */
const poidsAffiche = () =>
    [...document.querySelectorAll('h3')]
        .find((titre) => titre.textContent.trim() === 'Poids Corporel')
        ?.nextElementSibling.textContent.replace(/\s+/g, ' ')
        .trim()

const boutonDePeriode = (libelle) =>
    [...document.querySelectorAll('[role="group"][aria-label="Période"] button')].find(
        (bouton) => bouton.textContent.trim() === libelle,
    )

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.stubGlobal('route', route)
    // jsdom ne fait pas défiler la page, et le dit à chaque visite.
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

    document.body.replaceChildren(Object.assign(document.createElement('div'), { id: 'app' }))
    window.history.replaceState(null, '', '/calendar?year=2026&month=3')
    noterQueLeServeurRepond()

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router
    const pages = {
        'Calendar/Index': (await import('@/Pages/Calendar/Index.vue')).default,
        'Stats/Index': (await import('@/Pages/Stats/Index.vue')).default,
    }

    await inertia.createInertiaApp({
        page: calendrier(3),
        resolve: (nom) => pages[nom],
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) })
            application.config.errorHandler = (erreur, _instance, ou) => erreurs.push(`${erreur} — ${ou}`)
            application.config.globalProperties.route = route
            application.directive('press', {})
            application.use(plugin).mount(el)
        },
    })
})

beforeEach(() => {
    reseau.mode = 'muet'
    reseau.prochains = []
    requetes.length = 0
    prises = 0
    erreurs.length = 0
})

afterEach(async () => {
    routeur.cancelAll()
    await uneTache()
})

afterAll(() => {
    application.unmount()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('le mois du calendrier', () => {
    it('pose le mois suivant dès la réponse, sans laisser passer une image, et seul', async () => {
        routeCourante = 'calendar.index'
        await ouvrir(calendrier(3))

        expect(enTeteDuCalendrier()).toEqual(['Mars 2026'])

        const suivant = document.querySelector('button[aria-label="Mois suivant"]')
        suivant.click()

        const visite = await requeteSuivante()

        expect(cheminEtRequete(visite)).toBe('/calendar?year=2026&month=4')

        await servirSansImage(visite, reponseInertia(calendrier(4)))

        expect(enTeteDuCalendrier()).toEqual(['Avril 2026'])
        expect(document.querySelector('[dusk="calendar-day-2026-04-10"]')?.getAttribute('aria-label')).toBe(
            '10 Avril 2026, séance',
        )
        // Le bouton n'a pas été remonté : le focus du clavier ne le quitte pas.
        expect(document.querySelector('button[aria-label="Mois suivant"]')).toBe(suivant)
        expect(erreurs).toEqual([])
    })
})

describe('la période des statistiques', () => {
    it('pose la nouvelle période dès la réponse, puis chaque graphique avec les données de cette période', async () => {
        routeCourante = 'stats.index'
        const trenteJours = statistiques('30j', 81)

        await ouvrir(trenteJours)
        await servirSansImage(await requeteSuivante(), reponseDifferee(trenteJours, '30j'))

        expect(poidsAffiche()).toBe(`${nombre(81)} kg`)
        expect(donneesDuGraphique('VolumeTrendChart')).toEqual([donneesDifferees('30j').performance.volumeTrend])

        boutonDePeriode('7 JOURS').click()

        const visite = await requeteSuivante()

        expect(cheminEtRequete(visite)).toBe('/stats?period=7j')

        const septJours = statistiques('7j', 79.5)
        await servirSansImage(visite, reponseInertia(septJours))

        // La valeur immédiate de la nouvelle période, dès sa réponse ; les graphiques attendent leurs props.
        expect(poidsAffiche()).toBe(`${nombre(79.5)} kg`)
        expect(donneesDuGraphique('VolumeTrendChart')).toEqual([])

        await servirSansImage(await requeteSuivante(), reponseDifferee(septJours, '7j'))

        const sept = donneesDifferees('7j')
        expect(donneesDuGraphique('VolumeTrendChart')).toEqual([sept.performance.volumeTrend])
        expect(donneesDuGraphique('DurationHistoryChart')).toEqual([sept.performance.durationHistory])
        expect(donneesDuGraphique('MuscleDistributionChart')).toEqual([sept.performance.muscleDistribution])
        expect(donneesDuGraphique('WeightHistoryChart')).toEqual([sept.body.weightHistory])
        expect(donneesDuGraphique('BodyFatChart')).toEqual([sept.body.bodyFatHistory])
        expect(document.body.textContent).toContain('7 derniers jours')
        expect(erreurs).toEqual([])
    })
})
