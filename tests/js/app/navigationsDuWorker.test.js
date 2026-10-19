import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import { creerDesCachesEnMemoire } from '../utils/cachesEnMemoire'
import { EvenementDeRequete, EvenementProlongeable, RequeteDeNavigation } from '../utils/evenementsDuWorker'
import { EN_TETES_DE_LA_COQUILLE } from '@/sw/coquilleServie'
import {
    DELAI_DE_LA_NAVIGATION_MS,
    DUREE_SANS_DELAI_MS,
    MESSAGE_ATTENDRE_LA_REPONSE,
    MESSAGE_REPONSE_TARDIVE_RENVOYEE,
} from '@/sw/navigationsHorsLigne'
import { pageHorsLigne } from '@/sw/pageHorsLigne'
import { attendreLaReponse } from '@/Utils/messagesDuWorker'

/*
 * L'ouverture à froid, côté worker (#2048, partie F, décision 8 du
 * propriétaire) : huit secondes pour les pages de la salle, puis la page
 * « hors ligne » marquée de sa raison et enveloppée des en-têtes de sécurité ;
 * ni délai ni page « hors ligne » pour le rappel de la connexion sociale, le
 * panneau et les tableaux de bord ; la réponse arrivée après le délai, et
 * « Attendre la réponse ».
 *
 * Le vrai worker (`resources/js/sw.js`) et le vrai Workbox, chargés une fois
 * pour tout le fichier, sur des caches en mémoire (`cachesEnMemoire.js`) : la
 * page « hors ligne » est celle que la construction écrit, précachée par
 * l'installation. Le réseau est une réponse que chaque cas règle à son heure,
 * sous des minuteries simulées.
 */

const ORIGINE = self.location.origin

/** La page « hors ligne » de la construction. */
const PAGE_HORS_LIGNE = pageHorsLigne({
    feuilles: ['/build/assets/main-Un.css'],
    moduleDeLEntree: '/build/assets/main-Un.js',
})

/** Le serveur, pour l'installation : il ne sert que la page « hors ligne ». */
const fetchDeLInstallation = async (requete) =>
    new URL(typeof requete === 'string' ? requete : requete.url).pathname === '/build/hors-ligne.html'
        ? new Response(PAGE_HORS_LIGNE, { status: 200, headers: { 'Content-Type': 'text/html' } })
        : new Response('introuvable', { status: 404 })

/**
 * Une réponse du réseau que le cas règle quand il veut.
 *
 * @returns {{ promesse: Promise<Response>, repondre: (reponse: Response) => void, couper: () => void }}
 */
const reseauEnAttente = () => {
    let repondre
    let couper
    const promesse = new Promise((resoudre, rejeter) => {
        repondre = resoudre
        couper = () => rejeter(new TypeError('Failed to fetch'))
    })

    return { promesse, repondre, couper }
}

/** Les écouteurs du worker. */
let ecouteurs = {}

/** Les fenêtres que `clients.get` trouve, par identifiant. */
const fenetres = new Map()

/** Les clients du worker : la fenêtre qu'une navigation a ouverte se trouve par son identifiant. */
const clientsDuWorker = {
    claim: vi.fn(async () => undefined),
    matchAll: vi.fn(async () => []),
    get: vi.fn(async (identifiant) => fenetres.get(identifiant)),
}

/** Ce que le worker demande au réseau quand rien n'a été préchargé. */
const fetchDuWorker = vi.fn()

/**
 * Une navigation au worker : sa réponse, telle que le navigateur la recevrait.
 *
 * @param {string} chemin
 * @param {{ preloadResponse?: Promise<Response | undefined>, resultingClientId?: string }} [options]
 */
const naviguer = (chemin, options = {}) => {
    const evenement = new EvenementDeRequete(new RequeteDeNavigation(`${ORIGINE}${chemin}`), options)

    for (const ecouteur of ecouteurs.fetch ?? []) {
        ecouteur(evenement)
    }

    expect(evenement.reponses).toHaveLength(1)

    const reglee = { reponse: undefined }
    evenement.reponses[0].then((reponse) => {
        reglee.reponse = reponse
    })

    return {
        evenement,
        reglee,
        /** Les attentes du worker, toutes réglées. */
        attentes: () => Promise.allSettled(evenement.attentes),
    }
}

/**
 * Un message d'une page au worker, avec un port pour la réponse.
 *
 * @param {unknown} donnees
 */
const envoyerAuWorker = (donnees) => {
    const port = { postMessage: vi.fn() }
    const evenement = new EvenementProlongeable('message')
    evenement.data = donnees
    evenement.ports = [port]

    for (const ecouteur of ecouteurs.message ?? []) {
        ecouteur(evenement)
    }

    return port
}

