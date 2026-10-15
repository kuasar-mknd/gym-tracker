import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, it, expect, vi, beforeAll, beforeEach, afterEach, afterAll } from 'vitest'
import { createApp, h } from 'vue'
import {
    DELAI_DU_RESEAU_LENT_MS,
    ECRITURE_NON_CONFIRMEE,
    EVENEMENT_SANS_RESEAU,
    PAGE_INDISPONIBLE,
    RESEAU_LENT,
    direSansReseau,
    installerLesVisitesHorsLigne,
} from '@/Utils/visitesHorsLigne'
import { installerLeRapporteurDErreurs } from '@/Utils/rapporteurDErreurs'
import { noterQueLeServeurRepond, useEtatDuReseau } from '@/composables/useEtatDuReseau'
import { RequeteSimulee, relayerLesRejetsNonGeres, reponseInertia, requetes, reseau, uneTache } from './reseauSimule'
import { jsRoot } from '../conventions/sourceFiles'

/*
 * Sans réseau, l'application le dit (#2048), avec le vrai client d'Inertia.
 *
 * Réseau coupé, un appui sur Accueil, Séances ou la flèche de l'en-tête ne
 * faisait rien : la visite échouait, Inertia rejetait une promesse que
 * personne n'attend, et le rapporteur d'erreurs tentait en vain de l'envoyer.
 * Sur un réseau qui ne répond plus, la visite attendait sans fin.
 */

let routeur
const annonces = []
const envoisAuRapporteur = []
let relais

const ecouterLesAnnonces = (evenement) => annonces.push(evenement.detail)

const contenu = () => document.getElementById('contenu').textContent

/** Lance la visite et attend sa fin, quelle qu'en soit l'issue. */
const visiter = async (adresse, options = {}) => {
    let finie = false

    routeur.visit(adresse, { ...options, onFinish: () => (finie = true) })
    await vi.waitUntil(() => finie)
    await uneTache()
}

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    // jsdom ne fait pas défiler la page, et le dit à chaque visite.
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', '/workouts/12')
    globalThis.route = (nom) => `/${nom}`

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router

    installerLesVisitesHorsLigne({ routeur })

    const Page = {
        props: ['texte'],
        render() {
            return h('p', { id: 'contenu' }, this.texte)
        },
    }

    await inertia.createInertiaApp({
        page: {
            component: 'Workouts/Show',
            url: '/workouts/12',
            version: 'version-de-la-page',
            props: { errors: {}, texte: 'Séance en cours' },
        },
        resolve: () => Page,
        setup: ({ el, App, props, plugin }) => {
            const app = createApp({ render: () => h(App, props) }).use(plugin)

            installerLeRapporteurDErreurs(app, { envoyer: (charge) => envoisAuRapporteur.push(charge) })

            return app.mount(el)
        },
    })

    window.addEventListener(EVENEMENT_SANS_RESEAU, ecouterLesAnnonces)
})

beforeEach(() => {
    requetes.length = 0
    annonces.length = 0
    envoisAuRapporteur.length = 0
    reseau.mode = 'coupe'
    reseau.prochains.length = 0
    reseau.reponse = null
    noterQueLeServeurRepond()
    relais = relayerLesRejetsNonGeres()
})

afterEach(() => {
    relais.retirer()
    vi.useRealTimers()
})

