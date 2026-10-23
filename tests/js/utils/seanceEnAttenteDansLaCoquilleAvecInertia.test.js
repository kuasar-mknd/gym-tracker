import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'

import { creerUneFabriqueEnMemoire } from './baseIndexeeEnMemoire'
import { horodatageRecent } from './fileHorsLigne'
import { RequeteSimulee, relayerLesRejetsNonGeres, reseau, requetes, uneTache } from './reseauSimule'
import { pageHorsLigne } from '@/sw/pageHorsLigne'
import { TEMOIN, creerLeMagasinDesCopies, ouvrirLaBaseDuNavigateur } from '@/Utils/copiesHorsLigne'
import { CLEF_DU_TITULAIRE_DE_L_APPAREIL } from '@/Utils/historiqueDuCompte'

/*
 * Une séance démarrée sans réseau, rouverte à froid dans la coquille hors
 * ligne (#2049), avec le vrai client d'Inertia, dans l'ordre de `main.js` : la
 * coquille servie par le worker à l'adresse de la séance, le démarrage qui
 * écrit la séance construite depuis sa création en file, la garde de
 * l'historique, `createInertiaApp`, puis la file hors ligne, chargée après le
 * démarrage. Le réseau est coupé. Aucune copie ne garde cette adresse : sans
 * la branche de la séance en attente, la coquille montait la page `HorsLigne`.
 * Le client ne démarre qu'une fois par fichier : un fichier, un cas.
 */

const COMPTE = 7
const CLE_DE_LA_SEANCE = 'cle-de-la-seance-demarree'
const SQUAT = { id: 7, name: 'Squat', type: 'strength' }

const cle = btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '')

/** L'accueil, la dernière page qu'une réponse complète a donnée avant la coupure. */
const accueil = {
    component: 'Dashboard',
    url: '/dashboard',
    version: 'version-des-actifs',
    encryptHistory: true,
    clearHistory: false,
    props: { errors: {}, auth: { user: { id: COMPTE, name: 'Camille Horsligne', active_workout: null } } },
}

const bibliotheque = {
    ...accueil,
    component: 'Exercises/Index',
    url: '/exercises',
    props: { ...accueil.props, exercises: [SQUAT] },
}

const routes = {
    url: 'http://localhost:3000',
    port: 3000,
    defaults: {},
    routes: { dashboard: { uri: 'dashboard', methods: ['GET', 'HEAD'] } },
}

/** La séance montée, telle que le test la voit : ce qu'elle reçoit. */
const SeanceDuTest = {
    props: ['workout', 'exercises'],
    render() {
        return h('p', { id: 'seance' }, `${this.workout.id} ${this.exercises.map((exercice) => exercice.name).join()}`)
    },
}

let application
let relais
let demarrage
let file
let page

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    reseau.mode = 'coupe'
    relais = relayerLesRejetsNonGeres()

    // Une session en ligne, plus tôt : le témoin, le titulaire de l'appareil, et les copies.
    const fabrique = creerUneFabriqueEnMemoire()

    document.cookie = `${TEMOIN}=${cle}; path=/`
    localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, `compte:${COMPTE}`)

    const enLigne = creerLeMagasinDesCopies({ ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique) })

    expect(await enLigne.ecrireUneCopie(bibliotheque, { ziggy: routes })).toBe('ecrite')
    expect(await enLigne.ecrireUneCopie(accueil, { ziggy: routes })).toBe('ecrite')
    await enLigne.fermer()

    // « Démarrer » sans réseau, il y a une heure : la création de la séance attend la file.
    // Datée en dur, elle passait trente jours, et la file l'abandonnait au chargement (#2123).
    const demarree = horodatageRecent()

    localStorage.setItem(
        'offline_sync_queue',
        JSON.stringify([
            {
                id: 'q-seance',
                method: 'post',
                url: '/api/v1/workouts',
                data: { started_at: demarree },
                headers: { 'Idempotency-Key': CLE_DE_LA_SEANCE },
                timestamp: demarree,
                compte: String(COMPTE),
            },
        ]),
    )

    vi.stubGlobal('indexedDB', fabrique)

    // La coquille, telle que le worker la sert sans réseau à l'adresse de la séance.
    const coquille = new DOMParser().parseFromString(
        pageHorsLigne({ feuilles: ['/build/assets/main-Cd34.css'], moduleDeLEntree: '/build/assets/main-Ab12.js' }),
        'text/html',
    )

    document.documentElement.setAttribute('data-coquille', '')
    document.documentElement.setAttribute('data-raison', 'reseau')
    document.body.innerHTML = coquille.body.innerHTML
    window.history.replaceState(null, '', `/workouts/en-attente/${CLE_DE_LA_SEANCE}`)

    const { demarrerHorsLigne } = await import('@/Utils/demarrageHorsLigne')

    demarrage = await demarrerHorsLigne()

    // La suite de main.js : la garde de l'historique, route(), Inertia, puis la file.
    const inertia = await import('@inertiajs/vue3')
    const { installerLaGardeDeLHistorique } = await import('@/Utils/historiqueDuCompte')
    const { installerLaRouteGlobale } = await import('@/Utils/routeGlobale')

    installerLaGardeDeLHistorique({ routeur: inertia.router })
    installerLaRouteGlobale()
    page = inertia.usePage()

    await inertia.createInertiaApp({
        // Toute autre page, la page `HorsLigne` d'abord, se dit par son nom.
        resolve: (nom) => (nom === 'Workouts/Show' ? SeanceDuTest : { render: () => h('p', { id: 'seance' }, nom) }),
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
    localStorage.clear()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('la coquille rouverte à l’adresse d’une séance démarrée sans réseau', () => {
    it('monte la séance de sa création en file, chiffrée dans l’historique, sous l’identifiant que la file lui connaît', async () => {
        expect(demarrage).toMatchObject({ montee: true, raison: 'seance-en-attente' })

        await vi.waitUntil(() => window.history.state?.page instanceof ArrayBuffer)
        expect(window.location.pathname).toBe(`/workouts/en-attente/${CLE_DE_LA_SEANCE}`)
        expect(page.component).toBe('Workouts/Show')
        expect(page.props).not.toHaveProperty('horsLigne')

        // La séance et sa bibliothèque, prise à la copie des exercices.
        expect(document.querySelector('#seance').textContent).toBe('temp-qq-seance Squat')

        // La file, chargée ensuite, lit le compte dans la page écrite, et y connaît la même création.
        expect(file.compte).toBe(String(COMPTE))
        expect(file.entreeStockee('q-seance')).toMatchObject({ url: '/api/v1/workouts' })
        expect(requetes).toHaveLength(0)

        await uneTache()
        expect(relais.rejets).toEqual([])
    })
})
