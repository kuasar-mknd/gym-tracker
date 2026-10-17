import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'

import { creerUneFabriqueEnMemoire } from './baseIndexeeEnMemoire'
import { RequeteSimulee, relayerLesRejetsNonGeres, reponseInertia, requetes, reseau, uneTache } from './reseauSimule'
import { jsRoot } from '../conventions/sourceFiles'
import { ECHEANCE_AU_REPOS_MS } from '@/Utils/auRepos'
import {
    MAGASIN_DES_PAGES,
    MAGASIN_DE_L_ETAT,
    NOM_DE_LA_BASE,
    TEMOIN,
    creerLeMagasinDesCopies,
    ouvrirLaBaseDuNavigateur,
} from '@/Utils/copiesHorsLigne'
import { ecritureDesCopiesInstallee, installerLEcritureDesCopies, propsRecues } from '@/Utils/ecritureDesCopies'
import { CLEF_DU_TITULAIRE_DE_L_APPAREIL, installerLaGardeDeLHistorique } from '@/Utils/historiqueDuCompte'
import { chargerSyncService, horodatageRecent, retirerLesEcouteursDuService } from './fileHorsLigne'

/*
 * La page écrit la copie des pages gardées qu'elle reçoit du réseau (#2048,
 * partie D), avec le vrai client d'Inertia : `success`, les visites
 * partielles et les props différées sont les siens, et la garde de
 * l'historique pose le titulaire de l'appareil comme dans `main.js`.
 *
 * Le client se monte une fois pour le fichier ; chaque cas repart d'un
 * magasin vide et d'une page remise par une visite du client seul (#2044).
 * La page initiale du document a ses fichiers à elle : elle ne se lit qu'au
 * démarrage.
 */

const COMPTE = 7

/** Une clé de 32 octets tirés au hasard, en base64url, comme le serveur la tire. */
const cleAuHasard = () =>
    btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '')

/** @param {string} cle */
const poserLeTemoin = (cle) => {
    document.cookie = `${TEMOIN}=${cle}; path=/`
}

/**
 * Une page d'un compte, telle que le serveur la rend en entier.
 *
 * @param {{ compte?: number, url?: string, component?: string, props?: object, encryptHistory?: boolean, deferredProps?: object }} [options]
 */
const pageDe = ({
    compte = COMPTE,
    url = '/dashboard',
    component = 'Dashboard',
    props = {},
    encryptHistory = true,
    deferredProps,
} = {}) => ({
    component,
    url,
    version: 'v1',
    encryptHistory,
    clearHistory: false,
    props: {
        errors: {},
        auth: {
            user: {
                id: compte,
                name: compte === COMPTE ? 'Camille Horsligne' : 'Dominique Autrecompte',
                email: 'camille.horsligne@example.org',
                active_workout: null,
            },
        },
        ...props,
    },
    ...(deferredProps === undefined ? {} : { deferredProps }),
})

/** La table des routes que `@routes` déclare dans le document. */
const ZIGGY = { url: 'http://localhost', port: null, defaults: {}, routes: { dashboard: { uri: 'dashboard' } } }

/** L'heure du magasin, que l'hygiène des sept jours fait avancer. */
let maintenant = Date.parse('2026-10-12T18:04:00Z')

let routeur
let fabrique
let magasin
let ecriture
let application
let relais

const copiesRangees = () => fabrique.enregistrements(NOM_DE_LA_BASE, MAGASIN_DES_PAGES)
const etatRange = () => fabrique.enregistrements(NOM_DE_LA_BASE, MAGASIN_DE_L_ETAT).get('etat')

/** Lance la visite, attend sa fin, puis que toutes les copies décidées soient écrites. */
const visiter = async (adresse, options = {}) => {
    let finie = false

    routeur.visit(adresse, { ...options, onFinish: () => (finie = true) })
    await vi.waitUntil(() => finie)
    await uneTache()
    await ecriture.ecrituresFinies()
}

/** Une visite que le serveur rend par `page`. */
const recevoir = (page, options = {}) => {
    reseau.mode = 'repond'
    reseau.reponse = reponseInertia(page)

    return visiter(page.url, options)
}

/**
 * La réponse partielle du serveur : les seules props que la visite a
 * demandées, et la forme de la page, que Laravel rend toujours.
 *
 * @param {object} props
 */
