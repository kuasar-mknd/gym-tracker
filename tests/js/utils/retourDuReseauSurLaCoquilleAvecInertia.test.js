import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'

import { creerUneFabriqueEnMemoire } from './baseIndexeeEnMemoire'
import { RequeteSimulee, relayerLesRejetsNonGeres, reponseInertia, requetes, reseau, uneTache } from './reseauSimule'
import { pageHorsLigne } from '@/sw/pageHorsLigne'
import { TEMOIN, creerLeMagasinDesCopies, ouvrirLaBaseDuNavigateur } from '@/Utils/copiesHorsLigne'
import { CLEF_DU_TITULAIRE_DE_L_APPAREIL } from '@/Utils/historiqueDuCompte'

/*
 * La page HorsLigne montée par la coquille, puis le retour du réseau, avec le
 * vrai client d'Inertia et le vrai service des copies (#2048, partie F).
 *
 * Sans réseau, une adresse que l'appareil ne garde pas (les statistiques)
 * monte la page HorsLigne : le bandeau d'une copie, et ce qui s'ouvre sans
 * réseau. Le serveur répond de nouveau (la sonde, `noterQueLeServeurRepond`) :
 * la page se recharge en entier, comme une copie de l'application ouverte
 * (`visitesHorsLigne.js`), et devient la page de son adresse. Le layout est
 * réduit au bandeau : la page, elle, est la vraie.
 */

vi.mock('@/Layouts/AuthenticatedLayout.vue', async () => {
    const { default: BandeauHorsLigne } = await import('@/Components/UI/BandeauHorsLigne.vue')

    return {
        default: {
            components: { BandeauHorsLigne },
            template: '<div><BandeauHorsLigne /><slot /></div>',
        },
    }
})

const COMPTE = 7

const cle = btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '')

const auth = { user: { id: COMPTE, name: 'Camille Horsligne', active_workout: null, latest_achievement: null } }

/** L'accueil, tel qu'une réponse complète le donnait avant la coupure. */
const accueil = {
    component: 'Dashboard',
    url: '/dashboard',
    version: 'version-des-actifs',
    encryptHistory: true,
    clearHistory: false,
    sharedProps: ['auth'],
    props: { errors: {}, auth },
}

/** Les statistiques, telles que le serveur les rend au retour du réseau. */
const statistiques = {
    component: 'Stats/Index',
    url: '/stats',
    version: 'version-des-actifs',
    encryptHistory: true,
    clearHistory: false,
    props: { auth, resume: 'Douze séances ce mois-ci' },
}

const Statistiques = {
    props: ['resume'],
    setup: (props) => () => h('p', { id: 'statistiques' }, props.resume),
}

let routeur
let etatDuReseau
let application
let relais
let pageInertia

const bandeau = () => document.querySelector('[dusk="bandeau-hors-ligne"]')

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    reseau.mode = 'coupe'
    relais = relayerLesRejetsNonGeres()

    const fabrique = creerUneFabriqueEnMemoire()

    document.cookie = `${TEMOIN}=${cle}; path=/`
    localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, `compte:${COMPTE}`)

    const enLigne = creerLeMagasinDesCopies({ ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique) })

    expect(await enLigne.ecrireUneCopie(accueil, { ziggy: { url: 'http://localhost:3000', routes: {} } })).toBe(
        'ecrite',
    )
    await enLigne.fermer()
    vi.stubGlobal('indexedDB', fabrique)

    const coquille = new DOMParser().parseFromString(
        pageHorsLigne({ feuilles: ['/build/assets/main-Cd34.css'], moduleDeLEntree: '/build/assets/main-Ab12.js' }),
        'text/html',
    )

    document.documentElement.setAttribute('data-coquille', '')
    document.documentElement.setAttribute('data-raison', 'reseau')
    document.body.innerHTML = coquille.body.innerHTML
    window.history.replaceState(null, '', '/stats')

    const { demarrerHorsLigne } = await import('@/Utils/demarrageHorsLigne')

    expect(await demarrerHorsLigne()).toMatchObject({ montee: true, raison: 'HorsLigne' })

    // La suite de main.js : la garde de l'historique, les visites hors ligne, route(), puis Inertia.
    const inertia = await import('@inertiajs/vue3')
    const { installerLaGardeDeLHistorique } = await import('@/Utils/historiqueDuCompte')
    const { installerLesVisitesHorsLigne } = await import('@/Utils/visitesHorsLigne')
    const { installerLaRouteGlobale } = await import('@/Utils/routeGlobale')

    routeur = inertia.router
    etatDuReseau = await import('@/composables/useEtatDuReseau')
    installerLaGardeDeLHistorique({ routeur })
    installerLesVisitesHorsLigne({ routeur })
    installerLaRouteGlobale()

    for (const evenement of ['navigate', 'success']) {
        routeur.on(evenement, (detail) => (pageInertia = detail.detail.page))
    }

    await inertia.createInertiaApp({
        resolve: async (nom) => (nom === 'HorsLigne' ? (await import('@/Pages/HorsLigne.vue')).default : Statistiques),
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) })
            application.directive('press', {})
            application.use(plugin).mount(el)
        },
    })
})

afterAll(() => {
    relais.retirer()
    application?.unmount()
    document.documentElement.removeAttribute('data-coquille')
    document.documentElement.removeAttribute('data-raison')
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('la page HorsLigne démarrée par la coquille', () => {
    it('montre ce qui s’ouvre sans réseau, puis se recharge en entier quand le serveur répond', async () => {
        await vi.waitUntil(() => pageInertia?.component === 'HorsLigne' && bandeau() !== null)

        expect(document.querySelector('[dusk="bandeau-hors-ligne-titre"]').textContent).toMatch(
            /^Hors ligne · copie du /,
        )
        expect(
            [...document.querySelectorAll('[dusk="page-sans-reseau"]')].map((lien) => lien.getAttribute('href')),
        ).toEqual(['/dashboard'])
        expect(window.history.state.page).toBeInstanceOf(ArrayBuffer)
        expect(requetes).toHaveLength(0)

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(statistiques)
        etatDuReseau.noterQueLeServeurRepond()

        await vi.waitUntil(() => pageInertia?.component === 'Stats/Index', { timeout: 3000 })
        await uneTache()

        expect(requetes).toHaveLength(1)
        expect(new URL(requetes[0].url, window.location.href).pathname).toBe('/stats')
        expect(requetes[0].entetes['X-Inertia']).toBe('true')
        expect(requetes[0].entetes['X-Inertia-Partial-Data']).toBeUndefined()
        expect(document.querySelector('#statistiques').textContent).toBe('Douze séances ce mois-ci')
        expect(bandeau()).toBeNull()
        expect(pageInertia.props.horsLigne).toBeUndefined()
        expect(relais.rejets).toEqual([])
    })
})
