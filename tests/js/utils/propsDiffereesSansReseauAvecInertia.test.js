import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'

import { creerUneFabriqueEnMemoire } from './baseIndexeeEnMemoire'
import { RequeteSimulee, relayerLesRejetsNonGeres, reponseInertia, requetes, reseau, uneTache } from './reseauSimule'
import IndisponibleHorsLigne from '@/Components/UI/IndisponibleHorsLigne.vue'
import { CLEF_DU_TITULAIRE_DE_L_APPAREIL } from '@/Utils/historiqueDuCompte'

/*
 * Les props différées d'une page, sans réseau (#2048, partie E), avec le vrai
 * client d'Inertia et le vrai `<Deferred>`. Une page du réseau demande ses
 * props différées juste après son affichage, un groupe par requête : sans
 * réseau, `<Deferred>` montrait son squelette sans fin. Le groupe en échec
 * passe dans `rescuedProps`, sans relancer son chargement (une requête par
 * groupe, pas une de plus), le créneau `#rescue` dit « Indisponible hors
 * ligne », et la page les redemande une fois au retour du réseau.
 *
 * Le client se monte une fois pour le fichier ; chaque cas repart d'une page
 * sans props différées, remise par une visite du client seul (#2044).
 */

const COMPTE = 7

/** Le compte que chaque page déclare. */
const auth = () => ({ user: { id: COMPTE, name: 'Camille Horsligne', active_workout: null, latest_achievement: null } })

/** La page d'où partent les cas : sans props différées. */
const pageDeDepart = () => ({
    component: 'Tools/Index',
    url: '/tools',
    version: 'version-de-la-page',
    encryptHistory: true,
    clearHistory: false,
    props: { errors: {}, auth: auth() },
})

/** L'accueil tel que le serveur le rend en entier : deux groupes de props différées. */
const accueilDuServeur = () => ({
    component: 'Dashboard',
    url: '/dashboard',
    version: 'version-de-la-page',
    encryptHistory: true,
    clearHistory: false,
    props: { errors: {}, auth: auth(), recentWorkouts: [] },
    flash: { proposerLesNotifications: true },
    deferredProps: { default: ['analyticalStats'], objectifs: ['objectifsDuJour'] },
})

/** La réponse partielle aux props différées : la forme de la page, sans rien annoncer de plus. */
const reponseDesDifferees = (props) =>
    reponseInertia({
        component: 'Dashboard',
        url: '/dashboard',
        version: 'version-de-la-page',
        encryptHistory: true,
        props,
    })

let routeur
let Deferred
let fabrique
let magasin
let etatDuReseau
let application
let relais
let pageInertia

const annonces = []
const ecouterLesAnnonces = (evenement) => annonces.push(evenement.detail)

/** Les requêtes qui ont demandé des props différées ou partielles. */
const requetesPartielles = () => requetes.filter((requete) => requete.entetes['X-Inertia-Partial-Data'] !== undefined)

const indisponibles = () => document.querySelectorAll('[data-indisponible-hors-ligne]').length

/** Une page qui montre ses props différées, comme l'accueil. */
const Accueil = {
    render() {
        const differee = (nom, texte) =>
            h(
                Deferred,
                { data: nom },
                {
                    fallback: () => h('p', { class: 'chargement' }, 'Chargement'),
                    rescue: () => h(IndisponibleHorsLigne),
                    default: () => h('p', { class: 'chargee' }, texte),
                },
            )

        return h('div', [differee('analyticalStats', 'Statistiques'), differee('objectifsDuJour', 'Objectifs')])
    },
}

const Outils = { render: () => h('p', 'Outils') }

/** Visite l'accueil, que le serveur rend ; ses props différées partent ensuite sur `reseau.mode`. */
const ouvrirLAccueil = async () => {
    let finie = false

    reseau.prochains.push('repond')
    reseau.reponse = reponseInertia(accueilDuServeur())
    routeur.visit('/dashboard', { onFinish: () => (finie = true) })
    await vi.waitUntil(() => finie)
}