const reponsePartielle = (props) =>
    reponseInertia({ component: 'Dashboard', url: '/dashboard', version: 'v1', encryptHistory: true, props })

/** Une réponse partielle à un rechargement de l'accueil. */
const recevoirEnPartie = async (props, options) => {
    reseau.mode = 'repond'
    reseau.reponse = reponsePartielle(props)

    let fini = false

    routeur.reload({ ...options, onFinish: () => (fini = true) })
    await vi.waitUntil(() => fini)
    await uneTache()
    await ecriture.ecrituresFinies()
}

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    globalThis.Ziggy = ZIGGY
    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', '/dashboard')

    fabrique = creerUneFabriqueEnMemoire()
    magasin = creerLeMagasinDesCopies({
        ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique),
        horloge: () => maintenant,
    })

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router

    // Comme main.js : la garde de l'historique, puis l'écriture des copies, avant le démarrage d'Inertia.
    installerLaGardeDeLHistorique({
        routeur,
        pageInitiale: null,
        surUnAutreTitulaire: () => magasin.oublierLesCopiesDUnAutreTitulaire(),
    })
    ecriture = installerLEcritureDesCopies({ routeur, magasin, pageInitiale: null })

    await inertia.createInertiaApp({
        page: pageDe({ component: 'Tools/Index', url: '/tools' }),
        resolve: () => ({ render: () => h('p', { id: 'contenu' }, 'Page') }),
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) }).use(plugin)
            application.mount(el)
        },
    })
})

beforeEach(async () => {
    // Une visite du client seul, sans requête et sans `success` : aucune copie n'en vient.
    await new Promise((fini) =>
        routeur.replace({ ...pageDe({ component: 'Tools/Index', url: '/tools' }), onFinish: fini }),
    )
    await ecriture.ecrituresFinies()

    copiesRangees().clear()
    fabrique.enregistrements(NOM_DE_LA_BASE, MAGASIN_DE_L_ETAT).clear()
    localStorage.clear()
    requetes.length = 0
    reseau.mode = 'coupe'
    reseau.prochains = []
    reseau.reponse = null
    poserLeTemoin(cleAuHasard())
    relais = relayerLesRejetsNonGeres()
})

afterEach(() => {
    relais.retirer()
    delete globalThis.requestIdleCallback
})

