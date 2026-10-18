import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'

import { creerUneFabriqueEnMemoire } from './baseIndexeeEnMemoire'
import { RequeteSimulee, relayerLesRejetsNonGeres, reponseInertia, requetes, reseau, uneTache } from './reseauSimule'
import { CLEF_DU_TITULAIRE_DE_L_APPAREIL } from '@/Utils/historiqueDuCompte'

/*
 * Une copie hors ligne au retour du réseau, et aucune écriture depuis elle
 * (#2048, partie E), avec le vrai client d'Inertia et le vrai magasin chiffré,
 * sur une base en mémoire.
 *
 * À la première réponse réussie du serveur (une visite, `http()`, le rejeu de
 * la file, la sonde : tous le notent par `noterQueLeServeurRepond`), une
 * copie se recharge en entier, la séance seulement une fois sa file vide et
 * rien en vol ; l'historique qui ramène à une copie quand le serveur répond
 * la recharge aussi ; un échec ne dit rien. Une écriture partie d'une copie
 * est arrêtée, le dit, et fait recharger la page.
 *
 * Le client se monte une fois pour le fichier ; chaque cas repart des
 * séances, remises par une visite du client seul, d'un magasin vide et d'une
 * file de la séance enregistrée (#2044).
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

/** La page d'où partent les cas. */
const pageDeDepart = () => pageDe({ url: '/tools', component: 'Tools/Index', texte: 'Outils' })

let routeur
let fabrique
let magasin
let etatDuReseau
let visites
let application
let relais
let pageInertia

const annonces = []
const ecouterLesAnnonces = (evenement) => annonces.push(evenement.detail)

/** Ce que la file de la séance dit encore : en attente, en vol. */
const file = { enAttente: 0, enVol: 0, demandes: 0 }

const contenu = () => document.getElementById('contenu')?.textContent

/** Les requêtes parties depuis `depuis`, réduites à ce qu'on en lit. */
const requetesDepuis = (depuis) =>
    requetes.slice(depuis).map((requete) => ({
        methode: requete.methode.toUpperCase(),
        chemin: new URL(requete.url, window.location.href).pathname,
        partielle: requete.entetes['X-Inertia-Partial-Data'] !== undefined,
    }))

/** Lance une visite et attend sa fin, quelle qu'en soit l'issue. */
const visiter = async (adresse, options = {}) => {
    let finie = false

    routeur.visit(adresse, { ...options, onFinish: () => (finie = true) })
    await vi.waitUntil(() => finie)
    await uneTache()
}

/** Sert, sans réseau, la copie d'une page gardée. */
const servirLaCopieDe = async (page) => {
    expect(await magasin.ecrireUneCopie(page)).toBe('ecrite')
    reseau.mode = 'coupe'
    await visiter(page.url)
    await vi.waitUntil(() => pageInertia?.props?.horsLigne !== undefined && pageInertia.url === page.url, {
        timeout: 3000,
    })
}

/** Laisse passer un moment : assez pour qu'un rechargement de trop parte. */
const unMoment = () => new Promise((resoudre) => setTimeout(resoudre, 150))

const Page = {
    props: ['texte'],
    render() {
        return h('p', { id: 'contenu' }, this.texte)
    },
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

    visites.installerLesVisitesHorsLigne({
        routeur,
        lireUneCopie: (adresse) => magasin.lireUneCopie(adresse),
        laSeanceEstEnregistree: async () => {
            file.demandes += 1

            return file.enAttente === 0 && file.enVol === 0
        },
    })

    for (const evenement of ['navigate', 'clientVisit', 'success']) {
        routeur.on(evenement, (detail) => (pageInertia = detail.detail.page))
    }

    await inertia.createInertiaApp({
        page: pageDeDepart(),
        resolve: () => Page,
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

    fabrique.enregistrements('gym-tracker-copies', 'pages').clear()
    fabrique.enregistrements('gym-tracker-copies', 'etat').clear()
    localStorage.clear()
    localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, `compte:${COMPTE}`)
    document.cookie = `gt_copies=${cleAuHasard()}; path=/`
    Object.assign(file, { enAttente: 0, enVol: 0, demandes: 0 })
    requetes.length = 0
    annonces.length = 0
    reseau.mode = 'coupe'
    reseau.prochains.length = 0
    reseau.reponse = null
    relais = relayerLesRejetsNonGeres()
})

