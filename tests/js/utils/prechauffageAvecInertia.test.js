import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'

import { jsRoot } from '../conventions/sourceFiles'

import { creerUneFabriqueEnMemoire } from './baseIndexeeEnMemoire'
import { RequeteSimulee, relayerLesRejetsNonGeres, reponseInertia, requetes, reseau, uneTache } from './reseauSimule'
import {
    MAGASIN_DES_PAGES,
    MAGASIN_DE_L_ETAT,
    NOM_DE_LA_BASE,
    TEMOIN,
    creerLeMagasinDesCopies,
    ouvrirLaBaseDuNavigateur,
} from '@/Utils/copiesHorsLigne'
import { installerLEcritureDesCopies } from '@/Utils/ecritureDesCopies'
import { installerLaGardeDeLHistorique } from '@/Utils/historiqueDuCompte'
import {
    CLEF_DU_DERNIER_PRECHAUFFAGE,
    GET_AU_PLUS,
    INTERVALLE_DU_PRECHAUFFAGE_MS,
    installerLePrechauffage,
} from '@/Utils/prechauffage'

/*
 * Le préchauffage des copies hors ligne (#2048, partie D, décision 7 du
 * propriétaire), avec le vrai client d'Inertia : il part sur `success`, après
 * une vraie réponse, et sur rien d'autre. Il demande au serveur, par `fetch`,
 * huit pages au plus, l'une après l'autre, et les passe à l'écriture des
 * copies, sur une base en mémoire.
 *
 * Le client se monte une fois pour le fichier ; chaque cas repart d'un
 * magasin vide, d'un stockage vide et d'une page remise par une visite du
 * client seul (#2044).
 */

// Le détachement de l'appareil a ses propres tests : la déconnexion n'en a besoin que pour partir.
vi.mock('@/composables/useAbonnementPush', () => ({
    detacherLAppareil: () => Promise.resolve(),
    marquerLAbonnementARetransmettre: () => {},
    rapprocherLAbonnementPush: () => Promise.resolve(true),
}))

const COMPTE = 7

/** Une clé de 32 octets tirés au hasard, en base64url, comme le serveur la tire. */
const cleAuHasard = () =>
    btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '')

/** Le composant que le serveur rend à chaque adresse gardée à adresse fixe. */
const COMPOSANTS = {
    '/dashboard': 'Dashboard',
    '/workouts': 'Workouts/Index',
    '/exercises': 'Exercises/Index',
    '/profile': 'Profile/Index',
    '/tools': 'Tools/Index',
    '/tools/interval-timer': 'Tools/IntervalTimer',
    '/plates': 'Tools/PlateCalculator',
    '/tools/1rm': 'Tools/OneRepMax',
    '/tools/warmup': 'Tools/WarmupCalculator',
}

/** Les props différées que le serveur annonce, par composant (le contrat des pages gardées). */
const DIFFEREES = {
    Dashboard: { default: ['analyticalStats'] },
    'Workouts/Index': { default: ['deferredData'] },
}

/**
 * Une page du compte, telle que le serveur la rend en entier.
 *
 * @param {string} url
 * @param {{ seanceEnCours?: number | null, props?: object }} [options]
 */
const pageDe = (url, { seanceEnCours = null, props = {} } = {}) => {
    const component = /^\/workouts\/\d+$/.test(url) ? 'Workouts/Show' : COMPOSANTS[url]

    return {
        component,
        url,
        version: 'v1',
        encryptHistory: true,
        clearHistory: false,
        props: {
            errors: {},
            auth: {
                user: {
                    id: COMPTE,
                    name: 'Camille Horsligne',
                    active_workout: seanceEnCours === null ? null : { id: seanceEnCours },
                },
            },
            ...(component === 'Workouts/Show'
                ? { workout: { id: Number(url.split('/').at(-1)), ended_at: null } }
                : {}),
            ...props,
        },
        ...(DIFFEREES[component] === undefined ? {} : { deferredProps: DIFFEREES[component] }),
    }
}

/**
 * Le serveur que le préchauffage atteint par `fetch` : chaque demande se
 * retient, et répond par la règle de son chemin, ou par la page de ce chemin.
 */