/** Si une réponse est la page « hors ligne », et pour quelle raison. */
const raisonDeLaCoquille = async (reponse) => {
    const texte = await reponse.clone().text()

    if (!texte.includes('dusk="page-hors-ligne"')) {
        return null
    }

    return texte.match(/<html data-raison="(\w+)"/)?.[1] ?? 'sans raison'
}

beforeAll(async () => {
    vi.spyOn(self, 'addEventListener').mockImplementation((type, ecouteur) => {
        ;(ecouteurs[type] ??= []).push(ecouteur)
    })
    vi.stubGlobal('caches', creerDesCachesEnMemoire(`${ORIGINE}/sw.js`))
    vi.stubGlobal('fetch', fetchDeLInstallation)
    vi.stubGlobal('ExtendableEvent', EvenementProlongeable)
    vi.stubGlobal('FetchEvent', EvenementDeRequete)
    vi.stubGlobal('__WB_DISABLE_DEV_LOGS', true)
    vi.stubGlobal('registration', {
        scope: `${ORIGINE}/`,
        navigationPreload: { enable: vi.fn(async () => undefined) },
        pushManager: {},
        showNotification: vi.fn(),
    })
    vi.stubGlobal('clients', clientsDuWorker)
    vi.stubGlobal('skipWaiting', vi.fn())

    ecouteurs = {}
    self.__WB_MANIFEST = [{ url: '/build/hors-ligne.html', revision: 'Un' }]
    vi.resetModules()
    await import('@/sw.js')

    for (const type of ['install', 'activate']) {
        const evenement = new EvenementProlongeable(type)

        for (const ecouteur of ecouteurs[type] ?? []) {
            ecouteur(evenement)
        }

        await Promise.all(evenement.attentes)
    }

    vi.stubGlobal('fetch', fetchDuWorker)
})

/**
 * Chaque cas commence un jour après le précédent : un délai levé par un cas,
 * que le worker garde en mémoire, est passé pour le suivant.
 */
let jourDuCas = 0

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] })
    vi.setSystemTime(Date.UTC(2030, 0, 1) + jourDuCas++ * 86_400_000)
    fetchDuWorker.mockReset()
    fenetres.clear()
})

afterEach(() => {
    vi.useRealTimers()
})

afterAll(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    delete self.__WB_MANIFEST
})

describe('une page de la salle', () => {
    it.each(['/', '/dashboard', '/workouts/12', '/workouts', '/exercises', '/profile', '/tools', '/plates'])(
        'attend le réseau huit secondes, puis sert la page « hors ligne » marquée « delai » (%s)',
        async (chemin) => {
            const reseau = reseauEnAttente()
            const { reglee } = naviguer(chemin, { preloadResponse: reseau.promesse })

            await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS - 1)
            expect(reglee.reponse).toBeUndefined()

            await vi.advanceTimersByTimeAsync(1)
            expect(reglee.reponse).toBeDefined()
            await expect(raisonDeLaCoquille(reglee.reponse)).resolves.toBe('delai')
            reseau.couper()
        },
    )

    it('porte exactement les en-têtes de sécurité de l’application, et rien de la réponse précachée', async () => {
        const reseau = reseauEnAttente()
        const { reglee } = naviguer('/dashboard', { preloadResponse: reseau.promesse })

        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS)

        expect(Object.fromEntries(reglee.reponse.headers)).toEqual(
            Object.fromEntries(
                Object.entries({ 'Content-Type': 'text/html; charset=utf-8', ...EN_TETES_DE_LA_COQUILLE }).map(
                    ([nom, valeur]) => [nom.toLowerCase(), valeur],
                ),
            ),
        )
        expect(reglee.reponse.status).toBe(200)
        expect(EN_TETES_DE_LA_COQUILLE['Content-Security-Policy']).not.toMatch(/nonce-/)
        // Comme une page de compte : le cache de retour arrière ne la rend pas après la fin de la session.
        expect(reglee.reponse.headers.get('cache-control')).toBe('no-store, private')
        reseau.couper()
    })

    it('sert la page « hors ligne » marquée « reseau » sans attendre quand le réseau est coupé', async () => {
        const { reglee } = naviguer('/dashboard', { preloadResponse: Promise.reject(new TypeError('Failed to fetch')) })

        await vi.advanceTimersByTimeAsync(0)

        await expect(raisonDeLaCoquille(reglee.reponse)).resolves.toBe('reseau')
        expect(reglee.reponse.headers.get('content-security-policy')).toBe(
            EN_TETES_DE_LA_COQUILLE['Content-Security-Policy'],
        )
    })

    it('rend la réponse arrivée avant le délai, une erreur du serveur comprise', async () => {
        const reseau = reseauEnAttente()
        const { reglee } = naviguer('/dashboard', { preloadResponse: reseau.promesse })

        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS - 1)
        reseau.repondre(new Response('passerelle', { status: 502 }))
        await vi.advanceTimersByTimeAsync(0)

        expect(reglee.reponse.status).toBe(502)
        await expect(reglee.reponse.text()).resolves.toBe('passerelle')
        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS)
    })

    it('donne le même délai à la requête du worker quand rien n’a été préchargé', async () => {
        const reseau = reseauEnAttente()
        fetchDuWorker.mockReturnValue(reseau.promesse)
        const { reglee } = naviguer('/workouts/12')

        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS)

        expect(fetchDuWorker).toHaveBeenCalledTimes(1)
        await expect(raisonDeLaCoquille(reglee.reponse)).resolves.toBe('delai')
        reseau.couper()
    })

    it('n’attend pas une adresse de la salle qui porte un filtre ou une autre page', async () => {
        const reseau = reseauEnAttente()
        const { reglee } = naviguer('/workouts?page=2', { preloadResponse: reseau.promesse })

        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS * 3)
        expect(reglee.reponse).toBeUndefined()

        reseau.repondre(new Response('la page 2'))
        await vi.advanceTimersByTimeAsync(0)
        await expect(reglee.reponse.text()).resolves.toBe('la page 2')
    })
})