/** Laisse passer un moment, assez pour qu'une boucle de requêtes se voie. */
const unMoment = () => new Promise((resoudre) => setTimeout(resoudre, 250))

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', '/tools')
    globalThis.route = (nom) => `/${nom}`

    const { creerLeMagasinDesCopies, ouvrirLaBaseDuNavigateur } = await import('@/Utils/copiesHorsLigne')

    fabrique = creerUneFabriqueEnMemoire()
    magasin = creerLeMagasinDesCopies({ ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique) })
    etatDuReseau = await import('@/composables/useEtatDuReseau')

    const visites = await import('@/Utils/visitesHorsLigne')
    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router
    Deferred = inertia.Deferred

    visites.installerLesVisitesHorsLigne({ routeur, lireUneCopie: (adresse) => magasin.lireUneCopie(adresse) })
    routeur.on('navigate', (evenement) => (pageInertia = evenement.detail.page))
    routeur.on('clientVisit', (evenement) => (pageInertia = evenement.detail.page))
    routeur.on('success', (evenement) => (pageInertia = evenement.detail.page))

    await inertia.createInertiaApp({
        page: pageDeDepart(),
        resolve: (nom) => (nom === 'Dashboard' ? Accueil : Outils),
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) }).use(plugin)
            application.mount(el)
        },
    })

    await vi.waitUntil(() => pageInertia !== undefined)
    window.addEventListener(visites.EVENEMENT_SANS_RESEAU, ecouterLesAnnonces)
})

beforeEach(async () => {
    await new Promise((fini) => routeur.replace({ ...pageDeDepart(), onFinish: fini }))

    requetes.length = 0
    annonces.length = 0
    reseau.mode = 'coupe'
    reseau.prochains.length = 0
    reseau.reponse = null
    localStorage.clear()
    etatDuReseau.noterQueLeServeurRepond()
    relais = relayerLesRejetsNonGeres()
})

afterEach(async () => {
    relais.retirer()
    routeur.cancelAll()
    await uneTache()
})

