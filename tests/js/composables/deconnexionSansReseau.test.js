import { describe, it, expect, vi, beforeAll, beforeEach, afterEach, afterAll } from 'vitest'
import { createApp, h } from 'vue'
import { EVENEMENT_SANS_RESEAU, installerLesVisitesHorsLigne } from '@/Utils/visitesHorsLigne'
import {
    RequeteSimulee,
    relayerLesRejetsNonGeres,
    reponseInertia,
    requetes,
    reseau,
    uneTache,
} from '../utils/reseauSimule'

/*
 * Se déconnecter sans réseau, avec le vrai client d'Inertia (#2048).
 *
 * La déconnexion partait, échouait sans un mot, et la personne se croyait
 * sortie de son compte. Et quand un écouteur `before` annulait la visite,
 * Inertia n'appelait aucun rappel : la déconnexion restait « en cours », et
 * chaque clic suivant rendait la même promesse sans rien envoyer.
 */

// Le détachement de l'appareil et la file ont leurs propres tests.
vi.mock('@/composables/useAbonnementPush', () => ({ detacherLAppareil: () => Promise.resolve() }))
const laisse = vi.hoisted(() => ({ refusEffaces: [] }))
vi.mock('@/Utils/SyncService', () => ({
    default: {
        compte: '1',
        enAttente: () => 0,
        clearFailedRequests: (compte) => laisse.refusEffaces.push(compte),
    },
}))

let routeur
let seDeconnecter
let DECONNEXION_SANS_RESEAU
const annonces = []
let relais

const ecouterLesAnnonces = (evenement) => annonces.push(evenement.detail.message)

const logouts = () => requetes.filter((requete) => requete.url.endsWith('/logout'))

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', '/dashboard')
    globalThis.route = (nom) => `/${nom}`

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router
    ;({ seDeconnecter, DECONNEXION_SANS_RESEAU } = await import('@/composables/useDeconnexion'))

    installerLesVisitesHorsLigne({ routeur })

    const Page = {
        props: ['texte'],
        render() {
            return h('p', { id: 'contenu' }, this.texte)
        },
    }

    await inertia.createInertiaApp({
        page: { component: 'Dashboard', url: '/dashboard', version: 'v', props: { errors: {}, texte: 'Accueil' } },
        resolve: () => Page,
        setup: ({ el, App, props, plugin }) =>
            createApp({ render: () => h(App, props) })
                .use(plugin)
                .mount(el),
    })

    window.addEventListener(EVENEMENT_SANS_RESEAU, ecouterLesAnnonces)
})

beforeEach(() => {
    requetes.length = 0
    annonces.length = 0
    laisse.refusEffaces.length = 0
    reseau.mode = 'coupe'
    reseau.reponse = null
    relais = relayerLesRejetsNonGeres()
})

afterEach(() => {
    relais.retirer()
})

afterAll(() => {
    window.removeEventListener(EVENEMENT_SANS_RESEAU, ecouterLesAnnonces)
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

const attendreLaFinDeLaVisite = async () => {
    let finie = false
    const retirer = routeur.on('finish', () => {
        finie = true
    })

    return async () => {
        await vi.waitUntil(() => finie)
        retirer()
        await uneTache()
    }
}

describe('se déconnecter sans réseau', () => {
    it('le dit, laisse repartir une deuxième tentative, et aboutit au retour du réseau', async () => {
        let fin = await attendreLaFinDeLaVisite()
        await seDeconnecter()
        await fin()

        expect(logouts()).toHaveLength(1)
        expect(annonces).toEqual([DECONNEXION_SANS_RESEAU])
        expect(relais.rejets).toEqual([])
        // Toujours connectée : rien de ce que le compte laisse n'est effacé.
        expect(laisse.refusEffaces).toEqual([])

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia({
            component: 'Auth/Login',
            url: '/login',
            version: 'v',
            props: { texte: 'Connexion' },
        })
        fin = await attendreLaFinDeLaVisite()
        await seDeconnecter()
        await fin()

        expect(logouts()).toHaveLength(2)
        expect(document.getElementById('contenu').textContent).toBe('Connexion')
        expect(laisse.refusEffaces).toEqual(['1'])
        expect(annonces).toEqual([DECONNEXION_SANS_RESEAU])
    })

    it('repart quand un écouteur `before` a annulé la visite précédente', async () => {
        const annulerToutDepart = routeur.on('before', () => false)

        await seDeconnecter()
        await uneTache()
        annulerToutDepart()

        expect(logouts()).toHaveLength(0)

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia({
            component: 'Auth/Login',
            url: '/login',
            version: 'v',
            props: { texte: 'Connexion' },
        })
        const fin = await attendreLaFinDeLaVisite()
        await seDeconnecter()
        await fin()

        expect(logouts()).toHaveLength(1)
    })

    /*
     * Une visite annulée en route passe par `onCancel`, puis par `onFinish` :
     * la déconnexion se libère par là, sans rappel à part.
     */
    it('repart quand la visite précédente a été annulée en route', async () => {
        reseau.mode = 'muet'
        let fin = await attendreLaFinDeLaVisite()
        await seDeconnecter()
        routeur.cancelAll()
        await fin()

        expect(logouts()).toHaveLength(1)
        expect(logouts()[0].annulee).toBe(true)

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia({
            component: 'Auth/Login',
            url: '/login',
            version: 'v',
            props: { texte: 'Connexion' },
        })
        fin = await attendreLaFinDeLaVisite()
        await seDeconnecter()
        await fin()

        expect(logouts()).toHaveLength(2)
        expect(annonces).toEqual([])
    })
})