describe('la réponse arrivée après le délai', () => {
    /**
     * La page « hors ligne » servie au délai, puis la réponse du réseau.
     *
     * @param {Response} tardive
     */
    const unePageServieAuDelaiPuis = async (tardive, chemin = '/dashboard') => {
        const fenetre = { postMessage: vi.fn() }
        fenetres.set('fenetre-ouverte', fenetre)
        const reseau = reseauEnAttente()
        const navigation = naviguer(chemin, { preloadResponse: reseau.promesse, resultingClientId: 'fenetre-ouverte' })

        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS)
        await expect(raisonDeLaCoquille(navigation.reglee.reponse)).resolves.toBe('delai')

        await vi.advanceTimersByTimeAsync(20_000)
        reseau.repondre(tardive)
        await navigation.attentes()

        return fenetre
    }

    it.each([
        ['une redirection, telle qu’une navigation la reçoit', () => opaqueRedirect()],
        ['une redirection', () => new Response(null, { status: 302, headers: { Location: '/login' } })],
        ['un 401', () => new Response('', { status: 401 })],
    ])('annonce %s à la page que la navigation a ouverte, et à elle seule', async (_, tardive) => {
        const autre = { postMessage: vi.fn() }
        fenetres.set('autre-onglet', autre)

        const fenetre = await unePageServieAuDelaiPuis(tardive())

        expect(fenetre.postMessage).toHaveBeenCalledExactlyOnceWith({ type: MESSAGE_REPONSE_TARDIVE_RENVOYEE })
        expect(autre.postMessage).not.toHaveBeenCalled()
    })

    it('n’annonce pas une page servie', async () => {
        const fenetre = await unePageServieAuDelaiPuis(new Response('<!DOCTYPE html><title>Accueil</title>'))

        expect(fenetre.postMessage).not.toHaveBeenCalled()
    })

    it('n’annonce rien d’une réponse arrivée à temps, même redirigée', async () => {
        const fenetre = { postMessage: vi.fn() }
        fenetres.set('fenetre-ouverte', fenetre)
        const navigation = naviguer('/', {
            preloadResponse: Promise.resolve(new Response(null, { status: 302, headers: { Location: '/dashboard' } })),
            resultingClientId: 'fenetre-ouverte',
        })

        await navigation.attentes()
        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS)

        expect(navigation.reglee.reponse.status).toBe(302)
        expect(fenetre.postMessage).not.toHaveBeenCalled()
    })

    it('ne cherche aucune page quand le navigateur n’a pas dit laquelle la navigation a ouverte', async () => {
        clientsDuWorker.get.mockClear()
        const reseau = reseauEnAttente()
        const navigation = naviguer('/dashboard', { preloadResponse: reseau.promesse })

        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS)
        reseau.repondre(new Response('', { status: 401 }))
        await navigation.attentes()

        expect(clientsDuWorker.get).not.toHaveBeenCalled()
    })

    it('n’échoue pas quand la page ouverte n’est plus là', async () => {
        const reseau = reseauEnAttente()
        const navigation = naviguer('/dashboard', { preloadResponse: reseau.promesse, resultingClientId: 'partie' })

        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS)
        reseau.repondre(new Response('', { status: 401 }))
        const attentes = await navigation.attentes()

        expect(attentes.every((attente) => attente.status === 'fulfilled')).toBe(true)
        expect(clientsDuWorker.get).toHaveBeenCalledWith('partie')
    })
})

