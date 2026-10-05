import { describe, it, expect, vi, beforeAll, beforeEach, afterAll } from 'vitest'
import { createApp, h } from 'vue'
import {
    VERSION_PERIMEE,
    MARQUE_DE_LA_VISITE_EN_ENTIER,
    inscrireLeWorker,
    nouvelleVersionPrete,
} from '@/Utils/miseAJourDuWorker'

/*
 * La visite qui suit une mise à jour, avec le vrai client d'Inertia (#1967).
 *
 * La page ouverte est une séance en cours. Une nouvelle version est prête, et
 * la personne touche un lien. Sans réseau, rien ne doit remplacer la séance :
 * la visite échoue comme une visite d'Inertia, et la page reste. Quand le
 * serveur répond, il répond 409 à la version périmée qu'annonce la visite, et
 * c'est Inertia qui fait la navigation complète. Le serveur, lui, est tenu par
 * tests/Feature/VersionPerimeeTest.php.
 *
 * jsdom ne sait ni joindre un serveur ni naviguer : `XMLHttpRequest` et
 * `window.location` sont remplacés, et chaque navigation complète se compte.
 */

/** Ce que le faux réseau fait de la prochaine requête. */
let reseau = { disponible: false }
/** Les requêtes parties, avec leurs en-têtes. */
const requetes = []

class RequeteSimulee {
    upload = {}
    status = 0
    responseText = ''
    entetesDeReponse = {}
    entetes = {}

    open(methode, url) {
        this.methode = methode
        this.url = url
    }

    setRequestHeader(nom, valeur) {
        this.entetes[nom] = valeur
    }

    getAllResponseHeaders() {
        return Object.entries(this.entetesDeReponse)
            .map(([nom, valeur]) => `${nom}: ${valeur}`)
            .join('\r\n')
    }

    abort() {
        this.onabort?.()
    }

    send() {
        requetes.push({ methode: this.methode, url: this.url, entetes: this.entetes })

        queueMicrotask(() => {
            if (!reseau.disponible) {
                this.onerror?.()

                return
            }

            this.status = reseau.statut
            this.entetesDeReponse = reseau.entetes
            this.responseText = reseau.corps ?? ''
            this.onload?.()
        })
    }
}

const depart = new URL('/workouts/12', window.location.href)
const navigations = []
const locationOrigine = Object.getOwnPropertyDescriptor(window, 'location')

/** Une `window.location` qui garde l'adresse de la séance et compte les navigations. */
const lieuSimule = {
    get href() {
        return depart.href
    },
    set href(valeur) {
        navigations.push(valeur)
    },
    origin: depart.origin,
    protocol: depart.protocol,
    host: depart.host,
    hostname: depart.hostname,
    port: depart.port,
    pathname: depart.pathname,
    search: depart.search,
    get hash() {
        return ''
    },
    toString: () => depart.href,
    assign: (valeur) => navigations.push(valeur),
    replace: (valeur) => navigations.push(valeur),
    reload: () => navigations.push('rechargement'),
}

let routeur
const echecsReseau = []

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    // jsdom ne fait pas défiler la page, et le dit à chaque visite.
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', depart.pathname)
    Object.defineProperty(window, 'location', { value: lieuSimule, configurable: true })

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router

    inscrireLeWorker({ registerSW: () => undefined, routeur, http: inertia.http })

    // L'échec est compté, et rendu silencieux : sans quoi Inertia le relève en promesse rejetée.
    routeur.on('networkError', (evenement) => {
        echecsReseau.push(evenement.detail.error)

        return false
    })

    const Seance = {
        props: ['minuteur'],
        render() {
            return h('p', { id: 'contenu' }, this.minuteur)
        },
    }

    await inertia.createInertiaApp({
        page: {
            component: 'Workouts/Show',
            url: depart.pathname,
            version: 'version-de-la-page',
            props: { errors: {}, minuteur: 'Repos : 0:42' },
        },
        resolve: () => Seance,
        setup: ({ el, App, props, plugin }) =>
            createApp({ render: () => h(App, props) })
                .use(plugin)
                .mount(el),
    })
})

beforeEach(() => {
    requetes.length = 0
    navigations.length = 0
    echecsReseau.length = 0
    nouvelleVersionPrete.value = true
})

afterAll(() => {
    Object.defineProperty(window, 'location', locationOrigine)
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    nouvelleVersionPrete.value = false
})

/**
 * Lance la visite et attend qu'elle ait fini, quelle qu'en soit l'issue, ou
 * qu'une navigation complète l'ait remplacée.
 */
const visiter = async (adresse, options = {}) => {
    let finie = false

    routeur.visit(adresse, { ...options, onFinish: () => (finie = true) })

    await vi.waitUntil(() => finie || navigations.length > 0)
}

describe('la visite qui suit une mise à jour', () => {
    it('sans réseau, échoue comme avant et laisse la séance à l’écran', async () => {
        reseau = { disponible: false }

        await visiter('/dashboard')

        expect(requetes).toHaveLength(1)
        expect(echecsReseau).toHaveLength(1)
        expect(navigations).toEqual([])
        expect(document.getElementById('contenu').textContent).toBe('Repos : 0:42')
    })

    it('quand le serveur répond, devient une navigation complète, faite par Inertia', async () => {
        reseau = {
            disponible: true,
            statut: 409,
            entetes: { 'x-inertia-location': new URL('/dashboard', depart).href },
        }

        await visiter('/dashboard')

        expect(requetes[0].entetes['X-Inertia-Version']).toBe(VERSION_PERIMEE)
        expect(requetes[0].entetes).not.toHaveProperty(MARQUE_DE_LA_VISITE_EN_ENTIER)
        expect(navigations).toEqual([new URL('/dashboard', depart).href])
    })

    it('qui garde l’état de la page, comme un filtre, part à la version de la page et reste sur place', async () => {
        reseau = {
            disponible: true,
            statut: 200,
            entetes: { 'x-inertia': 'true', 'content-type': 'application/json' },
            corps: JSON.stringify({
                component: 'Workouts/Show',
                url: '/workouts/12?vue=series',
                version: 'version-de-la-page',
                props: { errors: {}, minuteur: 'Repos : 0:41' },
            }),
        }

        await visiter('/workouts/12?vue=series', { preserveState: true })

        expect(requetes[0].entetes['X-Inertia-Version']).toBe('version-de-la-page')
        expect(navigations).toEqual([])
        expect(document.getElementById('contenu').textContent).toBe('Repos : 0:41')
    })
})
