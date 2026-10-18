import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, onMounted } from 'vue'

import { creerUneFabriqueEnMemoire } from './baseIndexeeEnMemoire'
import { RequeteSimulee, relayerLesRejetsNonGeres, reponseInertia, reseau, uneTache } from './reseauSimule'
import { CLEF_DU_TITULAIRE_DE_L_APPAREIL } from '@/Utils/historiqueDuCompte'
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'

/*
 * Un graphique en échec sur une copie hors ligne, quand la copie se recharge
 * en entier (#2048, partie E), avec le vrai client d'Inertia, le vrai service
 * des copies, le vrai magasin chiffré sur une base en mémoire et le vrai
 * `graphiqueAsynchrone`.
 *
 * Les morceaux des graphiques restent hors du precache : sans réseau, celui de
 * la liste des séances ne se charge pas, et la copie le dit indisponible. Le
 * rechargement complet du retour du réseau garde la page montée
 * (`router.reload` impose `preserveState`) : le composant asynchrone gardait
 * son échec, et la page, qui n'était plus une copie, disait encore
 * « Graphique indisponible hors ligne ». Dans Chrome, l'échec d'un `import()`
 * vaut en plus pour tout le document : un graphique n'est donc plus demandé
 * tant que le serveur ne répond pas, et se demande à sa réponse suivante.
 *
 * Le client se monte une fois pour le fichier ; chaque cas repart des outils,
 * d'un magasin vide et d'un graphique neuf, dont aucun chargement n'a encore
 * abouti (`defineAsyncComponent` garde le premier).
 */

const COMPTE = 7

/** Une clé de 32 octets tirés au hasard, en base64url, comme le serveur la tire. */
const cleAuHasard = () =>
    btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '')

/**
 * Une page du compte, telle que le serveur la rend en entier.
 *
 * @param {{ url?: string, component?: string, texte?: string }} [options]
 */
const pageDe = ({ url = '/workouts', component = 'Workouts/Index', texte = 'Séances' } = {}) => ({
    component,
    url,
    version: 'version-de-la-page',
    encryptHistory: true,
    clearHistory: false,
    props: {
        errors: {},
        auth: { user: { id: COMPTE, name: 'Camille Horsligne', active_workout: null, latest_achievement: null } },
        texte,
    },
})

/** Ce que le cas compte du graphique : ses chargements demandés et ses dessins, et si son morceau manque au serveur. */
const graphique = { chargements: 0, dessins: 0, composant: null, morceauAbsent: false }

/** Un graphique neuf, dont le morceau ne se charge que si le réseau répond. */
const unGraphiqueNeuf = () =>
    graphiqueAsynchrone(() => {
        graphique.chargements += 1

        return reseau.mode === 'repond' && !graphique.morceauAbsent
            ? Promise.resolve({
                  setup: (props, { attrs }) => {
                      onMounted(() => (graphique.dessins += 1))

                      return () => h('p', { class: 'graphique-dessine' }, `séries : ${attrs.series}`)
                  },
              })
            : Promise.reject(new TypeError('Failed to fetch dynamically imported module'))
    })

/** La liste des séances, avec le graphique du cas. */
const Seances = {
    props: ['texte'],
    render() {
        return h('div', [h('p', { id: 'contenu' }, this.texte), h(graphique.composant, { series: 3 })])
    },
}

const Outils = { render: () => h('p', 'Outils') }

let routeur
let fabrique
let magasin
let etatDuReseau
let visites
let application
let relais
let pageInertia
const erreurs = []

const contenu = () => document.getElementById('contenu')?.textContent
const montre = () => ({
    indisponible: document.querySelectorAll('[data-graphique-indisponible]').length,
    dessine: document.querySelectorAll('.graphique-dessine').length,
})

/**
 * Sert, sans réseau, la copie des séances.
 *
 * @param {'indisponible' | 'dessine'} graphiqueAttendu ce que la copie montre du graphique
 */
const servirLaCopieDesSeances = async (graphiqueAttendu = 'indisponible') => {
    expect(await magasin.ecrireUneCopie(pageDe())).toBe('ecrite')
    reseau.mode = 'coupe'
    routeur.visit('/workouts')
    await vi.waitUntil(() => pageInertia?.props?.horsLigne !== undefined && montre()[graphiqueAttendu] === 1, {
        timeout: 3000,
    })
}

