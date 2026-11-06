import { describe, it, expect, vi, beforeAll, beforeEach, afterAll } from 'vitest'
import { createApp, h } from 'vue'
import { PREFIXE_DES_NAVIGATIONS, installerLeReleveDesTemps } from '@/Utils/releveDesTemps'
import { RequeteSimulee, reponseInertia, requetes, reseau } from './reseauSimule'

/*
 * Le relevé des temps avec le vrai client d'Inertia 3 (#2148, étape 1) : ce
 * que ses évènements disent vraiment d'une visite. Seule une visite au premier
 * plan donne une mesure de navigation ; un préchargement, une visite
 * asynchrone, un rechargement partiel et les props différées d'une page n'en
 * donnent aucune, et chaque requête porte la sorte que le client lui donne.
 *
 * Les entrées de `PerformanceResourceTiming` se fabriquent : jsdom n'en a pas.
 * Le relevé ne fait lui-même aucune requête : le réseau simulé ne voit que
 * celles du client.
 */

const ORIGINE = window.location.origin

/** Ce que l'observateur simulé du relevé reçoit, et les mesures posées. */
const observateurs = []
const mesures = []
let instant = 0

class ObservateurSimule {
    constructor(rappel) {
        this.rappel = rappel
    }

    observe() {
        observateurs.push(this)
    }

    disconnect() {
        observateurs.splice(observateurs.indexOf(this), 1)
    }
}

/** La fenêtre du relevé : la vraie, sauf la mesure du temps, que jsdom n'a pas. */
const fenetreDuReleve = () => ({
    location: window.location,
    document,
    console,
    navigator: window.navigator,
    localStorage: window.localStorage,
    PerformanceObserver: ObservateurSimule,
    performance: {
        now: () => instant,
        getEntriesByType: (type) =>
            type === 'navigation'
                ? [
                      {
                          name: `${ORIGINE}/dashboard`,
                          initiatorType: 'navigation',
                          requestStart: 1,
                          responseStart: 40,
                          workerStart: 0,
                          serverTiming: [{ name: 'app', duration: 20, description: '' }],
                      },
                  ]
                : [],
        mark: (nom) => ({ name: nom, startTime: instant }),
        measure: (nom, options) => mesures.push({ nom, ...options }),
    },
    requestAnimationFrame: (rappel) => setTimeout(rappel, 0),
    setTimeout,
})

/** Les pages que le faux serveur rend. */
const page = (composant, url, extra = {}) => ({
    component: composant,
    url,
    version: 'version-de-la-page',
    props: { errors: {}, titre: composant },
    ...extra,
})

const Page = {
    props: ['titre'],
    render() {
        return h('p', { id: 'titre' }, this.titre)
    },
}

let routeur
let installe

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', '/dashboard')

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router
    installe = installerLeReleveDesTemps({ routeur, fenetre: fenetreDuReleve() })

    await inertia.createInertiaApp({
        page: page('Dashboard', '/dashboard'),
        resolve: () => Page,
        setup: ({ el, App, props, plugin }) =>
            createApp({ render: () => h(App, props) })
                .use(plugin)
                .mount(el),
    })
})

beforeEach(async () => {
    reseau.mode = 'repond'
    reseau.prochains = []
    reseau.reponse = reponseInertia(page('Dashboard', '/dashboard'))

    let remise = false
    routeur.replace({ ...page('Dashboard', '/dashboard'), onFinish: () => (remise = true) })
    await vi.waitUntil(() => remise)

    routeur.flushAll()
    requetes.length = 0
    mesures.length = 0
})

