import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'

import { creerUneFabriqueEnMemoire } from './baseIndexeeEnMemoire'
import { RequeteSimulee, relayerLesRejetsNonGeres, reseau, uneTache } from './reseauSimule'
import { pageHorsLigne } from '@/sw/pageHorsLigne'
import { TEMOIN, creerLeMagasinDesCopies, ouvrirLaBaseDuNavigateur } from '@/Utils/copiesHorsLigne'
import { CLEF_DU_TITULAIRE_DE_L_APPAREIL } from '@/Utils/historiqueDuCompte'

/*
 * Le démarrage dans la coquille hors ligne avec le vrai client d'Inertia
 * (#2048, partie F), dans l'ordre de `main.js` : la coquille servie par le
 * worker, le démarrage qui écrit la page de la copie dans le document, la garde
 * de l'historique, puis `createInertiaApp`. Le réseau est coupé. La page montée
 * se range chiffrée dans l'historique, une note qui porte `</script>`, `<!--`
 * et U+2028 s'affiche telle quelle, la table des routes de la copie sert
 * `route()`, et la file hors ligne lit le compte dans la page écrite. Le
 * client ne démarre qu'une fois par fichier : un fichier, un cas.
 */

const COMPTE = 7

/** Une note de séance qui fermerait le script de la page, ouvrirait un commentaire, et couperait une ligne en JavaScript. */
const NOTE = 'Fin</script><script>window.piratee = true</script><!-- reste  après -->'

const cle = btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '')

/** La séance en cours, telle qu'une réponse complète la donnait avant la coupure. */
const seance = {
    component: 'Workouts/Show',
    url: '/workouts/12',
    version: 'version-des-actifs',
    encryptHistory: true,
    clearHistory: false,
    props: {
        errors: {},
        auth: { user: { id: COMPTE, name: 'Camille Horsligne', active_workout: { id: 12, name: 'Jambes' } } },
        workout: { id: 12, name: 'Jambes', notes: NOTE },
    },
}

const routes = {
    url: 'http://localhost:3000',
    port: 3000,
    defaults: {},
    routes: { 'workouts.show': { uri: 'workouts/{workout}', methods: ['GET', 'HEAD'], parameters: ['workout'] } },
}

let application
let relais
let demarrage
let file

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    reseau.mode = 'coupe'
    relais = relayerLesRejetsNonGeres()

    // Une session en ligne, plus tôt : le témoin, le titulaire de l'appareil, et la copie de la séance.
    const fabrique = creerUneFabriqueEnMemoire()

    document.cookie = `${TEMOIN}=${cle}; path=/`
    localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, `compte:${COMPTE}`)

    const enLigne = creerLeMagasinDesCopies({ ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique) })

    expect(await enLigne.ecrireUneCopie(seance, { ziggy: routes })).toBe('ecrite')
    await enLigne.fermer()

    // Le magasin de la page ouvre l'IndexedDB de l'appareil.
    vi.stubGlobal('indexedDB', fabrique)

    // La coquille, telle que le worker la sert sans réseau à l'adresse de la séance.
    const coquille = new DOMParser().parseFromString(
        pageHorsLigne({ feuilles: ['/build/assets/main-Cd34.css'], moduleDeLEntree: '/build/assets/main-Ab12.js' }),
        'text/html',
    )

    document.documentElement.setAttribute('data-coquille', '')
    document.documentElement.setAttribute('data-raison', 'reseau')
    document.body.innerHTML = coquille.body.innerHTML
    window.history.replaceState(null, '', '/workouts/12')

    const { demarrerHorsLigne, estLaCoquille } = await import('@/Utils/demarrageHorsLigne')

    expect(estLaCoquille()).toBe(true)
    demarrage = await demarrerHorsLigne()

    // La suite de main.js : la garde de l'historique, route(), puis Inertia.
    const inertia = await import('@inertiajs/vue3')
    const { installerLaGardeDeLHistorique } = await import('@/Utils/historiqueDuCompte')
    const { installerLaRouteGlobale } = await import('@/Utils/routeGlobale')

    installerLaGardeDeLHistorique({ routeur: inertia.router })
    installerLaRouteGlobale()

    await inertia.createInertiaApp({
        resolve: () => ({
            props: { workout: { type: Object, required: true } },
            setup: (props) => () => h('p', { id: 'note' }, props.workout.notes),
        }),
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) }).use(plugin)
            application.mount(el)
        },
    })

    file = (await import('@/Utils/SyncService')).default
})

afterAll(() => {
    relais.retirer()
    application?.unmount()
    document.documentElement.removeAttribute('data-coquille')
    document.documentElement.removeAttribute('data-raison')
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('la coquille démarrée sur une copie', () => {
    it('monte la copie, rangée chiffrée dans l’historique, sa note comme donnée, ses routes et son compte', async () => {
        expect(demarrage).toMatchObject({ montee: true, raison: 'copie' })

        // Inertia range la page initiale chiffrée : `encryptHistory: true`, écrit en dur.
        await vi.waitUntil(() => window.history.state?.page instanceof ArrayBuffer)
        expect(window.history.state.page).toBeInstanceOf(ArrayBuffer)
        expect(window.location.pathname).toBe('/workouts/12')

        // La note s'affiche telle quelle : aucun script de plus n'a tourné.
        expect(document.querySelector('#note').textContent).toBe(NOTE)
        expect(window.piratee).toBeUndefined()
        expect(document.querySelectorAll('script:not([src])')).toHaveLength(1)
        expect(document.querySelector('main')).toBeNull()

        // La table des routes de la copie sert route(), et la file lit le compte dans la page écrite.
        expect(globalThis.route('workouts.show', 12, false)).toBe('/workouts/12')
        expect(file.compte).toBe(String(COMPTE))

        await uneTache()
        expect(relais.rejets).toEqual([])
    })
})