afterAll(async () => {
    application.unmount()
    ecriture.desinstaller()
    await magasin.fermer()
    delete globalThis.Ziggy
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('une réponse complète du réseau', () => {
    it('garde la page entière, avec la table des routes, chiffrée', async () => {
        const page = pageDe({ props: { recentWorkouts: [{ name: 'Jambes du mardi' }] } })

        await recevoir(page)

        const lue = await magasin.lireUneCopie('/dashboard')

        expect(lue.page).toMatchObject({ component: 'Dashboard', url: '/dashboard', encryptHistory: true })
        expect(lue.page.props).toEqual(page.props)
        expect(lue.ziggy).toEqual(ZIGGY)
        expect(etatRange()).toMatchObject({ ouvert: true, compte: String(COMPTE) })
        expect(JSON.stringify([...copiesRangees().values()].map((copie) => ({ ...copie, chiffre: null })))).not.toMatch(
            /Camille|Jambes/,
        )
        expect(relais.rejets).toEqual([])
    })

    it('ne garde pas une page hors de la liste blanche, ni un autre composant à une adresse gardée', async () => {
        await recevoir(pageDe({ component: 'Stats/Index', url: '/stats' }))
        await recevoir(pageDe({ component: 'Journal/Index', url: '/dashboard' }))

        expect(copiesRangees().size).toBe(0)
        expect(etatRange()).toBeUndefined()
    })

    it('ne garde rien quand l’historique n’est pas chiffré : un contexte qui n’est pas sûr', async () => {
        await recevoir(pageDe({ encryptHistory: false }))

        expect(copiesRangees().size).toBe(0)
        expect(etatRange()).toBeUndefined()
    })

    it('d’un autre compte, vide d’abord le magasin du précédent, puis garde la sienne', async () => {
        await recevoir(pageDe())
        await recevoir(pageDe({ component: 'Workouts/Index', url: '/workouts' }))
        const empreinteDuPremier = etatRange().empreinte

        expect([...copiesRangees().keys()].sort()).toEqual(['/dashboard', '/workouts'])

        // Une autre session, une autre clé, un autre compte.
        poserLeTemoin(cleAuHasard())
        await recevoir(pageDe({ compte: 8 }))

        expect([...copiesRangees().keys()]).toEqual(['/dashboard'])
        expect(etatRange()).toMatchObject({ ouvert: true, compte: '8', empreintesFermees: [empreinteDuPremier] })
        expect((await magasin.lireUneCopie('/dashboard')).page.props.auth.user.name).toBe('Dominique Autrecompte')
        expect(await magasin.lireUneCopie('/workouts')).toBeNull()
    })
})

describe('une réponse partielle', () => {
    it('ne crée jamais de copie', async () => {
        // La page est là, mais aucune copie : elle n'est pas venue du réseau.
        await new Promise((fini) => routeur.replace({ ...pageDe(), onFinish: fini }))

        await recevoirEnPartie({ statistiques: { seances: 3 } }, { only: ['statistiques'] })

        expect(requetes.at(-1).entetes['X-Inertia-Partial-Data']).toBe('statistiques')
        expect(copiesRangees().size).toBe(0)
        expect(etatRange()).toBeUndefined()
    })

    it('ne verse que ses props dans la copie, et ne remplace jamais auth', async () => {
        await recevoir(pageDe({ props: { statistiques: { seances: 2 }, recentWorkouts: ['Jambes'] } }))

        await recevoirEnPartie(
            {
                statistiques: { seances: 3 },
                auth: { user: { id: COMPTE, name: 'Nom venu d’une réponse partielle', active_workout: null } },
            },
            { only: ['statistiques', 'auth'] },
        )

        const lue = await magasin.lireUneCopie('/dashboard')

        expect(lue.page.props.statistiques).toEqual({ seances: 3 })
        expect(lue.page.props.recentWorkouts).toEqual(['Jambes'])
        expect(lue.page.props.auth.user.name).toBe('Camille Horsligne')
        expect(lue.datesDesProps.statistiques).toBeGreaterThanOrEqual(lue.copieDu)
    })

    it('qui écarte des props (`except`) verse toutes les autres, et garde les écartées', async () => {
        await recevoir(pageDe({ props: { statistiques: { seances: 2 }, recentWorkouts: ['Jambes'] } }))

        await recevoirEnPartie({ statistiques: { seances: 4 } }, { except: ['recentWorkouts'] })

        expect(requetes.at(-1).entetes['X-Inertia-Partial-Except']).toBe('recentWorkouts')

        const lue = await magasin.lireUneCopie('/dashboard')

        expect(lue.page.props.statistiques).toEqual({ seances: 4 })
        expect(lue.page.props.recentWorkouts).toEqual(['Jambes'])
        expect(lue.page.props.auth.user.name).toBe('Camille Horsligne')
    })

    it('nomme les props reçues comme Inertia les fusionne : une prop imbriquée l’est dans sa racine', () => {
        const page = { props: { auth: {}, errors: {}, a: {}, b: [], c: 1 } }

        expect(propsRecues({ only: ['a.x', 'a.y', 'b'], except: [], reset: ['c'] }, page)).toEqual(['a', 'b', 'c'])
        expect(propsRecues({ only: [], except: ['b', 'a.x'], reset: [] }, page)).toEqual(['auth', 'errors', 'a', 'c'])
    })

    it('qui rend un autre composant est une réponse complète : le serveur a tout rendu', async () => {
        await new Promise((fini) => routeur.replace({ ...pageDe(), onFinish: fini }))
        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(pageDe({ component: 'Workouts/Index', url: '/workouts' }))

        let fini = false
        routeur.reload({ only: ['statistiques'], onFinish: () => (fini = true) })
        await vi.waitUntil(() => fini)
        await ecriture.ecrituresFinies()

        expect([...copiesRangees().keys()]).toEqual(['/workouts'])
    })

    /*
     * Les props différées partent dès que la page est posée, et leur réponse
     * peut revenir avant que la page soit au repos : leur écriture est alors
     * décidée avant que la copie de la page soit écrite, et ne doit pas passer
     * devant elle, qu'elle ne trouverait pas.
     */
    it('des props différées trouvent la copie de leur page, même décidées avant qu’elle soit écrite', async () => {
        const auRepos = []
        globalThis.requestIdleCallback = (rappel) => {
            auRepos.push(rappel)
        }
        const fins = []
        const arreter = routeur.on('finish', () => fins.push(true))

        reseau.mode = 'muet'
        routeur.visit('/dashboard')
        await vi.waitUntil(() => requetes.length === 1)
        requetes[0].repondre(reponseInertia(pageDe({ deferredProps: { default: ['graphique'] } })))

        // Inertia demande aussitôt les props différées, en partiel.
        await vi.waitUntil(() => requetes.length === 2)
        expect(requetes[1].entetes['X-Inertia-Partial-Data']).toBe('graphique')
        requetes[1].repondre(reponsePartielle({ graphique: [1, 2] }))
        await vi.waitUntil(() => fins.length === 2)
        arreter()

        // Les deux écritures sont décidées, aucune n'est faite : la page arrive au repos.
        expect(auRepos).toHaveLength(2)
        expect(copiesRangees().size).toBe(0)
        auRepos.forEach((rappel) => rappel())
        await ecriture.ecrituresFinies()

        const lue = await magasin.lireUneCopie('/dashboard')

        expect(lue.page.props.graphique).toEqual([1, 2])
        expect(lue.page.props.auth.user.id).toBe(COMPTE)
    })
})

describe('une réponse qu’Inertia n’a pas posée', () => {
    /*
     * Une réponse asynchrone revenue quand la page a changé de composant,
     * Inertia ne la pose pas (`shouldSetPage`) et émet pourtant `success`,
     * avec la page courante : rien de ce `success` n'est une réponse.
     */
    it('des props différées revenues après une visite côté client ne s’écrivent nulle part, sans lever', async () => {
        const erreurs = []
        const surUneErreur = (evenement) => erreurs.push(evenement.error ?? evenement.message)
        const succes = []

        window.addEventListener('error', surUneErreur)
        reseau.mode = 'muet'
        routeur.visit('/dashboard')
        await vi.waitUntil(() => requetes.length === 1)
        requetes[0].repondre(reponseInertia(pageDe({ deferredProps: { default: ['graphique'] } })))
        await vi.waitUntil(() => requetes.length === 2)
        await ecriture.ecrituresFinies()

        // La page quitte l'accueil par une visite côté client, avant la réponse des props différées.
        await new Promise((fini) =>
            routeur.push({ ...pageDe({ component: 'Workouts/Index', url: '/workouts' }), onFinish: fini }),
        )
        const arreter = routeur.on('success', (evenement) => succes.push(evenement.detail.page.component))
        requetes[1].repondre(reponsePartielle({ graphique: [1, 2] }))
        await vi.waitUntil(() => succes.length === 1)
        arreter()
        await uneTache()
        await ecriture.ecrituresFinies()
        window.removeEventListener('error', surUneErreur)

        expect(succes).toEqual(['Workouts/Index'])
        expect([...copiesRangees().keys()]).toEqual(['/dashboard'])
        expect((await magasin.lireUneCopie('/dashboard')).page.props).not.toHaveProperty('graphique')
        expect(erreurs).toEqual([])
        expect(relais.rejets).toEqual([])
    })
})

describe('l’écriture au repos', () => {
    it('sans requestIdleCallback (Safari, Safari iOS), écrit quand même, sans ReferenceError', async () => {
        expect('requestIdleCallback' in globalThis).toBe(false)
        const erreurs = []
        const surErreur = (evenement) => erreurs.push(evenement.error ?? evenement.message)
        window.addEventListener('error', surErreur)

        await recevoir(pageDe())

        window.removeEventListener('error', surErreur)
        expect(erreurs).toEqual([])
        expect(relais.rejets).toEqual([])
        expect((await magasin.lireUneCopie('/dashboard')).page.component).toBe('Dashboard')
    })

    it('passe par requestIdleCallback quand le navigateur l’a, avec une échéance', async () => {
        globalThis.requestIdleCallback = vi.fn((rappel) => setTimeout(rappel, 0))

        await recevoir(pageDe())

        expect(globalThis.requestIdleCallback).toHaveBeenCalledWith(expect.any(Function), {
            timeout: ECHEANCE_AU_REPOS_MS,
        })
        expect(copiesRangees().has('/dashboard')).toBe(true)
    })

    it('attend que la page soit au repos pour écrire', async () => {
        const enAttente = []
        globalThis.requestIdleCallback = (rappel) => {
            enAttente.push(rappel)
        }
        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(pageDe())

        let finie = false
        routeur.visit('/dashboard', { onFinish: () => (finie = true) })
        await vi.waitUntil(() => finie)
        await fabrique.auRepos()

        expect(enAttente).toHaveLength(1)
        expect(copiesRangees().size).toBe(0)

        enAttente.forEach((rappel) => rappel())
        await ecriture.ecrituresFinies()

        expect(copiesRangees().has('/dashboard')).toBe(true)
    })
    it('garde la page telle que le réseau l’a rendue, même modifiée avant le repos', async () => {
        const auRepos = []
        globalThis.requestIdleCallback = (rappel) => {
            auRepos.push(rappel)
        }
        let recue = null
        const arreter = routeur.on('success', (evenement) => {
            recue = evenement.detail.page
        })
        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(pageDe({ props: { recentWorkouts: [{ name: 'Jambes du mardi' }] } }))

        let finie = false
        routeur.visit('/dashboard', { onFinish: () => (finie = true) })
        await vi.waitUntil(() => finie)
        arreter()

        // Un composant qui modifierait les props de la page après la réponse.
        recue.props.recentWorkouts[0].name = 'Modifiée à l’écran'
        auRepos.forEach((rappel) => rappel())
        await ecriture.ecrituresFinies()

        expect((await magasin.lireUneCopie('/dashboard')).page.props.recentWorkouts).toEqual([
            { name: 'Jambes du mardi' },
        ])
    })
})

/*
 * La séance en cours que dit chaque page complète (#2048, section « La
 * séance » de l'issue) : terminer une séance mène à l'accueil, et sa copie
 * la montrait encore en cours. Et le rejeu d'une écriture de séance par la
 * file, qui date dans l'état ce que la copie de la séance n'a pas.
 */
describe('la séance en cours', () => {
    /** La séance 5 d'un compte qui a `enCours` en cours, close ou non. */
    const pageDeLaSeance = ({ id = 5, enCours = id, close = false } = {}) => {
        const page = pageDe({
            component: 'Workouts/Show',
            url: `/workouts/${id}`,
            props: { workout: { id, name: 'Séance du soir', ended_at: close ? '2026-10-12T19:00:00Z' : null } },
        })

        page.props.auth.user.active_workout = enCours === null ? null : { id: enCours }

        return page
    }

    /** L'accueil d'un compte qui a `enCours` en cours. */
    const accueil = (enCours) => {
        const page = pageDe()

        page.props.auth.user.active_workout = enCours === null ? null : { id: enCours }

        return page
    }

    it('retire la copie d’une séance terminée qui la montre encore en cours, et garde une séance close', async () => {
        await recevoir(pageDeLaSeance({ id: 4, enCours: 5, close: true }))
        await recevoir(pageDeLaSeance({ id: 5, enCours: 5 }))

        expect([...copiesRangees().keys()].sort()).toEqual(['/workouts/4', '/workouts/5'])

        // Terminer la séance 5 mène à l'accueil : le compte n'a plus de séance en cours.
        await recevoir(accueil(null))

        expect([...copiesRangees().keys()].sort()).toEqual(['/dashboard', '/workouts/4'])
        expect(await magasin.lireUneCopie('/workouts/5')).toBeNull()
    })

    it('une autre séance démarrée retire la copie de la précédente, qui la montrait en cours', async () => {
        await recevoir(pageDeLaSeance({ id: 4, enCours: 4 }))
        await recevoir(pageDeLaSeance({ id: 5, enCours: 5 }))

        expect([...copiesRangees().keys()]).toEqual(['/workouts/5'])
    })

    it('rafraîchit, sans la retirer, la copie de la séance terminée quand la réponse est son compte rendu', async () => {
        await recevoir(accueil(5))
        await recevoir(pageDeLaSeance({ id: 5, enCours: 5 }))

        await recevoir(pageDeLaSeance({ id: 5, enCours: null, close: true }))

        expect((await magasin.lireUneCopie('/workouts/5')).page.props.workout.ended_at).toBe('2026-10-12T19:00:00Z')
    })

    it('ne retire pas une séance close, ni rien tant que la séance en cours ne change pas', async () => {
        await recevoir(accueil(6))
        await recevoir(pageDeLaSeance({ id: 3, enCours: 6, close: true }))
        await recevoir(pageDeLaSeance({ id: 6, enCours: 6 }))
        await recevoir(accueil(6))

        expect([...copiesRangees().keys()].sort()).toEqual(['/dashboard', '/workouts/3', '/workouts/6'])

        await recevoir(accueil(null))

        expect([...copiesRangees().keys()].sort()).toEqual(['/dashboard', '/workouts/3'])
    })

    it('dès la première page de l’onglet, retire une séance close ailleurs, application fermée', async () => {
        await recevoir(accueil(5))
        await recevoir(pageDeLaSeance({ id: 5, enCours: 5 }))

        // Un autre chargement de la page : la séance 5 a été terminée sur un autre appareil.
        ecriture.desinstaller()
        ecriture = installerLEcritureDesCopies({ routeur, magasin, pageInitiale: accueil(null) })
        await ecriture.ecrituresFinies()

        expect([...copiesRangees().keys()]).toEqual(['/dashboard'])
    })

    it('le rejeu d’une écriture de séance par la file date le dernier rejeu ; celui d’une autre écriture, non', async () => {
        await recevoir(pageDe())

        window.dispatchEvent(
            new CustomEvent('sync:replayed', { detail: { queueId: 'q1', url: '/profile/preferences', data: {} } }),
        )
        await uneTache()
        await fabrique.auRepos()

        expect(etatRange().dernierRejeu).toBeNull()

        maintenant += 60_000
        window.dispatchEvent(
            new CustomEvent('sync:replayed', {
                detail: { queueId: 'q2', url: 'http://localhost/api/v1/sets/3', data: { id: 3 } },
            }),
        )

        await vi.waitFor(() => expect(etatRange().dernierRejeu).toBe(maintenant))
    })
})

describe('pour la page de séance et le préchauffage', () => {
    it('est l’écriture installée, que lisent la page de séance et le préchauffage', () => {
        expect(ecritureDesCopiesInstallee()).toBe(ecriture)
    })

    it('dit d’où vient la page : du réseau, d’une visite côté client, de l’historique', async () => {
        await recevoir(pageDe())
        expect(ecriture.origineDeLaPage()).toBe('reseau')

        await new Promise((fini) =>
            routeur.replace({ ...pageDe({ component: 'Tools/Index', url: '/tools' }), onFinish: fini }),
        )
        expect(ecriture.origineDeLaPage()).toBe('client')

        await recevoir(pageDe({ component: 'Workouts/Index', url: '/workouts' }))
        expect(ecriture.origineDeLaPage()).toBe('reseau')

        const retour = new Promise((fini) => window.addEventListener('popstate', fini, { once: true }))
        window.history.back()
        await retour

        expect(ecriture.origineDeLaPage()).toBe('historique')
    })

    it('fait passer sans attendre le repos ce qui est décidé, quand la page se cache', async () => {
        const auRepos = []
        globalThis.requestIdleCallback = (rappel) => {
            auRepos.push(rappel)
        }
        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(pageDe())

        let finie = false
        routeur.visit('/dashboard', { onFinish: () => (finie = true) })
        await vi.waitUntil(() => finie)
        await fabrique.auRepos()

        expect(copiesRangees().size).toBe(0)

        ecriture.presser()
        await ecriture.ecrituresFinies()

        expect(copiesRangees().has('/dashboard')).toBe(true)
        expect(auRepos.length).toBeGreaterThan(0)
    })

    it('verse une réponse partielle venue d’ailleurs qu’une visite, sans auth, dans la copie existante', async () => {
        await recevoir(pageDe({ props: { statistiques: { seances: 2 } } }))

        ecriture.completer(
            pageDe({ props: { statistiques: { seances: 5 }, auth: { user: { id: COMPTE, name: 'Autre nom' } } } }),
            ['statistiques', 'auth'],
        )
        await ecriture.ecrituresFinies()

        const lue = await ecriture.lireLaCopie('/dashboard')

        expect(lue.page.props.statistiques).toEqual({ seances: 5 })
        expect(lue.page.props.auth.user.name).toBe('Camille Horsligne')
    })
})

/*
 * Les purges que l'écriture branche (#2048, « Vie privée ») : un autre
 * titulaire de l'appareil, la fin de la session que la file constate,
 * l'hygiène du chargement, et la barrière qu'une purge pose devant une copie
 * décidée avant elle. La déconnexion et la suppression du compte ont leur
 * fichier, avec le vrai layout.
 */
describe('les purges', () => {
    it('un autre onglet qui reçoit la page d’un autre compte fait oublier les copies de celui-ci', async () => {
        await recevoir(pageDe())
        const empreinte = etatRange().empreinte

        // L'autre onglet : sa propre garde, son propre sessionStorage, le même localStorage.
        const sonOnglet = new Map()
        const relais = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(function (clef, valeur) {
            relais.mockRestore()
            this.setItem(clef, valeur)
            window.dispatchEvent(new StorageEvent('storage', { key: clef, newValue: valeur, storageArea: this }))
        })
        installerLaGardeDeLHistorique({
            routeur: { on: () => {}, clearHistory: () => {} },
            fenetre: {
                localStorage,
                sessionStorage: {
                    getItem: (clef) => sonOnglet.get(clef) ?? null,
                    setItem: (clef, valeur) => sonOnglet.set(clef, valeur),
                },
                document: { documentElement: { style: {} } },
                addEventListener: () => {},
            },
            pageInitiale: pageDe({ compte: 8 }),
        })
        await vi.waitUntil(() => etatRange().ouvert === false)

        expect(localStorage.getItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL)).toBe('compte:8')
        expect(copiesRangees().size).toBe(0)
        expect(etatRange().empreintesFermees).toEqual([empreinte])
    })

    /**
     * Rejoue un chargement de la file hors ligne avec une écriture en
     * attente, et un serveur qui répond 401 à son rejeu : la session est
     * finie. Rend la file, et ce qu'elle a annoncé.
     */
    const rejouerSousUneSessionFinie = async () => {
        localStorage.setItem(
            'offline_sync_queue',
            JSON.stringify([
                {
                    method: 'patch',
                    url: '/api/v1/sets/1',
                    data: { weight: 100 },
                    id: 'en-file-1',
                    timestamp: horodatageRecent(),
                    compte: String(COMPTE),
                },
            ]),
        )
        vi.stubGlobal('fetch', () =>
            Promise.resolve(
                new Response('{"message":"Unauthenticated."}', {
                    status: 401,
                    headers: { 'content-type': 'application/json' },
                }),
            ),
        )
        const annonces = []
        const ecouter = (evenement) => annonces.push(evenement.detail)
        window.addEventListener('sync:auth-required', ecouter)

        try {
            const file = await chargerSyncService({ compte: COMPTE })
            await file.pending
            await vi.waitUntil(() => etatRange()?.ouvert === false)

            return { file, annonces }
        } finally {
            window.removeEventListener('sync:auth-required', ecouter)
            retirerLesEcouteursDuService()
        }
    }

    it('la fin de la session que la file hors ligne constate (401 du rejeu) les fait oublier', async () => {
        await recevoir(pageDe())
        const empreinte = etatRange().empreinte

        const { file, annonces } = await rejouerSousUneSessionFinie()

        expect(annonces).toEqual([expect.objectContaining({ status: 401, pending: 1 })])
        // La file, elle, reste au compte pour son retour (#1964).
        expect(file.enAttente()).toBe(1)
        expect(copiesRangees().size).toBe(0)
        expect(etatRange().empreintesFermees).toEqual([empreinte])
    })

    it('et ferme la clé du témoin, même quand aucune copie n’avait encore été écrite', async () => {
        localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, `compte:${COMPTE}`)

        await rejouerSousUneSessionFinie()

        // Une réponse en vol qui reposerait la clé de la session finie ne rouvre rien.
        expect(etatRange().empreintesFermees).toHaveLength(1)
        expect(await magasin.ecrireUneCopie(pageDe())).toBe('cle-fermee')
    })

    it('l’hygiène du chargement efface une copie de plus de sept jours', async () => {
        await recevoir(pageDe())
        maintenant += 7 * 24 * 60 * 60 * 1000 + 1

        const autreChargement = installerLEcritureDesCopies({ routeur, magasin, pageInitiale: null })
        await autreChargement.ecrituresFinies()
        autreChargement.desinstaller()

        expect(copiesRangees().size).toBe(0)
        expect(etatRange()).toMatchObject({ ouvert: true, compte: String(COMPTE) })
    })

    /*
     * La suppression du compte oublie les copies sans fermer la clé : une
     * copie décidée avant elle, pas encore écrite, rouvrirait le magasin
     * pour le compte qui part si elle prenait sa génération en s'écrivant.
     */
    it('une copie décidée avant une purge ne s’écrit pas après, même sous la même clé', async () => {
        const auRepos = []
        globalThis.requestIdleCallback = (rappel) => {
            auRepos.push(rappel)
        }
        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(pageDe())

        let finie = false
        routeur.visit('/dashboard', { onFinish: () => (finie = true) })
        await vi.waitUntil(() => finie)
        await magasin.oublierLesCopies({ fermerLEtat: false })
        auRepos.forEach((rappel) => rappel())
        await ecriture.ecrituresFinies()

        expect(copiesRangees().size).toBe(0)
        expect(etatRange()).toMatchObject({ ouvert: false, empreintesFermees: [] })

        // La page suivante de la session, elle, garde de nouveau.
        delete globalThis.requestIdleCallback
        await recevoir(pageDe())

        expect(copiesRangees().has('/dashboard')).toBe(true)
    })
})