afterAll(() => {
    window.removeEventListener(EVENEMENT_SANS_RESEAU, ecouterLesAnnonces)
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('sans réseau, une visite le dit', () => {
    it('un lien vers une autre page affiche « Cette page n’est pas disponible sans réseau », sans rejet ni envoi au rapporteur', async () => {
        await visiter('/stats')

        expect(requetes).toHaveLength(1)
        expect(annonces).toEqual([{ message: PAGE_INDISPONIBLE, annuler: null }])
        expect(relais.rejets).toEqual([])
        expect(envoisAuRapporteur).toEqual([])
        expect(contenu()).toBe('Séance en cours')
        expect(useEtatDuReseau().joignable.value).toBe(false)
    })

    it('une écriture dit que sa confirmation manque, sans dire que rien n’est enregistré', async () => {
        await visiter('/workouts', { method: 'post' })

        expect(annonces).toEqual([{ message: ECRITURE_NON_CONFIRMEE, annuler: null }])
        expect(ECRITURE_NON_CONFIRMEE).not.toMatch(/rien n.a été enregistré/)
        expect(relais.rejets).toEqual([])
    })

    it('un rechargement en arrière-plan échoue sans un mot, mais le serveur n’est plus joignable', async () => {
        let fini = false
        routeur.reload({ only: ['texte'], onFinish: () => (fini = true) })
        await vi.waitUntil(() => fini)
        await uneTache()

        expect(requetes).toHaveLength(1)
        expect(annonces).toEqual([])
        expect(relais.rejets).toEqual([])
        expect(useEtatDuReseau().joignable.value).toBe(false)
    })

    it('une visite qui dit elle-même son échec ne reçoit pas le message général', async () => {
        await visiter('/workouts', {
            method: 'post',
            onNetworkError: direSansReseau('Il faut le réseau pour démarrer une séance.'),
        })

        expect(annonces).toEqual([{ message: 'Il faut le réseau pour démarrer une séance.', annuler: null }])
        expect(relais.rejets).toEqual([])
        expect(useEtatDuReseau().joignable.value).toBe(false)
    })

    it('le serveur redevient joignable à la première réponse, jamais au seul retour de l’interface réseau', async () => {
        await visiter('/stats')
        window.dispatchEvent(new Event('online'))

        expect(useEtatDuReseau().joignable.value).toBe(false)

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia({
            component: 'Workouts/Show',
            url: '/workouts/12',
            version: 'version-de-la-page',
            props: { texte: 'Séance en cours' },
        })
        await visiter('/workouts/12', { preserveState: true })

        expect(useEtatDuReseau().joignable.value).toBe(true)

        window.dispatchEvent(new Event('offline'))

        expect(useEtatDuReseau().joignable.value).toBe(false)
    })

    /*
     * Une page qui porte des erreurs de validation est une réponse du serveur :
     * Inertia n'émet alors que `error`, pas `success`.
     */
    it('le serveur redevient joignable sur une réponse qui porte des erreurs de validation', async () => {
        await visiter('/stats')

        expect(useEtatDuReseau().joignable.value).toBe(false)

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia({
            component: 'Workouts/Show',
            url: '/workouts/12',
            version: 'version-de-la-page',
            props: { texte: 'Séance en cours', errors: { name: 'Le nom est obligatoire.' } },
        })
        let erreursRecues = null
        await visiter('/workouts/12', {
            method: 'patch',
            preserveState: true,
            onError: (erreurs) => (erreursRecues = erreurs),
        })

        expect(erreursRecues).toEqual({ name: 'Le nom est obligatoire.' })
        expect(useEtatDuReseau().joignable.value).toBe(true)
    })
})

describe('ce que le filet laisse passer', () => {
    /*
     * Inertia fait passer par `networkError` toute erreur levée pendant le
     * traitement d'une réponse, une erreur de programmation comprise : le
     * rapporteur doit encore la voir.
     */
    it('une erreur qui ne vient pas du réseau n’est ni avalée, ni annoncée', () => {
        const erreur = new TypeError('Cannot read properties of null')
        const evenement = new CustomEvent('inertia:networkError', { cancelable: true, detail: { error: erreur } })

        document.dispatchEvent(evenement)

        expect(evenement.defaultPrevented).toBe(false)
        expect(annonces).toEqual([])
        expect(useEtatDuReseau().joignable.value).toBe(true)
        expect(direSansReseau('Il faut le réseau pour démarrer une séance.')(erreur)).toBeUndefined()
        expect(annonces).toEqual([])
    })
})

describe('un réseau qui ne répond pas', () => {
    it('au bout de huit secondes, « Le réseau ne répond pas », et « Annuler » arrête la visite', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
        reseau.mode = 'muet'
        let annulee = false
        let finie = false

        routeur.visit('/dashboard', { onCancel: () => (annulee = true), onFinish: () => (finie = true) })
        await vi.advanceTimersByTimeAsync(DELAI_DU_RESEAU_LENT_MS - 1)

        expect(requetes).toHaveLength(1)
        expect(annonces).toEqual([])

        await vi.advanceTimersByTimeAsync(1)

        expect(annonces).toEqual([{ message: RESEAU_LENT, annuler: expect.any(Function) }])

        annonces[0].annuler()
        await vi.advanceTimersByTimeAsync(0)

        expect(annulee).toBe(true)
        expect(finie).toBe(true)
        expect(requetes[0].annulee).toBe(true)
        // L'annonce de la lenteur se retire avec sa visite.
        expect(annonces.at(-1)).toEqual({ message: null, retire: RESEAU_LENT })
        expect(contenu()).toBe('Séance en cours')
    })

    it('ne dit rien d’une visite qui aboutit avant le délai', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
        reseau.mode = 'muet'
        let finie = false

        routeur.visit('/dashboard', { onFinish: () => (finie = true) })
        await vi.advanceTimersByTimeAsync(DELAI_DU_RESEAU_LENT_MS - 1000)
        requetes[0].repondre(
            reponseInertia({
                component: 'Dashboard',
                url: '/dashboard',
                version: 'version-de-la-page',
                props: { texte: 'Accueil' },
            }),
        )
        await vi.waitUntil(() => finie)
        await vi.advanceTimersByTimeAsync(DELAI_DU_RESEAU_LENT_MS)

        expect(annonces).toEqual([])
        expect(contenu()).toBe('Accueil')
    })

    /*
     * L'évènement ne dit que l'adresse de la requête : l'échec d'un rechargement
     * de la page courante, parti pendant une visite au premier plan, ne doit
     * pas être imputé à celle-ci, ni lui annoncer une page indisponible, ni
     * couper son minuteur de lenteur.
     */
    it('n’impute pas à la visite au premier plan l’échec d’un rechargement parti pendant elle', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
        reseau.prochains.push('muet', 'coupe')
        let rechargementFini = false
        const pageCourante = window.location.pathname

        routeur.visit('/exercises')
        routeur.reload({ only: ['texte'], onFinish: () => (rechargementFini = true) })
        await vi.advanceTimersByTimeAsync(0)

        expect(requetes.map((requete) => new URL(requete.url, window.location.href).pathname)).toEqual([
            '/exercises',
            pageCourante,
        ])
        expect(rechargementFini).toBe(true)
        expect(annonces).toEqual([])
        expect(relais.rejets).toEqual([])

        await vi.advanceTimersByTimeAsync(DELAI_DU_RESEAU_LENT_MS)

        expect(annonces).toEqual([{ message: RESEAU_LENT, annuler: expect.any(Function) }])

        routeur.cancelAll()
    })

    it('ne compte pas le délai d’un rechargement en arrière-plan', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
        reseau.mode = 'muet'

        routeur.reload({ only: ['texte'] })
        await vi.advanceTimersByTimeAsync(DELAI_DU_RESEAU_LENT_MS * 2)

        expect(annonces).toEqual([])

        routeur.cancelAll()
    })
})

describe('le branchement', () => {
    /*
     * Le filet ne sert que posé sur le routeur de l'application : sans lui,
     * tout ce qui précède redevient silencieux dans le navigateur.
     */
    it('est posé par main.js sur le routeur d’Inertia, avant le montage de l’application', () => {
        const main = readFileSync(resolve(jsRoot, 'main.js'), 'utf-8')
        const pose = main.indexOf('installerLesVisitesHorsLigne({ routeur: router })')

        expect(pose).toBeGreaterThan(-1)
        expect(pose).toBeLessThan(main.indexOf('createInertiaApp({'))
    })
})