const serveur = {
    /** @type {Array<{ chemin: string, options: RequestInit, repondre: (reponse: object) => void, enVol: boolean }>} */
    appels: [],
    /** @type {Map<string, (appel: object) => object | 'muet' | 'coupe'>} */
    regles: new Map(),
    seanceEnCours: null,
    enVol: 0,
    enVolAuPlus: 0,
}

/** Une réponse d'Inertia, telle que `fetch` la rend. */
const reponseDePage = (page, statut = 200) =>
    new Response(JSON.stringify(page), {
        status: statut,
        headers: { 'x-inertia': 'true', 'content-type': 'application/json' },
    })

/** Une réponse sans corps, d'un statut. */
const reponseVide = (statut) => new Response(null, { status: statut })

/** Ce que `fetch` rend d'une redirection sous `redirect: 'manual'`. */
const redirectionOpaque = () => ({ type: 'opaqueredirect', status: 0, ok: false, headers: new Headers() })

/** La page que le serveur rend à une demande, partielle ou non. */
const pageDuServeur = (appel) => {
    const page = pageDe(appel.chemin, { seanceEnCours: serveur.seanceEnCours })
    const partielles = appel.options.headers['X-Inertia-Partial-Data']

    if (partielles === undefined) {
        return reponseDePage(page)
    }

    return reponseDePage({
        ...page,
        deferredProps: undefined,
        props: Object.fromEntries(partielles.split(',').map((nom) => [nom, { venuDuPartiel: nom }])),
    })
}

/** @type {typeof fetch} */
const demander = (chemin, options) =>
    new Promise((resoudre, rejeter) => {
        const appel = { chemin, options, enVol: true, repondre: resoudre }

        serveur.appels.push(appel)
        serveur.enVol += 1
        serveur.enVolAuPlus = Math.max(serveur.enVolAuPlus, serveur.enVol)
        options.signal.addEventListener('abort', () => rejeter(new DOMException('aborted', 'AbortError')))

        const regle = (serveur.regles.get(chemin) ?? pageDuServeur)(appel)

        if (regle === 'coupe') {
            queueMicrotask(() => rejeter(new TypeError('Failed to fetch')))
        } else if (regle !== 'muet') {
            queueMicrotask(() => resoudre(regle))
        }
    }).finally(() => {
        serveur.enVol -= 1
    })

let maintenant = Date.parse('2026-10-12T18:04:00Z')
let routeur
let fabrique
let magasin
let ecriture
let prechauffage
let application
let relais

const copiesRangees = () => fabrique.enregistrements(NOM_DE_LA_BASE, MAGASIN_DES_PAGES)
const cheminsRanges = () => [...copiesRangees().keys()].sort()
const etatRange = () => fabrique.enregistrements(NOM_DE_LA_BASE, MAGASIN_DE_L_ETAT).get('etat')
const cheminsDemandes = () => serveur.appels.map((appel) => appel.chemin)

/** Tout ce que le préchauffage a fait partir est revenu, et écrit. */
const prechauffageFini = async () => {
    await prechauffage.fini()
    await ecriture.ecrituresFinies()
}

/**
 * La réponse d'Inertia à une visite de la page, sans ses props différées :
 * le vrai client les demanderait aussitôt, et le réseau simulé lui rendrait
 * la même page, qui les annonce encore, sans fin.
 *
 * @param {object} page
 */
const reponseDeVisite = (page) => reponseInertia({ ...page, deferredProps: undefined })

/** Une visite à laquelle le serveur répond par `page`, jusqu'à la fin du préchauffage qu'elle fait partir. */
const recevoir = async (page) => {
    let finie = false

    reseau.mode = 'repond'
    reseau.reponse = reponseDeVisite(page)
    routeur.visit(page.url, { onFinish: () => (finie = true) })
    await vi.waitUntil(() => finie)
    await uneTache()
    await ecriture.ecrituresFinies()
    await prechauffageFini()
}

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    globalThis.route = (nom) => ({ logout: '/logout' })[nom]
    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', '/tools')

    fabrique = creerUneFabriqueEnMemoire()
    magasin = creerLeMagasinDesCopies({
        ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique),
        horloge: () => maintenant,
    })

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router

    // Comme main.js : la garde de l'historique, l'écriture des copies, puis le préchauffage, avant Inertia.
    installerLaGardeDeLHistorique({
        routeur,
        pageInitiale: null,
        surUnAutreTitulaire: () => magasin.oublierLesCopiesDUnAutreTitulaire(),
    })
    ecriture = installerLEcritureDesCopies({ routeur, magasin, pageInitiale: null })
    prechauffage = installerLePrechauffage({
        routeur,
        ecriture,
        magasin,
        pageInitiale: null,
        demander,
        horloge: () => maintenant,
    })

    await inertia.createInertiaApp({
        page: { ...pageDe('/tools'), props: { ...pageDe('/tools').props } },
        resolve: () => ({ render: () => h('p', { id: 'contenu' }, 'Page') }),
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) }).use(plugin)
            application.mount(el)
        },
    })
})