describe('« Attendre la réponse »', () => {
    it('lève le délai de l’adresse pendant une minute, puis le remet', async () => {
        const port = envoyerAuWorker({ type: MESSAGE_ATTENDRE_LA_REPONSE, adresse: `${ORIGINE}/dashboard` })

        expect(port.postMessage).toHaveBeenCalledExactlyOnceWith({ type: MESSAGE_ATTENDRE_LA_REPONSE, levee: true })

        const lent = reseauEnAttente()
        const sansDelai = naviguer('/dashboard', { preloadResponse: lent.promesse })
        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS * 5)
        expect(sansDelai.reglee.reponse).toBeUndefined()
        lent.repondre(new Response('l’accueil, enfin'))
        await vi.advanceTimersByTimeAsync(0)
        await expect(sansDelai.reglee.reponse.text()).resolves.toBe('l’accueil, enfin')

        // Une autre adresse de la salle garde son délai.
        const ailleurs = reseauEnAttente()
        const seance = naviguer('/workouts/12', { preloadResponse: ailleurs.promesse })
        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS)
        await expect(raisonDeLaCoquille(seance.reglee.reponse)).resolves.toBe('delai')
        ailleurs.couper()

        await vi.advanceTimersByTimeAsync(DUREE_SANS_DELAI_MS)
        const plusTard = reseauEnAttente()
        const denouveau = naviguer('/dashboard', { preloadResponse: plusTard.promesse })
        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS)
        await expect(raisonDeLaCoquille(denouveau.reglee.reponse)).resolves.toBe('delai')
        plusTard.couper()
    })

    it('sert encore la page « hors ligne », marquée « reseau », si le réseau tombe pendant l’attente', async () => {
        envoyerAuWorker({ type: MESSAGE_ATTENDRE_LA_REPONSE, adresse: `${ORIGINE}/tools` })

        const reseau = reseauEnAttente()
        const { reglee } = naviguer('/tools', { preloadResponse: reseau.promesse })
        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS * 2)
        reseau.couper()
        await vi.advanceTimersByTimeAsync(0)

        await expect(raisonDeLaCoquille(reglee.reponse)).resolves.toBe('reseau')
        await vi.advanceTimersByTimeAsync(DUREE_SANS_DELAI_MS)
    })

    it.each([
        ['une adresse hors de la salle', `${ORIGINE}/stats`],
        ['une adresse d’une autre origine', 'https://ailleurs.example/dashboard'],
        ['le rappel de la connexion sociale', `${ORIGINE}/auth/google/callback`],
        ['une adresse qui n’en est pas une', 42],
        ['une adresse qui ne se lit pas', 'http://['],
    ])('ne lève rien pour %s', async (_, adresse) => {
        const port = envoyerAuWorker({ type: MESSAGE_ATTENDRE_LA_REPONSE, adresse })

        expect(port.postMessage).toHaveBeenCalledExactlyOnceWith({ type: MESSAGE_ATTENDRE_LA_REPONSE, levee: false })
    })

    it('ne garde que les seize dernières adresses levées', async () => {
        const seances = Array.from({ length: 17 }, (_, rang) => `/workouts/${rang + 100}`)

        for (const seance of seances) {
            envoyerAuWorker({ type: MESSAGE_ATTENDRE_LA_REPONSE, adresse: `${ORIGINE}${seance}` })
        }

        const premiere = reseauEnAttente()
        const oubliee = naviguer(seances[0], { preloadResponse: premiere.promesse })
        const derniere = reseauEnAttente()
        const gardee = naviguer(seances[16], { preloadResponse: derniere.promesse })
        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS)

        await expect(raisonDeLaCoquille(oubliee.reglee.reponse)).resolves.toBe('delai')
        expect(gardee.reglee.reponse).toBeUndefined()
        premiere.couper()
        derniere.couper()
        await vi.advanceTimersByTimeAsync(0)
    })

    it('ne répond pas à un autre message', () => {
        expect(envoyerAuWorker({ type: 'autre chose' }).postMessage).not.toHaveBeenCalled()
        expect(envoyerAuWorker(null).postMessage).not.toHaveBeenCalled()
    })

    it('se demande depuis la page, qui recharge une fois le délai levé', async () => {
        vi.useRealTimers()
        const navigations = []
        const conteneur = {
            controller: {
                postMessage: (donnees, ports) => {
                    const evenement = new EvenementProlongeable('message')
                    evenement.data = donnees
                    evenement.ports = ports

                    for (const ecouteur of ecouteurs.message ?? []) {
                        ecouteur(evenement)
                    }
                },
            },
        }

        await attendreLaReponse({
            conteneur,
            adresse: `${ORIGINE}/exercises`,
            recharger: () => navigations.push('/exercises'),
        })

        expect(navigations).toEqual(['/exercises'])

        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] })
        const lent = reseauEnAttente()
        const rechargee = naviguer('/exercises', { preloadResponse: lent.promesse })
        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS * 2)
        expect(rechargee.reglee.reponse).toBeUndefined()
        lent.repondre(new Response('la bibliothèque'))
        await vi.advanceTimersByTimeAsync(0)
        await expect(rechargee.reglee.reponse.text()).resolves.toBe('la bibliothèque')
        await vi.advanceTimersByTimeAsync(DUREE_SANS_DELAI_MS)
    })
})

