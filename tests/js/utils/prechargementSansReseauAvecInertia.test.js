import { describe, it, expect, vi, beforeAll, beforeEach, afterEach, afterAll } from 'vitest'
import { createApp, h } from 'vue'
import {
    EVENEMENT_SANS_RESEAU,
    PAGE_INDISPONIBLE,
    RESEAU_LENT,
    installerLesVisitesHorsLigne,
} from '@/Utils/visitesHorsLigne'
import { installerLeRapporteurDErreurs } from '@/Utils/rapporteurDErreurs'
import { noterQueLeServeurRepond } from '@/composables/useEtatDuReseau'
import {
    RequeteSimulee,
    appelsDeFetch,
    fetchSimule,
    relayerLesRejetsNonGeres,
    reponseInertia,
    requetes,
    reseau,
    uneTache,
} from './reseauSimule'

/*
 * Un préchargement qui échoue sans réseau ne fige rien (#2148, étape 2b), avec
 * le vrai client d'Inertia et le vrai filet des visites hors ligne.
 *
 * Le filet rend `false` à tout échec du réseau (`networkError`), préchargements
 * compris : Inertia n'appelle alors pas `onPrefetchError`, qui retirait
 * l'entrée de ceux qui sont en vol, et aucune réponse n'appelle
 * `onPrefetchResponse`. L'entrée restait « en vol » pour toujours, et la
 * visite suivante vers cette adresse s'y accrochait : réseau revenu, elle
 * n'envoyait rien et ne finissait jamais, sans `start`, donc sans copie ni
 * toast de lenteur. Laisser passer l'évènement ne suffit pas : l'entrée sort
 * bien du cache, mais Inertia lève deux rejets que personne n'attend, que le
 * rapporteur d'erreurs enverrait au serveur, le plus souvent en pure perte
 * pendant la coupure. Le filet vide donc le cache des préchargements
 * (`router.flushAll()`) et garde son `false`.
 *
 * Le rapporteur est le vrai, avec son envoi par `fetch` vers
 * `erreurs-navigateur.store`, sur le réseau simulé : un envoi s'y verrait.
 *
 * Le filet attend ici 300 ms avant le toast de lenteur et 200 ms avant une
 * copie, et le magasin n'en a aucune : une visite qu'il suit dit sa lenteur
 * puis son échec sans faire attendre le test.
 */

const DELAI_DU_RESEAU_LENT_MS = 300

/** La page de départ, telle que le serveur l'a rendue. */
const accueil = () => ({
    component: 'Dashboard',
    url: '/dashboard',
    version: 'v',
    props: { errors: {}, texte: 'Accueil' },
})

/** Une page que le serveur rend à l'adresse demandée. */
const pageDe = (adresse, texte) => ({
    component: 'Dashboard',
    url: adresse,
    version: 'v',
    props: { errors: {}, texte },
})

let routeur
let application
let relais

/** Les messages des toasts que le filet a montrés, dans l'ordre, et leur bouton « Annuler ». */
let annonces = []

const ecouterLesAnnonces = (evenement) => {
    if (evenement.detail.message !== null) {
        annonces.push({ message: evenement.detail.message, annuler: evenement.detail.annuler })
    }
}

const messages = () => annonces.map((annonce) => annonce.message)

const attendre = (millisecondes) => new Promise((resoudre) => setTimeout(resoudre, millisecondes))

const contenu = () => document.getElementById('contenu').textContent

const chemins = () => requetes.map((requete) => new URL(requete.url, window.location.href).pathname)

/** Ce que le rapporteur d'erreurs a tenté d'envoyer au serveur. */
const envoisDErreurs = () => appelsDeFetch.filter((appel) => appel.adresse === '/erreurs-navigateur')

/** Précharge `adresse` sans réseau, et attend que l'échec soit passé par le filet. */
const prechargerSansReseau = async (adresse) => {
    const parties = requetes.length

    reseau.mode = 'coupe'
    routeur.prefetch(adresse)
    await vi.waitUntil(() => requetes.length === parties + 1, { interval: 1 })
    await uneTache()
}

/** Lance la visite et attend sa fin, une seconde et demie au plus. */
const visiter = async (adresse) => {
    let finie = false
    let reussie = false

    routeur.visit(adresse, { onSuccess: () => (reussie = true), onFinish: () => (finie = true) })
    await vi.waitUntil(() => finie, { timeout: 1500, interval: 1 }).catch(() => {})
    await uneTache()

    return { finie, reussie }
}

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.stubGlobal('fetch', fetchSimule)
    vi.stubGlobal('route', (nom) => (nom === 'erreurs-navigateur.store' ? '/erreurs-navigateur' : `/${nom}`))
    // jsdom ne fait pas défiler la page, et le dit à chaque visite.
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', '/dashboard')

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router

    installerLesVisitesHorsLigne({
        routeur,
        delaiDuReseauLent: DELAI_DU_RESEAU_LENT_MS,
        delaiAvantUneCopie: 200,
        lireUneCopie: async () => null,
    })
    window.addEventListener(EVENEMENT_SANS_RESEAU, ecouterLesAnnonces)

    const Page = {
        props: ['texte'],
        render() {
            return h('p', { id: 'contenu' }, this.texte)
        },
    }

    await inertia.createInertiaApp({
        page: accueil(),
        resolve: () => Page,
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) }).use(plugin)
            installerLeRapporteurDErreurs(application)

            return application.mount(el)
        },
    })
})