beforeEach(async () => {
    // Une visite du client seul, sans requête et sans `success` : ni copie ni préchauffage n'en viennent.
    await new Promise((fini) => routeur.replace({ ...pageDe('/tools'), onFinish: fini }))
    await prechauffageFini()

    copiesRangees().clear()
    fabrique.enregistrements(NOM_DE_LA_BASE, MAGASIN_DE_L_ETAT).clear()
    localStorage.clear()
    requetes.length = 0
    reseau.mode = 'coupe'
    reseau.prochains = []
    reseau.reponse = null
    serveur.appels = []
    serveur.regles = new Map()
    serveur.seanceEnCours = null
    serveur.enVol = 0
    serveur.enVolAuPlus = 0
    maintenant += 24 * 60 * 60 * 1000
    document.cookie = `${TEMOIN}=${cleAuHasard()}; path=/`
    relais = relayerLesRejetsNonGeres()
})

afterEach(async () => {
    prechauffage.interrompre()
    await prechauffageFini()
    relais.retirer()
})

afterAll(async () => {
    application.unmount()
    prechauffage.desinstaller()
    ecriture.desinstaller()
    await magasin.fermer()
    delete globalThis.route
    document.cookie = `${TEMOIN}=; path=/; max-age=0`
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('le préchauffage', () => {
    it('part après une vraie réponse, et demande l’une après l’autre, avec les en-têtes d’Inertia, les pages du noyau qui manquent', async () => {
        serveur.seanceEnCours = 5

        await recevoir(pageDe('/dashboard', { seanceEnCours: 5 }))

        // La séance en cours d'abord, puis la liste blanche, sans la page qui vient d'arriver ; huit au plus.
        expect(cheminsDemandes()).toEqual([
            '/workouts/5',
            '/workouts',
            '/exercises',
            '/profile',
            '/tools',
            '/tools/interval-timer',
            '/plates',
            '/tools/1rm',
        ])
        expect(serveur.appels).toHaveLength(GET_AU_PLUS)
        expect(serveur.enVolAuPlus).toBe(1)

        for (const { options } of serveur.appels) {
            expect(options).toMatchObject({
                method: 'GET',
                headers: {
                    Accept: 'text/html, application/xhtml+xml',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Inertia': 'true',
                    'X-Inertia-Version': 'v1',
                },
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'manual',
            })
            expect(Object.keys(options.headers).sort()).toEqual([
                'Accept',
                'X-Inertia',
                'X-Inertia-Version',
                'X-Requested-With',
            ])
            expect(options.signal).toBeInstanceOf(AbortSignal)
        }

        // Chaque page reçue est gardée, et la séance en cours y est.
        expect(cheminsRanges()).toEqual([
            '/dashboard',
            '/exercises',
            '/plates',
            '/profile',
            '/tools',
            '/tools/1rm',
            '/tools/interval-timer',
            '/workouts',
            '/workouts/5',
        ])
        expect((await magasin.lireUneCopie('/workouts/5')).page.props.workout).toEqual({ id: 5, ended_at: null })
        expect(relais.rejets).toEqual([])
    })

    it('demande en partiel la prop différée de l’accueil, et jamais celle de la liste des séances', async () => {
        await recevoir(pageDe('/tools'))

        expect(cheminsDemandes().slice(0, 3)).toEqual(['/dashboard', '/dashboard', '/workouts'])
        expect(serveur.appels[1].options.headers).toMatchObject({
            'X-Inertia-Partial-Component': 'Dashboard',
            'X-Inertia-Partial-Data': 'analyticalStats',
        })
        expect(serveur.appels.filter((appel) => appel.chemin === '/workouts')).toHaveLength(1)
        expect(serveur.appels.some((appel) => appel.options.headers['X-Inertia-Partial-Data'] === 'deferredData')).toBe(
            false,
        )
        expect(serveur.appels).toHaveLength(GET_AU_PLUS)

        const accueil = await magasin.lireUneCopie('/dashboard')

        expect(accueil.page.props.analyticalStats).toEqual({ venuDuPartiel: 'analyticalStats' })
        expect(accueil.page.props.auth.user.id).toBe(COMPTE)
        expect(accueil.datesDesProps.analyticalStats).toBe(maintenant)
    })

    it('part au plus une fois par trente minutes sous la même clé de session, et repart sous une autre', async () => {
        await recevoir(pageDe('/tools'))
        const premiers = cheminsDemandes()

        // Il manque encore l'échauffement, mais la demi-heure n'est pas passée.
        maintenant += INTERVALLE_DU_PRECHAUFFAGE_MS - 1
        await recevoir(pageDe('/profile'))

        expect(cheminsDemandes()).toEqual(premiers)
        expect(JSON.parse(localStorage.getItem(CLEF_DU_DERNIER_PRECHAUFFAGE))).toMatchObject({
            a: maintenant - INTERVALLE_DU_PRECHAUFFAGE_MS + 1,
        })

        // Passée, il ne demande que ce qui manque encore.
        maintenant += 1
        await recevoir(pageDe('/profile'))

        expect(cheminsDemandes().slice(premiers.length)).toEqual(['/tools/warmup'])

        // Une nouvelle session : une autre clé, un magasin qui repart de zéro.
        document.cookie = `${TEMOIN}=${cleAuHasard()}; path=/`
        maintenant += 1000
        await recevoir(pageDe('/tools'))

        expect(cheminsDemandes().length).toBeGreaterThan(premiers.length + 1)
    })

    it('refait aussitôt les copies qu’une suppression du compte refusée a oubliées, sans attendre la demi-heure', async () => {
        await recevoir(pageDe('/tools'))
        const premiers = cheminsDemandes().length

        expect(cheminsRanges().length).toBeGreaterThan(5)

        // Comme DeleteUserForm : l'oubli avant l'envoi, la clé fermée, puis rouverte au refus du mot de passe.
        const empreinte = magasin.empreinteDuTemoin()
        const purge = magasin.oublierLesCopies({ fermerLeTemoin: true })
        const generation = magasin.generationCourante()

        await purge
        expect(await magasin.rouvrirLaCle({ empreinte, compte: COMPTE, generation })).toBe(true)
        expect(cheminsRanges()).toEqual([])

        maintenant += 60 * 1000
        await recevoir(pageDe('/profile'))

        expect(cheminsDemandes().length).toBeGreaterThan(premiers)
        expect(cheminsRanges()).toEqual(expect.arrayContaining(['/dashboard', '/profile', '/tools', '/workouts']))
    })

    it('ne demande rien quand tout le noyau est là et récent, et redemande une copie de plus de deux heures', async () => {
        await recevoir(pageDe('/tools'))
        maintenant += INTERVALLE_DU_PRECHAUFFAGE_MS
        await recevoir(pageDe('/tools'))
        const toutes = cheminsDemandes().length

        maintenant += INTERVALLE_DU_PRECHAUFFAGE_MS
        await recevoir(pageDe('/tools'))

        expect(cheminsDemandes()).toHaveLength(toutes)
        expect(localStorage.getItem(CLEF_DU_DERNIER_PRECHAUFFAGE)).toContain(
            String(maintenant - INTERVALLE_DU_PRECHAUFFAGE_MS),
        )

        // Deux heures et une milliseconde après les premières copies.
        maintenant += 2 * 60 * 60 * 1000 - 2 * INTERVALLE_DU_PRECHAUFFAGE_MS + 1
        await recevoir(pageDe('/tools'))

        expect(cheminsDemandes().slice(toutes, toutes + 2)).toEqual(['/dashboard', '/dashboard'])
    })

    it('redemande la séance en cours dont une écriture a été rejouée par la file depuis sa copie', async () => {
        serveur.seanceEnCours = 5
        await recevoir(pageDe('/tools', { seanceEnCours: 5 }))
        maintenant += INTERVALLE_DU_PRECHAUFFAGE_MS
        await recevoir(pageDe('/tools', { seanceEnCours: 5 }))
        const avant = cheminsDemandes().length

        maintenant += INTERVALLE_DU_PRECHAUFFAGE_MS
        window.dispatchEvent(new CustomEvent('sync:replayed', { detail: { queueId: 'q1', url: '/api/v1/sets/3' } }))
        await vi.waitUntil(() => etatRange().dernierRejeu === maintenant)
        maintenant += 1
        await recevoir(pageDe('/tools', { seanceEnCours: 5 }))

        expect(cheminsDemandes().slice(avant)).toEqual(['/workouts/5'])
    })

    it('s’arrête sur un 409, sans réseau, ou sur une réponse qui ne vient pas, au bout de son délai', async () => {
        serveur.regles.set('/dashboard', () => reponseVide(409))
        await recevoir(pageDe('/tools'))

        expect(cheminsDemandes()).toEqual(['/dashboard'])
        expect(cheminsRanges()).toEqual(['/tools'])

        localStorage.clear()
        serveur.regles.set('/dashboard', () => 'coupe')
        await recevoir(pageDe('/tools'))

        expect(cheminsDemandes()).toEqual(['/dashboard', '/dashboard'])
    })

    it.each([
        ['une redirection', redirectionOpaque],
        ['un 401', () => reponseVide(401)],
    ])('oublie les copies et ferme la clé du témoin sur %s, la fin de la session', async (_cas, reponse) => {
        let empreinte = null
        serveur.regles.set('/workouts', () => {
            empreinte = etatRange().empreinte

            return reponse()
        })

        await recevoir(pageDe('/tools'))

        // Les copies écrites avant aussi, la page d'où il est parti comprise : le magasin entier.
        expect(empreinte).not.toBeNull()
        expect(cheminsDemandes()).toEqual(['/dashboard', '/dashboard', '/workouts'])
        expect(copiesRangees().size).toBe(0)
        expect(etatRange()).toMatchObject({ ouvert: false })
        expect(etatRange().empreintesFermees).toContain(empreinte)

        // La clé fermée, plus rien ne part ni ne s'écrit sous elle.
        maintenant += INTERVALLE_DU_PRECHAUFFAGE_MS
        await recevoir(pageDe('/profile'))

        expect(cheminsDemandes()).toHaveLength(3)
        expect(copiesRangees().size).toBe(0)
    })

    it('ne garde pas une réponse qui n’est pas la page demandée, ni une erreur du serveur, et continue', async () => {
        serveur.seanceEnCours = 5
        // La séance en cours répond par une autre séance, l'accueil par une autre page gardée.
        serveur.regles.set('/workouts/5', () => reponseDePage(pageDe('/workouts/6', { seanceEnCours: 5 })))
        serveur.regles.set('/dashboard', () => reponseDePage(pageDe('/plates', { props: { pasLaSienne: true } })))
        serveur.regles.set('/plates', () => reponseVide(500))
        serveur.regles.set('/workouts', () => reponseVide(500))
        serveur.regles.set('/exercises', () => new Response('<html></html>', { status: 200 }))

        await recevoir(pageDe('/tools', { seanceEnCours: 5 }))

        expect(cheminsDemandes().slice(0, 5)).toEqual([
            '/workouts/5',
            '/dashboard',
            '/workouts',
            '/exercises',
            '/profile',
        ])
        expect(cheminsRanges()).toEqual(['/profile', '/tools', '/tools/1rm', '/tools/interval-timer'])
    })

    it('ne redemande pas la page qui vient d’arriver, même quand sa copie n’a pas pu s’écrire', async () => {
        // Plus grande que le plafond des copies : elle ne se garde pas.
        await recevoir(pageDe('/dashboard', { props: { enorme: 'x'.repeat(1_600_000) } }))

        expect(cheminsRanges()).not.toContain('/dashboard')
        expect(cheminsDemandes()).not.toContain('/dashboard')
        expect(cheminsDemandes()[0]).toBe('/workouts')
    })

    it('ne part ni d’une visite côté client, ni d’une page rendue par l’historique, ni d’une page cachée', async () => {
        await new Promise((fini) => routeur.push({ ...pageDe('/profile'), onFinish: fini }))
        await new Promise((fini) => routeur.push({ ...pageDe('/exercises'), onFinish: fini }))
        const retour = new Promise((fini) => window.addEventListener('popstate', fini, { once: true }))
        window.history.back()
        await retour
        await uneTache()
        await prechauffageFini()

        expect(serveur.appels).toEqual([])

        const visibilite = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
        await recevoir(pageDe('/tools'))
        visibilite.mockRestore()

        expect(serveur.appels).toEqual([])
        expect(cheminsRanges()).toEqual(['/tools'])
    })

    it('une purge l’interrompt : ce qui revient après ne s’écrit pas', async () => {
        serveur.regles.set('/workouts', () => 'muet')

        let finie = false
        reseau.mode = 'repond'
        reseau.reponse = reponseDeVisite(pageDe('/tools'))
        routeur.visit('/tools', { onFinish: () => (finie = true) })
        await vi.waitUntil(() => finie)
        await vi.waitUntil(() => serveur.appels.some((appel) => appel.chemin === '/workouts'))

        await magasin.oublierLesCopies()

        expect(serveur.appels.at(-1).options.signal.aborted).toBe(true)

        await prechauffageFini()

        expect(cheminsDemandes().at(-1)).toBe('/workouts')
        expect(copiesRangees().size).toBe(0)
    })

    it('la déconnexion l’interrompt avant toute autre chose, avant même la requête de déconnexion', async () => {
        const { seDeconnecter } = await import('@/composables/useDeconnexion')
        serveur.regles.set('/dashboard', () => 'muet')
        await recevoirSansAttendre(pageDe('/tools'))
        await vi.waitUntil(() => serveur.appels.length === 1)
        const demande = serveur.appels[0].options.signal

        // À l'envoi de la déconnexion, la demande du préchauffage est déjà abandonnée.
        let abandonneeALEnvoi = null
        const envoyer = RequeteSimulee.prototype.send
        vi.spyOn(RequeteSimulee.prototype, 'send').mockImplementation(function (corps) {
            abandonneeALEnvoi ??= demande.aborted

            return envoyer.call(this, corps)
        })
        reseau.mode = 'coupe'

        const deconnexion = seDeconnecter()

        expect(demande.aborted).toBe(true)

        await deconnexion
        await vi.waitUntil(() => abandonneeALEnvoi !== null)
        await prechauffageFini()

        expect(abandonneeALEnvoi).toBe(true)
        expect(requetes.at(-1).url).toContain('/logout')
        expect(serveur.appels).toHaveLength(1)
    })
})

/** Une visite à laquelle le serveur répond par `page`, sans attendre le préchauffage qu'elle fait partir. */
const recevoirSansAttendre = async (page) => {
    let finie = false

    reseau.mode = 'repond'
    reseau.reponse = reponseDeVisite(page)
    routeur.visit(page.url, { onFinish: () => (finie = true) })
    await vi.waitUntil(() => finie)
}

describe('le branchement', () => {
    const source = (fichier) => readFileSync(resolve(jsRoot, fichier), 'utf-8')

    it('est posé par main.js après l’écriture des copies, dont il prend la file, avant le montage de l’application', () => {
        const main = source('main.js')
        const pose = main.indexOf('installerLePrechauffage({ routeur: router, ecriture: ecritureDesCopies })')

        expect(pose).toBeGreaterThan(
            main.indexOf('const ecritureDesCopies = installerLEcritureDesCopies({ routeur: router })'),
        )
        expect(pose).toBeLessThan(main.indexOf('createInertiaApp({'))
    })

    it('s’interrompt au tout début de seDeconnecter, avant l’attente des écritures et la question', () => {
        const deconnexion = source('composables/useDeconnexion.js')
        const corps = deconnexion.slice(deconnexion.indexOf('export const seDeconnecter = () => {'))

        expect(corps).toMatch(/^export const seDeconnecter = \(\) => \{\s+interrompreLePrechauffage\(\)\n/)
    })
})