afterAll(async () => {
    application.unmount()
    await magasin.fermer()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('une prop différée sans réseau', () => {
    it('ne coûte qu’une requête par groupe, et dit « Indisponible hors ligne » sans un mot de plus', async () => {
        await ouvrirLAccueil()
        await vi.waitUntil(() => indisponibles() === 2)
        await unMoment()

        expect(
            requetesPartielles()
                .map((requete) => requete.entetes['X-Inertia-Partial-Data'])
                .sort(),
        ).toEqual(['analyticalStats', 'objectifsDuJour'])
        expect(requetes).toHaveLength(3)
        expect(pageInertia.rescuedProps.sort()).toEqual(['analyticalStats', 'objectifsDuJour'])
        expect(pageInertia.deferredProps).toBeUndefined()
        expect(pageInertia.initialDeferredProps).toEqual(accueilDuServeur().deferredProps)
        // Le message d'un instant que la page avait reçu reste le sien.
        expect(pageInertia.flash).toEqual({ proposerLesNotifications: true })
        expect(document.querySelectorAll('.chargement')).toHaveLength(0)
        expect(document.querySelector('[data-indisponible-hors-ligne]').textContent).toContain(
            'Indisponible hors ligne',
        )
        expect(annonces).toEqual([])
        expect(relais.rejets).toEqual([])
    })

    it('au retour du réseau, les redemande une fois, ensemble, et les montre', async () => {
        await ouvrirLAccueil()
        await vi.waitUntil(() => indisponibles() === 2)

        reseau.mode = 'repond'
        reseau.reponse = reponseDesDifferees({ analyticalStats: { semaine: 3 }, objectifsDuJour: [] })

        // Une réponse réussie du serveur, comme `http()`, le rejeu de la file ou la sonde la notent.
        etatDuReseau.noterQueLeServeurRepond()
        await vi.waitUntil(() => document.querySelectorAll('.chargee').length === 2)

        const redemandes = requetesPartielles().slice(2)

        expect(redemandes).toHaveLength(1)
        expect(redemandes[0].entetes['X-Inertia-Partial-Data'].split(',').sort()).toEqual([
            'analyticalStats',
            'objectifsDuJour',
        ])
        expect(pageInertia.rescuedProps).toEqual([])
        expect(indisponibles()).toBe(0)

        // Une autre réponse ensuite ne redemande plus rien.
        etatDuReseau.noterQueLeServeurRepond()
        await unMoment()

        expect(requetesPartielles()).toHaveLength(3)
    })

    it('un rechargement au retour du réseau qui échoue reste silencieux, et se refait à la réponse suivante', async () => {
        await ouvrirLAccueil()
        await vi.waitUntil(() => indisponibles() === 2)

        etatDuReseau.noterQueLeServeurRepond()
        await vi.waitUntil(() => requetesPartielles().length === 3)
        await unMoment()

        expect(indisponibles()).toBe(2)
        expect(annonces).toEqual([])
        expect(relais.rejets).toEqual([])

        reseau.mode = 'repond'
        reseau.reponse = reponseDesDifferees({ analyticalStats: { semaine: 3 }, objectifsDuJour: [] })
        etatDuReseau.noterQueLeServeurRepond()
        await vi.waitUntil(() => document.querySelectorAll('.chargee').length === 2)

        expect(requetesPartielles()).toHaveLength(4)
    })

    it('ne les redemande qu’une fois, même quand le serveur ne sait pas les rendre', async () => {
        await ouvrirLAccueil()
        await vi.waitUntil(() => indisponibles() === 2)

        // Le serveur répond, mais secourt lui-même les deux props (une erreur à leur calcul).
        reseau.mode = 'repond'
        reseau.reponse = reponseInertia({
            component: 'Dashboard',
            url: '/dashboard',
            version: 'version-de-la-page',
            encryptHistory: true,
            rescuedProps: ['analyticalStats', 'objectifsDuJour'],
            props: {},
        })
        etatDuReseau.noterQueLeServeurRepond()
        await vi.waitUntil(() => requetesPartielles().length === 3)
        await unMoment()

        etatDuReseau.noterQueLeServeurRepond()
        await unMoment()

        expect(requetesPartielles()).toHaveLength(3)
        expect(indisponibles()).toBe(2)
    })

    it('ne redemande rien pour une autre page que celle qui les a secourues', async () => {
        await ouvrirLAccueil()
        await vi.waitUntil(() => indisponibles() === 2)
        await new Promise((fini) => routeur.replace({ ...pageDeDepart(), onFinish: fini }))

        reseau.mode = 'repond'
        reseau.reponse = reponseDesDifferees({ analyticalStats: { semaine: 3 }, objectifsDuJour: [] })
        etatDuReseau.noterQueLeServeurRepond()
        await unMoment()

        expect(requetesPartielles()).toHaveLength(2)
    })
})

describe('une copie servie', () => {
    it('montre « Indisponible hors ligne » pour les props différées qu’elle n’a pas, sans les demander', async () => {
        localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, `compte:${COMPTE}`)
        document.cookie = `gt_copies=${btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=+$/, '')}; path=/`

        // L'accueil gardé avant que ses props différées ne reviennent.
        expect(await magasin.ecrireUneCopie(accueilDuServeur())).toBe('ecrite')

        let finie = false
        routeur.visit('/dashboard', { onFinish: () => (finie = true) })
        await vi.waitUntil(() => finie)
        await vi.waitUntil(() => indisponibles() === 2)
        await unMoment()

        expect(pageInertia.props.horsLigne).toMatchObject({ raison: 'reseau' })
        expect(pageInertia.rescuedProps.sort()).toEqual(['analyticalStats', 'objectifsDuJour'])
        expect(requetes).toHaveLength(1)
        expect(requetesPartielles()).toEqual([])
    })
})
