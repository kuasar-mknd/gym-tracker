import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'

import { creerUneFabriqueEnMemoire } from './baseIndexeeEnMemoire'
import { RequeteSimulee, relayerLesRejetsNonGeres, reponseInertia, requetes, reseau, uneTache } from './reseauSimule'
import { MESSAGE_REPONSE_TARDIVE_ARRIVEE } from '@/sw/navigationsHorsLigne'
import { pageHorsLigne } from '@/sw/pageHorsLigne'
import { TEMOIN, creerLeMagasinDesCopies, ouvrirLaBaseDuNavigateur } from '@/Utils/copiesHorsLigne'
import { CLEF_DU_TITULAIRE_DE_L_APPAREIL } from '@/Utils/historiqueDuCompte'

/*
 * Un réseau lent mais vivant, avec le vrai client d'Inertia et le vrai service
 * des copies (#2048, partie F, seconde relecture) : l'accueil n'a pas répondu
 * en huit secondes, le worker a servi la coquille au délai, et la coquille a
 * monté la copie de l'accueil, marquée « lent ». La réponse arrive ensuite, et
 * le worker prévient la page (`MESSAGE_REPONSE_TARDIVE_ARRIVEE`) : sans aucun
 * geste, la page se recharge par une visite d'Inertia et devient celle du
 * serveur, sans recharger le document. Sans ce message, elle restait sur la
 * copie tant que la sonde, qui abandonne à cinq secondes, échouait sur le même
 * réseau lent.
 */

const COMPTE = 7

const cle = btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '')

const auth = { user: { id: COMPTE, name: 'Camille Horsligne', active_workout: null, latest_achievement: null } }

/**
 * L'accueil : `resume` dit d'où il vient.
 *
 * @param {string} resume
 */
const accueil = (resume) => ({
    component: 'Dashboard',
    url: '/dashboard',
    version: 'version-des-actifs',
    encryptHistory: true,
    clearHistory: false,
    sharedProps: ['auth'],
    props: { errors: {}, auth, resume },
})

let routeur
let application
let relais
let pageInertia
let conteneur
let recharger

const resume = () => document.querySelector('#accueil')?.textContent

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    reseau.mode = 'muet'
    relais = relayerLesRejetsNonGeres()

    const fabrique = creerUneFabriqueEnMemoire()

    document.cookie = `${TEMOIN}=${cle}; path=/`
    localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, `compte:${COMPTE}`)

    const enLigne = creerLeMagasinDesCopies({ ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique) })

    expect(
        await enLigne.ecrireUneCopie(accueil('La copie d’hier'), {
            ziggy: { url: 'http://localhost:3000', routes: {} },
        }),
    ).toBe('ecrite')
    await enLigne.fermer()
    vi.stubGlobal('indexedDB', fabrique)

    const coquille = new DOMParser().parseFromString(
        pageHorsLigne({ feuilles: ['/build/assets/main-Cd34.css'], moduleDeLEntree: '/build/assets/main-Ab12.js' }),
        'text/html',
    )

    document.documentElement.setAttribute('data-coquille', '')
    document.documentElement.setAttribute('data-raison', 'delai')
    document.body.innerHTML = coquille.body.innerHTML
    window.history.replaceState(null, '', '/dashboard')

    // Comme main.js : l'écoute des messages du worker d'abord, puis le démarrage dans la coquille.
    const { installerLesMessagesDuWorker } = await import('@/Utils/messagesDuWorker')

    conteneur = new EventTarget()
    recharger = vi.fn()
    installerLesMessagesDuWorker({ conteneur, recharger })

    const { demarrerHorsLigne } = await import('@/Utils/demarrageHorsLigne')

    expect(await demarrerHorsLigne()).toMatchObject({ montee: true, raison: 'copie' })

    const inertia = await import('@inertiajs/vue3')
    const { installerLaGardeDeLHistorique } = await import('@/Utils/historiqueDuCompte')
    const { installerLesVisitesHorsLigne } = await import('@/Utils/visitesHorsLigne')
    const { installerLaRouteGlobale } = await import('@/Utils/routeGlobale')
    const { default: BandeauHorsLigne } = await import('@/Components/UI/BandeauHorsLigne.vue')

    routeur = inertia.router
    installerLaGardeDeLHistorique({ routeur })
    installerLesVisitesHorsLigne({ routeur })
    installerLaRouteGlobale()

    for (const evenement of ['navigate', 'success']) {
        routeur.on(evenement, (detail) => (pageInertia = detail.detail.page))
    }

    const Accueil = {
        props: ['resume'],
        setup: (props) => () => h('div', [h(BandeauHorsLigne), h('p', { id: 'accueil' }, props.resume)]),
    }

    await inertia.createInertiaApp({
        resolve: async () => Accueil,
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) })
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

describe('la copie montée au délai, puis la réponse arrivée après lui', () => {
    it('se recharge d’elle-même par une visite d’Inertia, sans recharger le document', async () => {
        await vi.waitUntil(() => pageInertia?.props?.horsLigne?.raison === 'lent' && resume() === 'La copie d’hier')

        expect(document.querySelector('[dusk="bandeau-hors-ligne-titre"]').textContent).toMatch(
            /^Le réseau ne répond pas · copie du /,
        )
        expect(requetes).toHaveLength(0)

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(accueil('L’accueil du serveur'))
        conteneur.dispatchEvent(new MessageEvent('message', { data: { type: MESSAGE_REPONSE_TARDIVE_ARRIVEE } }))

        await vi.waitUntil(() => resume() === 'L’accueil du serveur', { timeout: 3000 })
        await uneTache()

        expect(requetes).toHaveLength(1)
        expect(new URL(requetes[0].url, window.location.href).pathname).toBe('/dashboard')
        expect(requetes[0].entetes['X-Inertia']).toBe('true')
        expect(requetes[0].entetes['X-Inertia-Partial-Data']).toBeUndefined()
        expect(pageInertia.props.horsLigne).toBeUndefined()
        expect(document.querySelector('[dusk="bandeau-hors-ligne"]')).toBeNull()
        expect(recharger).not.toHaveBeenCalled()
        expect(relais.rejets).toEqual([])
    })
})
