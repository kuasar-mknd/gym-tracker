import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'

import { creerUneFabriqueEnMemoire } from './baseIndexeeEnMemoire'
import { RequeteSimulee, reponseInertia, requetes, reseau, uneTache } from './reseauSimule'
import {
    MAGASIN_DES_PAGES,
    MAGASIN_DE_L_ETAT,
    NOM_DE_LA_BASE,
    TEMOIN,
    creerLeMagasinDesCopies,
    ouvrirLaBaseDuNavigateur,
} from '@/Utils/copiesHorsLigne'

/*
 * Un document rouvert par les boutons Retour ou Avance n'est pas une réponse
 * du réseau à garder (#2048, partie D), avec le vrai client d'Inertia.
 *
 * Inertia y montre l'entrée d'historique quand il sait la lire, telle que la
 * page l'avait laissée, et la page du document n'est pas ce que la personne
 * voit. La navigation se lit comme Inertia la lit
 * (`performance.getEntriesByType('navigation')`). Ses props différées, en
 * partiel, ne créent rien non plus.
 */

const accueil = {
    component: 'Dashboard',
    url: '/dashboard',
    version: 'v1',
    encryptHistory: true,
    clearHistory: false,
    deferredProps: { default: ['graphique'] },
    props: { errors: {}, auth: { user: { id: 7, name: 'Camille Horsligne', active_workout: null } } },
}

let fabrique
let ecriture
let application

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    // Avant le chargement d'Inertia, qui lit le type de la navigation à son import.
    vi.spyOn(performance, 'getEntriesByType').mockImplementation((type) =>
        type === 'navigation' ? [{ type: 'back_forward' }] : [],
    )
    reseau.mode = 'muet'

    document.body.innerHTML = '<div id="app"></div>'
    const script = Object.assign(document.createElement('script'), {
        type: 'application/json',
        textContent: JSON.stringify(accueil),
    })
    script.dataset.page = 'app'
    document.body.appendChild(script)
    window.history.replaceState(null, '', '/dashboard')
    document.cookie = `${TEMOIN}=${btoa('x'.repeat(32)).replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_')}; path=/`

    fabrique = creerUneFabriqueEnMemoire()

    const inertia = await import('@inertiajs/vue3')
    const { installerLaGardeDeLHistorique } = await import('@/Utils/historiqueDuCompte')
    const { installerLEcritureDesCopies } = await import('@/Utils/ecritureDesCopies')

    installerLaGardeDeLHistorique({ routeur: inertia.router })
    ecriture = installerLEcritureDesCopies({
        routeur: inertia.router,
        magasin: creerLeMagasinDesCopies({ ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique) }),
    })

    await inertia.createInertiaApp({
        resolve: () => ({ render: () => h('p', 'Accueil') }),
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) }).use(plugin)
            application.mount(el)
        },
    })
})

afterAll(() => {
    application.unmount()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('un document rouvert par les boutons Retour ou Avance', () => {
    it('n’est pas gardé, et ses props différées ne créent rien', async () => {
        await vi.waitUntil(() => requetes.length === 1)

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

        expect(fabrique.enregistrements(NOM_DE_LA_BASE, MAGASIN_DES_PAGES).size).toBe(0)
        expect(fabrique.enregistrements(NOM_DE_LA_BASE, MAGASIN_DE_L_ETAT).get('etat')).toBeUndefined()
    })
})