/*
 * La page initiale d'un document qui ne vient pas du réseau : la coquille
 * hors ligne (partie F) la tire d'une copie, et une copie montrée porte sa
 * marque (`props.horsLigne`, partie E). La réécrire la daterait d'aujourd'hui.
 * Le document rouvert par l'historique, et celui qui vient du réseau, ont
 * leurs fichiers, avec le démarrage d'Inertia.
 */
describe('la page initiale d’un document qui ne vient pas du réseau', () => {
    /**
     * Une seconde écriture, branchée sur un document à part le temps du cas.
     *
     * @param {{ coquille?: boolean, page?: object }} options
     */
    const ecrireLaPageInitiale = async ({ coquille = false, page = pageDe() }) => {
        const documentServi = document.implementation.createHTMLDocument('')
        const evenements = new EventTarget()

        if (coquille) {
            documentServi.documentElement.dataset.coquille = ''
        }

        localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, `compte:${COMPTE}`)

        const seconde = installerLEcritureDesCopies({
            routeur,
            magasin,
            pageInitiale: page,
            fenetre: {
                document: documentServi,
                performance: { getEntriesByType: () => [{ type: 'navigate' }] },
                addEventListener: (...arguments_) => evenements.addEventListener(...arguments_),
                removeEventListener: (...arguments_) => evenements.removeEventListener(...arguments_),
            },
        })

        await seconde.ecrituresFinies()
        seconde.desinstaller()
    }

    it('venue du réseau, est gardée', async () => {
        await ecrireLaPageInitiale({})

        expect([...copiesRangees().keys()]).toEqual(['/dashboard'])
    })

    it('la coquille hors ligne ne garde rien', async () => {
        await ecrireLaPageInitiale({ coquille: true })

        expect(copiesRangees().size).toBe(0)
    })

    it('une page qui porte la marque d’une copie ne se garde pas de nouveau', async () => {
        await ecrireLaPageInitiale({ page: pageDe({ props: { horsLigne: { copieDu: 1, raison: 'reseau' } } }) })

        expect(copiesRangees().size).toBe(0)
    })
})

describe('le branchement', () => {
    const main = () => readFileSync(resolve(jsRoot, 'main.js'), 'utf-8')

    it('est posé par main.js après la garde de l’historique, avant le montage de l’application', () => {
        const pose = main().indexOf('installerLEcritureDesCopies({ routeur: router })')

        expect(pose).toBeGreaterThan(main().indexOf('installerLaGardeDeLHistorique('))
        expect(pose).toBeLessThan(main().indexOf('createInertiaApp({'))
    })

    it('fait oublier par la garde de l’historique les copies d’un autre titulaire, celles du magasin de la page', () => {
        expect(main()).toContain(
            'installerLaGardeDeLHistorique({ routeur: router, surUnAutreTitulaire: oublierLesCopiesDUnAutreTitulaire })',
        )
        expect(main()).toContain("import { oublierLesCopiesDUnAutreTitulaire } from '@/Utils/copiesHorsLigne'")
    })
})