afterEach(async () => {
    relais.retirer()
    routeur.cancelAll()
    await uneTache()
})

afterAll(async () => {
    window.removeEventListener(visites.EVENEMENT_SANS_RESEAU, ecouterLesAnnonces)
    application.unmount()
    await magasin.fermer()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('au retour du réseau, une copie se recharge en entier', () => {
    it('à la première réponse réussie du serveur, et la marque part avec elle', async () => {
        await servirLaCopieDe(pageDe())
        const depuis = requetes.length

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(pageDe({ texte: 'Séances du serveur' }))
        etatDuReseau.noterQueLeServeurRepond()
        await vi.waitUntil(() => contenu() === 'Séances du serveur')
        await unMoment()

        expect(requetesDepuis(depuis)).toEqual([{ methode: 'GET', chemin: '/workouts', partielle: false }])
        expect(pageInertia.props.horsLigne).toBeUndefined()
        expect(annonces).toEqual([])
        expect(relais.rejets).toEqual([])
    })

    it('après une réponse partielle d’Inertia, qui garde la marque, la page entière se redemande', async () => {
        await servirLaCopieDe(pageDe())
        const depuis = requetes.length

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(pageDe({ texte: 'Séances du serveur' }))
        let marqueApresLaPartielle
        routeur.reload({ only: ['texte'], onSuccess: (page) => (marqueApresLaPartielle = page.props.horsLigne) })
        await vi.waitUntil(() => marqueApresLaPartielle !== undefined)

        // Fusionnée, la réponse partielle a laissé la marque de la copie : la page entière se redemande ensuite.
        expect(marqueApresLaPartielle).toMatchObject({ raison: 'reseau' })

        await vi.waitUntil(() => pageInertia.props.horsLigne === undefined)

        expect(requetesDepuis(depuis)).toEqual([
            { methode: 'GET', chemin: '/workouts', partielle: true },
            { methode: 'GET', chemin: '/workouts', partielle: false },
        ])
    })

    it('un rechargement qui échoue ne dit rien, et la réponse suivante le refait', async () => {
        await servirLaCopieDe(pageDe())
        const depuis = requetes.length

        etatDuReseau.noterQueLeServeurRepond()
        await vi.waitUntil(() => requetes.length === depuis + 1)
        await unMoment()

        expect(pageInertia.props.horsLigne).toBeDefined()
        expect(annonces).toEqual([])
        expect(relais.rejets).toEqual([])

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(pageDe({ texte: 'Séances du serveur' }))
        etatDuReseau.noterQueLeServeurRepond()
        await vi.waitUntil(() => contenu() === 'Séances du serveur')

        expect(requetesDepuis(depuis).map((requete) => requete.partielle)).toEqual([false, false])
    })

    it('une salve de réponses ne lance qu’un rechargement à la fois', async () => {
        await servirLaCopieDe(pageDe())
        const depuis = requetes.length

        reseau.mode = 'muet'
        etatDuReseau.noterQueLeServeurRepond()
        etatDuReseau.noterQueLeServeurRepond()
        etatDuReseau.noterQueLeServeurRepond()
        await unMoment()

        expect(requetesDepuis(depuis)).toHaveLength(1)
    })
})

describe('la séance attend sa file', () => {
    const seance = () => pageDe({ url: '/workouts/15', component: 'Workouts/Show', texte: 'Séance' })

    it('ne se recharge qu’une fois la file vide et rien en vol', async () => {
        await servirLaCopieDe(seance())
        const depuis = requetes.length
        file.enAttente = 1
        file.enVol = 1

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(seance())
        etatDuReseau.noterQueLeServeurRepond()
        await vi.waitUntil(() => file.demandes === 1)
        await unMoment()

        expect(requetesDepuis(depuis)).toEqual([])

        // La file se vide, mais une écriture vole encore.
        file.enAttente = 0
        window.dispatchEvent(new CustomEvent('sync:replayed', { detail: { queueId: 1 } }))
        await vi.waitUntil(() => file.demandes === 2)
        await unMoment()

        expect(requetesDepuis(depuis)).toEqual([])

        // Le dernier refus de la file sort sans réponse réussie : la séance n'attend plus rien.
        file.enVol = 0
        window.dispatchEvent(new CustomEvent('sync:failed', { detail: { queueId: 2 } }))
        await vi.waitUntil(() => pageInertia.props.horsLigne === undefined)

        expect(requetesDepuis(depuis)).toEqual([{ methode: 'GET', chemin: '/workouts/15', partielle: false }])
    })

    it('une autre page n’attend pas la file', async () => {
        await servirLaCopieDe(pageDe())
        file.enAttente = 3

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(pageDe({ texte: 'Séances du serveur' }))
        etatDuReseau.noterQueLeServeurRepond()
        await vi.waitUntil(() => contenu() === 'Séances du serveur')

        expect(file.demandes).toBe(0)
    })
})

describe('l’historique qui ramène à une copie', () => {
    it('la recharge quand le serveur répond, et la laisse sinon', async () => {
        await servirLaCopieDe(pageDe())

        // Le réseau revient, et la personne ouvre une autre page avant que la copie ne se recharge.
        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(pageDe({ url: '/dashboard', component: 'Dashboard', texte: 'Accueil' }))
        await visiter('/dashboard')
        await unMoment()
        const depuis = requetes.length

        // Retour : la copie revient de l'historique, et le serveur répond.
        reseau.reponse = reponseInertia(pageDe({ texte: 'Séances du serveur' }))
        window.history.back()
        await vi.waitUntil(() => contenu() === 'Séances du serveur', { timeout: 3000 })

        expect(requetesDepuis(depuis)).toEqual([{ methode: 'GET', chemin: '/workouts', partielle: false }])
    })

    it('ne recharge rien quand le serveur ne répond pas', async () => {
        await servirLaCopieDe(pageDe())

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(pageDe({ url: '/dashboard', component: 'Dashboard', texte: 'Accueil' }))
        await visiter('/dashboard')
        await unMoment()

        reseau.mode = 'coupe'
        etatDuReseau.noterQueLeServeurNeRepondPas()
        const depuis = requetes.length
        window.history.back()
        await vi.waitUntil(() => contenu() === 'Séances', { timeout: 3000 })
        await unMoment()

        expect(pageInertia.props.horsLigne).toBeDefined()
        expect(requetesDepuis(depuis)).toEqual([])
    })
})

describe('aucune écriture ne part d’une copie', () => {
    it.each(['post', 'put', 'patch', 'delete'])(
        'une visite en %s est arrêtée avant son départ, le dit, et la page se recharge',
        async (methode) => {
            await servirLaCopieDe(pageDe())
            const depuis = requetes.length
            let annulee = false

            reseau.mode = 'repond'
            reseau.reponse = reponseInertia(pageDe({ texte: 'Séances du serveur' }))
            routeur.visit('/workouts', { method: methode, data: { nom: 'Jambes' }, onCancel: () => (annulee = true) })
            await vi.waitUntil(() => contenu() === 'Séances du serveur')

            expect(requetesDepuis(depuis)).toEqual([{ methode: 'GET', chemin: '/workouts', partielle: false }])
            expect(annonces).toEqual([{ message: visites.ECRITURE_DEPUIS_UNE_COPIE, annuler: null }])
            // Arrêtée par `before`, la visite n'appelle aucun rappel.
            expect(annulee).toBe(false)
            expect(relais.rejets).toEqual([])
        },
    )

    it('une écriture part de la page rechargée', async () => {
        await servirLaCopieDe(pageDe())

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(pageDe({ texte: 'Séances du serveur' }))
        etatDuReseau.noterQueLeServeurRepond()
        await vi.waitUntil(() => contenu() === 'Séances du serveur')
        const depuis = requetes.length

        await visiter('/workouts', { method: 'post', data: { nom: 'Jambes' } })

        expect(requetesDepuis(depuis)).toEqual([{ methode: 'POST', chemin: '/workouts', partielle: false }])
    })

    it('la visite exemptée part d’une copie', async () => {
        await servirLaCopieDe(pageDe())
        const depuis = requetes.length

        reseau.mode = 'muet'
        routeur.post('/deconnexion-exemptee', {}, { onBefore: visites.exempterDeLaGardeDesCopies })
        await vi.waitUntil(() => requetes.length === depuis + 1)

        expect(requetesDepuis(depuis)).toEqual([{ methode: 'POST', chemin: '/deconnexion-exemptee', partielle: false }])
        expect(annonces).toEqual([])
    })
})