/** Le serveur répond de nouveau, par la page des séances. */
const rendreLeReseau = () => {
    reseau.mode = 'repond'
    reseau.reponse = reponseInertia(pageDe({ texte: 'Séances du serveur' }))
}

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
    visites = await import('@/Utils/visitesHorsLigne')

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router

    visites.installerLesVisitesHorsLigne({ routeur, lireUneCopie: (adresse) => magasin.lireUneCopie(adresse) })

    for (const evenement of ['navigate', 'clientVisit', 'success']) {
        routeur.on(evenement, (detail) => (pageInertia = detail.detail.page))
    }

    await inertia.createInertiaApp({
        page: pageDe({ url: '/tools', component: 'Tools/Index', texte: 'Outils' }),
        resolve: (nom) => (nom === 'Workouts/Index' ? Seances : Outils),
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) }).use(plugin)
            // L'échec du morceau reste une erreur que Vue remet au rapporteur.
            application.config.errorHandler = (erreur) => erreurs.push(erreur.message)
            application.mount(el)
        },
    })

    await vi.waitUntil(() => pageInertia !== undefined)
})

beforeEach(async () => {
    reseau.mode = 'coupe'
    reseau.prochains.length = 0
    reseau.reponse = null
    await new Promise((fini) =>
        routeur.replace({ ...pageDe({ url: '/tools', component: 'Tools/Index', texte: 'Outils' }), onFinish: fini }),
    )

    fabrique.enregistrements('gym-tracker-copies', 'pages').clear()
    fabrique.enregistrements('gym-tracker-copies', 'etat').clear()
    localStorage.clear()
    localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, `compte:${COMPTE}`)
    document.cookie = `gt_copies=${cleAuHasard()}; path=/`
    Object.assign(graphique, { chargements: 0, dessins: 0, composant: unGraphiqueNeuf(), morceauAbsent: false })
    erreurs.length = 0
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

describe('un graphique en échec sur une copie, au retour du réseau', () => {
    it('n’est pas demandé sans réseau, se dessine quand la copie se recharge en entier, puis sur la copie suivante', async () => {
        await servirLaCopieDesSeances()

        // Le serveur ne répond pas : aucun import, que le navigateur garderait en échec pour tout le document.
        expect(graphique.chargements).toBe(0)
        expect(erreurs).toEqual([])

        // Le serveur répond à la sonde : la copie se recharge en entier, sans être remontée.
        rendreLeReseau()
        etatDuReseau.noterQueLeServeurRepond()
        await vi.waitUntil(() => contenu() === 'Séances du serveur' && montre().dessine === 1, { timeout: 3000 })

        expect(pageInertia.props.horsLigne).toBeUndefined()
        expect(montre()).toEqual({ indisponible: 0, dessine: 1 })
        expect(document.querySelector('.graphique-dessine').textContent).toBe('séries : 3')

        // Dessiné, il ne se remonte plus à chaque réponse du serveur.
        const chargements = graphique.chargements
        etatDuReseau.noterQueLeServeurRepond()
        etatDuReseau.noterQueLeServeurRepond()
        await uneTache()

        expect(chargements).toBe(1)
        expect(graphique.chargements).toBe(chargements)
        expect(graphique.dessins).toBe(1)

        // Sans réseau de nouveau, la copie le montre : chargé dans le document, il se dessine sans rien demander.
        await new Promise((fini) =>
            routeur.replace({
                ...pageDe({ url: '/tools', component: 'Tools/Index', texte: 'Outils' }),
                onFinish: fini,
            }),
        )
        await servirLaCopieDesSeances('dessine')

        expect(montre()).toEqual({ indisponible: 0, dessine: 1 })
        expect(graphique.chargements).toBe(1)
        expect(relais.rejets).toEqual([])
    })

    it('en échec alors que le serveur répondait, se redemande à la réponse suivante, une fois', async () => {
        // Le serveur répond, mais pas le morceau : il manque, le temps d'un déploiement.
        rendreLeReseau()
        graphique.morceauAbsent = true
        let finie = false
        routeur.visit('/workouts', { onFinish: () => (finie = true) })
        await vi.waitUntil(() => finie && montre().indisponible === 1, { timeout: 3000 })

        expect(graphique.chargements).toBe(1)
        expect(erreurs).toEqual(['Failed to fetch dynamically imported module'])

        graphique.morceauAbsent = false
        etatDuReseau.noterQueLeServeurRepond()
        await vi.waitUntil(() => montre().dessine === 1, { timeout: 3000 })

        expect(montre()).toEqual({ indisponible: 0, dessine: 1 })
        expect(graphique.chargements).toBe(2)
    })

    it('se dessine quand « Réessayer » recharge la copie', async () => {
        await servirLaCopieDesSeances()

        rendreLeReseau()
        await visites.reessayerLaCopie()
        await vi.waitUntil(() => contenu() === 'Séances du serveur' && montre().dessine === 1, { timeout: 3000 })

        expect(montre()).toEqual({ indisponible: 0, dessine: 1 })
        expect(relais.rejets).toEqual([])
    })
})
