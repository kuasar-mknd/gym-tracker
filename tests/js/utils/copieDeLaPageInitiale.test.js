import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'

import { creerUneFabriqueEnMemoire } from './baseIndexeeEnMemoire'
import { RequeteSimulee, relayerLesRejetsNonGeres, reponseInertia, requetes, reseau, uneTache } from './reseauSimule'
import { TEMOIN, creerLeMagasinDesCopies, ouvrirLaBaseDuNavigateur } from '@/Utils/copiesHorsLigne'
import { installerLEcritureDesCopies } from '@/Utils/ecritureDesCopies'
import { installerLaGardeDeLHistorique } from '@/Utils/historiqueDuCompte'

/*
 * La page initiale du document est gardée (#2048, partie D), avec le vrai
 * client d'Inertia.
 *
 * Un chargement complet écrit la page dans `script[data-page]`, et Inertia
 * n'émet pas `success` pour elle : ouvrir l'application, ou la recharger, ne
 * gardait rien. Ses props différées, qu'Inertia demande aussitôt en partiel,
 * rejoignent ensuite sa copie. Le client ne démarre qu'une fois par
 * fichier : la page restaurée par l'historique a le sien
 * (`copieDUnePageRestaureeParLHistorique.test.js`).
 */

const COMPTE = 7

const cle = btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '')

/** L'accueil, tel que le serveur l'écrit dans le document. */
const accueil = {
    component: 'Dashboard',
    url: '/dashboard',
    version: 'v1',
    encryptHistory: true,
    clearHistory: false,
    deferredProps: { default: ['graphique'] },
    props: {
        errors: {},
        auth: { user: { id: COMPTE, name: 'Camille Horsligne', active_workout: null } },
        recentWorkouts: [{ name: 'Jambes du mardi' }],
    },
}

let fabrique
let magasin
let ecriture
let application
let relais

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    reseau.mode = 'muet'
    relais = relayerLesRejetsNonGeres()

    document.body.innerHTML = '<div id="app"></div>'
    const script = Object.assign(document.createElement('script'), {
        type: 'application/json',
        textContent: JSON.stringify(accueil),
    })
    script.dataset.page = 'app'
    document.body.appendChild(script)
    window.history.replaceState(null, '', '/dashboard')
    document.cookie = `${TEMOIN}=${cle}; path=/`

    fabrique = creerUneFabriqueEnMemoire()
    magasin = creerLeMagasinDesCopies({ ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique) })

    const inertia = await import('@inertiajs/vue3')

    // Comme main.js : la garde de l'historique lit la page du document, puis l'écriture des copies.
    installerLaGardeDeLHistorique({ routeur: inertia.router })
    ecriture = installerLEcritureDesCopies({ routeur: inertia.router, magasin })

    await inertia.createInertiaApp({
        resolve: () => ({ render: () => h('p', 'Accueil') }),
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) }).use(plugin)
            application.mount(el)
        },
    })
})

afterAll(() => {
    relais.retirer()
    application.unmount()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('la page initiale du document', () => {
    it('est gardée, et ses props différées la rejoignent', async () => {
        await ecriture.ecrituresFinies()

        const avant = await magasin.lireUneCopie('/dashboard')

        expect(avant.page.props).toEqual(accueil.props)
        expect(avant.page.props.graphique).toBeUndefined()

        // Inertia demande les props différées de la page initiale, en partiel.
        await vi.waitUntil(() => requetes.length === 1)
        expect(requetes[0].entetes['X-Inertia-Partial-Data']).toBe('graphique')

        let finie = false
        const arreter = (await import('@inertiajs/vue3')).router.on('finish', () => (finie = true))
        requetes[0].repondre(
            reponseInertia({
                component: 'Dashboard',
                url: '/dashboard',
                version: 'v1',
                encryptHistory: true,
                props: { graphique: [3, 4] },
            }),
        )
        await vi.waitUntil(() => finie)
        arreter()
        await uneTache()
        await ecriture.ecrituresFinies()

        const apres = await magasin.lireUneCopie('/dashboard')

        expect(apres.page.props.graphique).toEqual([3, 4])
        expect(apres.page.props.recentWorkouts).toEqual(accueil.props.recentWorkouts)
        expect(relais.rejets).toEqual([])
    })
})