/*
 * Ni délai ni page « hors ligne » : coupé à huit secondes, le rappel d'un
 * fournisseur de connexion poserait la session sur une réponse que personne
 * ne lit ; le panneau et les tableaux de bord n'ont rien à faire de la page de
 * l'application. Le rapprochement avec les vraies routes vit dans
 * `ServiceWorkerAssetsTest`.
 */
describe('une navigation qui ne reçoit jamais la page « hors ligne »', () => {
    const chemins = [
        '/auth/google/callback?code=abc&state=def',
        '/auth/github/redirect',
        '/%61uth/google/callback',
        '/backoffice',
        '/backoffice/pulse',
        '/backoffice/journaux',
        '/horizon',
        '/horizon/dashboard',
        '/telescope/requests',
        '/verify-email/7/empreinte',
        '/reset-password/jeton',
        '/inscription/ouvrir/7/empreinte?expires=1&signature=abc',
        '/adresse/confirmer?compte=7&adresse=x&expires=1&signature=abc',
        '/_dusk/login/7',
    ]

    it.each(chemins)('attend %s sans délai', async (chemin) => {
        const reseau = reseauEnAttente()
        const { reglee } = naviguer(chemin, { preloadResponse: reseau.promesse })

        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS * 4)
        expect(reglee.reponse).toBeUndefined()

        reseau.repondre(new Response('la réponse du serveur'))
        await vi.advanceTimersByTimeAsync(0)
        await expect(reglee.reponse.text()).resolves.toBe('la réponse du serveur')
    })

    it.each(chemins)('laisse à %s l’erreur du navigateur sans réseau', async (chemin) => {
        const { reglee } = naviguer(chemin, { preloadResponse: Promise.reject(new TypeError('Failed to fetch')) })

        await vi.advanceTimersByTimeAsync(0)

        expect(reglee.reponse.type).toBe('error')
    })

    it('rend la réponse préchargée, sans seconde requête', async () => {
        const { reglee } = naviguer('/auth/google/callback?code=abc', {
            preloadResponse: Promise.resolve(new Response('connecté')),
        })

        await vi.advanceTimersByTimeAsync(0)

        await expect(reglee.reponse.text()).resolves.toBe('connecté')
        expect(fetchDuWorker).not.toHaveBeenCalled()
    })
})

describe('une autre page de l’application', () => {
    it('attend le réseau sans délai', async () => {
        const reseau = reseauEnAttente()
        const { reglee } = naviguer('/stats', { preloadResponse: reseau.promesse })

        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS * 4)
        expect(reglee.reponse).toBeUndefined()

        reseau.repondre(new Response('les statistiques'))
        await vi.advanceTimersByTimeAsync(0)
        await expect(reglee.reponse.text()).resolves.toBe('les statistiques')
    })

    it('reçoit la page « hors ligne » marquée « reseau » sans réseau', async () => {
        const { reglee } = naviguer('/journal', { preloadResponse: Promise.reject(new TypeError('Failed to fetch')) })

        await vi.advanceTimersByTimeAsync(0)

        await expect(raisonDeLaCoquille(reglee.reponse)).resolves.toBe('reseau')
    })
})

/**
 * Une redirection telle que la reçoit une navigation, qui ne les suit pas :
 * une réponse opaque, de statut 0. `new Response` ne sait pas la faire.
 */
const opaqueRedirect = () => {
    const reponse = new Response(null, { status: 200 })
    Object.defineProperty(reponse, 'type', { value: 'opaqueredirect' })
    Object.defineProperty(reponse, 'status', { value: 0 })

    return reponse
}