afterAll(() => {
    installe?.desinstaller()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

/** Lance une visite et attend qu'elle ait fini, quelle qu'en soit l'issue. */
const visiter = async (adresse, options = {}) => {
    let finie = false

    routeur.visit(adresse, { ...options, onFinish: () => (finie = true) })
    await vi.waitUntil(() => finie)
}

/** Laisse passer deux images et ce qui les suit. */
const deuxImages = () => new Promise((resoudre) => setTimeout(() => setTimeout(resoudre, 5), 5))

/** Les mesures de navigation posées depuis le début du cas. */
const navigationsMesurees = () => mesures.map((mesure) => mesure.nom)

describe('la navigation perçue, avec le vrai client', () => {
    it('mesure une visite au premier plan, nommée par son chemin relatif', async () => {
        reseau.reponse = reponseInertia(page('Stats', '/stats?periode=mois'))
        let mesuresAuNavigate = null
        // Posé après celui du relevé, cet écouteur passe après lui : la page est là, pas encore peinte.
        const retirer = routeur.on('navigate', () => {
            mesuresAuNavigate = mesures.length
            instant = 180
        })

        try {
            instant = 100
            await visiter('/stats?periode=mois')
            await deuxImages()
        } finally {
            retirer()
        }

        expect(document.getElementById('titre').textContent).toBe('Stats')
        expect(mesuresAuNavigate).toBe(0)
        expect(mesures).toEqual([{ nom: `${PREFIXE_DES_NAVIGATIONS}/stats`, start: 100, end: 180 }])
    })

    it('ne mesure ni un préchargement, ni une visite asynchrone, ni un rechargement partiel', async () => {
        reseau.reponse = reponseInertia(page('Workouts', '/workouts'))
        let prechargee = false
        routeur.prefetch('/workouts', { onPrefetched: () => (prechargee = true) }, { cacheFor: 1000 })
        await vi.waitUntil(() => prechargee)

        reseau.reponse = reponseInertia(page('Dashboard', '/dashboard', { props: { errors: {}, titre: 'Accueil' } }))
        let rechargee = false
        routeur.reload({ only: ['titre'], onFinish: () => (rechargee = true) })
        await vi.waitUntil(() => rechargee)

        reseau.reponse = reponseInertia(page('Habits', '/habits'))
        await visiter('/habits', { async: true })
        await deuxImages()

        expect(requetes).toHaveLength(3)
        expect(window.location.pathname).toBe('/habits')
        expect(navigationsMesurees()).toEqual([])
    })

    it('ne mesure pas les props différées de la page, seulement la visite qui l’a ouverte', async () => {
        // La requête des props différées attend sa réponse, que le test donne : la même page la redemanderait sans fin.
        reseau.prochains = ['repond', 'muet']
        reseau.reponse = reponseInertia(
            page('Dashboard', '/dashboard?onglet=semaine', { deferredProps: { default: ['analyticalStats'] } }),
        )

        await visiter('/dashboard?onglet=semaine')
        await vi.waitUntil(() => requetes.length === 2)
        requetes[1].repondre(
            reponseInertia(
                page('Dashboard', '/dashboard?onglet=semaine', { props: { errors: {}, analyticalStats: {} } }),
            ),
        )
        await deuxImages()

        expect(requetes[1].entetes['X-Inertia-Partial-Data']).toBe('analyticalStats')
        expect(navigationsMesurees()).toEqual([`${PREFIXE_DES_NAVIGATIONS}/dashboard`])
    })

    it('nomme chaque requête par la sorte que le client lui donne, et n’en fait aucune lui-même', async () => {
        const envois = vi.spyOn(globalThis, 'fetch')

        reseau.reponse = reponseInertia(page('Stats', '/stats'))
        await visiter('/stats')

        reseau.reponse = reponseInertia(page('Stats', '/stats'))
        let rechargee = false
        routeur.reload({ only: ['titre'], onFinish: () => (rechargee = true) })
        await vi.waitUntil(() => rechargee)

        reseau.reponse = reponseInertia(page('Workouts', '/workouts'))
        let prechargee = false
        routeur.prefetch('/workouts', { onPrefetched: () => (prechargee = true) }, { cacheFor: 1000 })
        await vi.waitUntil(() => prechargee)

        expect(requetes.map((requete) => requete.url)).toEqual([
            `${ORIGINE}/stats`,
            `${ORIGINE}/stats`,
            `${ORIGINE}/workouts`,
        ])

        // Ce que le navigateur relèverait de ces trois requêtes, dans leur ordre.
        for (const observateur of observateurs) {
            observateur.rappel({
                getEntries: () =>
                    requetes.map((requete) => ({
                        name: requete.url,
                        initiatorType: 'xmlhttprequest',
                        requestStart: 10,
                        responseStart: 50,
                        workerStart: 0,
                        serverTiming: [],
                    })),
            })
        }

        expect(
            installe
                .releve()
                .requetes.map((ligne) => ligne.etiquette)
                .slice(-3),
        ).toEqual(['GET /stats (visite)', 'GET /stats (partielle)', 'GET /workouts (préchargement)'])
        expect(envois).not.toHaveBeenCalled()
    })
})