beforeEach(async () => {
    // Une visite du client seul, sans requête : l'accueil revient à l'écran.
    let remise = false
    routeur.replace({ ...accueil(), onFinish: () => (remise = true) })
    await vi.waitUntil(() => remise)

    requetes.length = 0
    appelsDeFetch.length = 0
    reseau.mode = 'coupe'
    reseau.prochains.length = 0
    reseau.reponse = null
    noterQueLeServeurRepond()
    relais = relayerLesRejetsNonGeres()
    annonces = []
})

afterEach(() => {
    relais.retirer()
    routeur.cancelAll()
    routeur.flushAll()
})

afterAll(() => {
    window.removeEventListener(EVENEMENT_SANS_RESEAU, ecouterLesAnnonces)
    application.unmount()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('un préchargement qui échoue sans réseau', () => {
    it('ne laisse aucune entrée en vol derrière lui', async () => {
        await prechargerSansReseau('/stats')

        expect(routeur.getPrefetching('/stats')).toBeFalsy()
    })

    it('réseau revenu, laisse la visite suivante partir et aboutir', async () => {
        await prechargerSansReseau('/stats')

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(pageDe('/stats', 'Statistiques'))

        const visite = await visiter('/stats')

        expect(chemins()).toEqual(['/stats', '/stats'])
        expect(visite).toEqual({ finie: true, reussie: true })
        expect(contenu()).toBe('Statistiques')
    })

    it('ne lève aucun rejet que personne n’attend, et le rapporteur n’envoie rien', async () => {
        await prechargerSansReseau('/stats')
        await uneTache()

        expect(relais.rejets).toEqual([])
        expect(envoisDErreurs()).toEqual([])
    })

    /*
     * Le filet vide tout le cache des préchargements : les autres pages
     * préchargées partent avec, ce qui ne coûte rien sans réseau. Une visite
     * au premier plan qui échoue, elle, ne vide rien.
     */
    it('vide aussi les autres pages préchargées, et une visite au premier plan qui échoue n’en vide aucune', async () => {
        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(pageDe('/workouts', 'Séances'))
        routeur.prefetch('/workouts', {}, { cacheFor: 30_000 })
        await vi.waitUntil(() => Boolean(routeur.getCached('/workouts')), { interval: 1 })

        reseau.mode = 'coupe'
        const auPremierPlan = await visiter('/calendar')

        expect(auPremierPlan).toEqual({ finie: true, reussie: false })
        expect(routeur.getCached('/workouts')).toBeTruthy()

        await prechargerSansReseau('/stats')

        expect(routeur.getCached('/workouts')).toBeFalsy()
        expect(relais.rejets).toEqual([])
    })
})

/*
 * Une visite au premier plan lancée pendant qu'un préchargement de la même
 * adresse est en vol : un survol suivi d'un clic, ou un toucher pendant un
 * préchargement au repos, sur un réseau de salle qui ne répond plus. Inertia
 * l'accroche à la promesse du préchargement (`get()` puis `use()`) : elle
 * n'envoie rien, n'émet ni `start` ni `finish`, et ne finit que par la
 * réponse du préchargement. Quand il échouait, le filet vidait bien le cache,
 * mais la promesse à laquelle la visite s'était accrochée ne se réglait
 * jamais : le toucher se perdait sans un mot, sans copie ni toast de lenteur.
 * Le filet la suit donc dès `before`, comme une visite partie, et son échec
 * est celui du préchargement.
 */
describe('une visite partie pendant le vol du préchargement', () => {
    /** Précharge `/stats` sur un réseau qui ne répond plus, puis la visite s'y accroche. */
    const visiterPendantLeVol = async () => {
        const visite = { finie: false, reussie: false }

        reseau.mode = 'muet'
        routeur.prefetch('/stats')
        await vi.waitUntil(() => requetes.length === 1, { interval: 1 })
        routeur.visit('/stats', {
            onSuccess: () => (visite.reussie = true),
            onFinish: () => (visite.finie = true),
        })
        await uneTache()

        return visite
    }

    it('n’envoie rien, et dit que la page n’est pas disponible quand le préchargement échoue', async () => {
        await visiterPendantLeVol()

        expect(chemins()).toEqual(['/stats'])

        requetes[0].onerror()
        await vi.waitUntil(() => annonces.length > 0, { timeout: 1000, interval: 1 }).catch(() => {})

        expect(messages()).toEqual([PAGE_INDISPONIBLE])
        expect(routeur.getPrefetching('/stats')).toBeFalsy()
        expect(contenu()).toBe('Accueil')
        expect(relais.rejets).toEqual([])
        expect(envoisDErreurs()).toEqual([])

        // Réseau revenu, le toucher suivant part et aboutit ; la visite accrochée n'annonce plus rien.
        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(pageDe('/stats', 'Statistiques'))

        const suivante = await visiter('/stats')
        await attendre(DELAI_DU_RESEAU_LENT_MS + 100)

        expect(suivante).toEqual({ finie: true, reussie: true })
        expect(contenu()).toBe('Statistiques')
        expect(chemins()).toEqual(['/stats', '/stats'])
        expect(messages()).toEqual([PAGE_INDISPONIBLE])
    })

    it('a son toast de lenteur tant que le préchargement ne répond pas', async () => {
        await visiterPendantLeVol()
        await vi
            .waitUntil(() => annonces.length > 0, { timeout: DELAI_DU_RESEAU_LENT_MS + 500, interval: 5 })
            .catch(() => {})

        expect(messages()).toEqual([RESEAU_LENT])
        expect(annonces[0].annuler).toBeTypeOf('function')
    })

    it('« Annuler » l’arrête : l’échec du préchargement, ensuite, ne dit plus rien', async () => {
        await visiterPendantLeVol()
        await vi
            .waitUntil(() => annonces.length > 0, { timeout: DELAI_DU_RESEAU_LENT_MS + 500, interval: 5 })
            .catch(() => {})

        expect(messages()).toEqual([RESEAU_LENT])

        annonces[0].annuler()
        requetes[0].onerror?.()
        await attendre(DELAI_DU_RESEAU_LENT_MS + 100)

        expect(messages()).toEqual([RESEAU_LENT])
        expect(relais.rejets).toEqual([])
    })

    /*
     * Le témoin : le préchargement répond, la visite accrochée pose sa page
     * sans autre requête. Le filet cesse alors de la suivre : ni toast de
     * lenteur ni copie après coup.
     */
    it('pose la page quand le préchargement répond, sans rien annoncer après', async () => {
        const visite = await visiterPendantLeVol()

        requetes[0].repondre(reponseInertia(pageDe('/stats', 'Statistiques')))
        await vi.waitUntil(() => visite.reussie, { timeout: 1000, interval: 1 })
        await attendre(DELAI_DU_RESEAU_LENT_MS + 100)

        expect(contenu()).toBe('Statistiques')
        expect(chemins()).toEqual(['/stats'])
        expect(messages()).toEqual([])
    })

    /*
     * Une visite que le préchargement ne sert pas (d'autres en-têtes) part
     * avec sa propre requête : `start` la suit, et le filet ne la suit pas
     * une seconde fois comme accrochée, ce qui annoncerait sa lenteur après
     * sa fin.
     */
    it('ne suit qu’une fois une visite de la même adresse qui part avec sa propre requête', async () => {
        reseau.mode = 'muet'
        routeur.prefetch('/stats')
        await vi.waitUntil(() => requetes.length === 1, { interval: 1 })

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(pageDe('/stats', 'Statistiques'))
        let finie = false
        routeur.visit('/stats', { headers: { 'X-Autre': '1' }, onFinish: () => (finie = true) })
        await vi.waitUntil(() => finie, { timeout: 1000, interval: 1 })
        await attendre(DELAI_DU_RESEAU_LENT_MS + 100)

        expect(chemins()).toEqual(['/stats', '/stats'])
        expect(contenu()).toBe('Statistiques')
        expect(messages()).toEqual([])
    })

    it('ne suit pas une visite qu’un autre écouteur de `before` annule', async () => {
        reseau.mode = 'muet'
        routeur.prefetch('/stats')
        await vi.waitUntil(() => requetes.length === 1, { interval: 1 })

        const retirer = routeur.on('before', () => false)
        routeur.visit('/stats')
        retirer()
        await attendre(DELAI_DU_RESEAU_LENT_MS + 100)
        requetes[0].onerror()
        await uneTache()

        expect(chemins()).toEqual(['/stats'])
        expect(messages()).toEqual([])
    })
})

/*
 * La contre-épreuve du correctif : `router.flush(adresse)` ne retire que les
 * entrées déjà en cache, pas celles qui sont en vol. Le préchargement reste
 * ici en vol parce que le réseau ne répond pas, comme celui que le filet
 * laissait après son échec : seul `router.flushAll()` l'en retire. Si une
 * montée d'Inertia faisait de `flush` un vidage des entrées en vol aussi, ce
 * cas tomberait, et le filet pourrait ne vider que l'adresse en échec.
 */
describe('vider une seule adresse', () => {
    it('ne retire pas un préchargement en vol : seul le vidage complet le retire', async () => {
        reseau.mode = 'muet'
        routeur.prefetch('/stats')
        await vi.waitUntil(() => requetes.length === 1, { interval: 1 })

        expect(routeur.getPrefetching('/stats')).toBeTruthy()

        routeur.flush('/stats')

        expect(routeur.getPrefetching('/stats')).toBeTruthy()

        routeur.flushAll()

        expect(routeur.getPrefetching('/stats')).toBeFalsy()

        requetes[0].abort()
    })
})
