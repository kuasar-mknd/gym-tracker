import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import { creerDesCachesEnMemoire } from '../utils/cachesEnMemoire'
import { creerUneFabriqueEnMemoire } from '../utils/baseIndexeeEnMemoire'
import { CACHE_DE_LA_CONSTRUCTION_PRECEDENTE } from '@/sw/cacheDesActifs'
import {
    ENTREE_DE_L_APPLICATION,
    DOSSIER_DES_PAGES,
    GRAPHIQUES_INDISPONIBLES_HORS_LIGNE,
    greffonDeLaFermetureDuPrecache,
    pointsDeDepartDuPrecache,
} from '@/sw/fermetureDuPrecache'
import { CLEF_DU_TITULAIRE_DE_L_APPAREIL } from '@/Utils/historiqueDuCompte'

/*
 * Deux constructions successives (#2048, partie E, décision 9 du
 * propriétaire) : une page restée sur l'ancienne garde ses morceaux sans
 * réseau, et ses copies survivent tant que le contrat n'a pas changé.
 *
 * Le vrai worker (`resources/js/sw.js`) et le vrai Workbox, chargés une fois
 * par construction comme un navigateur charge chaque worker neuf, sur des
 * caches en mémoire (`cachesEnMemoire.js`) et un serveur qui ne sert que la
 * construction déployée. Le precache de chaque construction sort du vrai
 * greffon de la fermeture, sur un manifeste de construction fait à la main.
 * Les copies passent par le vrai magasin, rechargé comme le charge le code
 * d'une nouvelle construction, sur la base en mémoire du même appareil.
 */

const ORIGINE = self.location.origin

/** Le precache de Workbox, sous la portée du worker. */
const PRECACHE = `workbox-precache-v2-${ORIGINE}/`

/**
 * Un évènement prolongeable, comme le worker en reçoit : `waitUntil` garde
 * chaque promesse, que l'envoi attend.
 */
class EvenementProlongeable extends Event {
    constructor(type) {
        super(type)
        /** @type {Promise<unknown>[]} */
        this.attentes = []
    }

    waitUntil(promesse) {
        this.attentes.push(Promise.resolve(promesse))
    }
}

/** Une requête que le worker reçoit : un second `respondWith` lève, comme dans un navigateur. */
class EvenementDeRequete extends EvenementProlongeable {
    constructor(requete) {
        super('fetch')
        this.request = requete
        this.preloadResponse = Promise.resolve(undefined)
        /** @type {Promise<Response>[]} */
        this.reponses = []
    }

    respondWith(reponse) {
        if (this.reponses.length > 0) {
            throw new DOMException('The event has already been responded to.', 'InvalidStateError')
        }

        this.reponses.push(Promise.resolve(reponse))
    }
}

/** Le serveur : il ne sert que la construction déployée, et rien quand le réseau est coupé. */
const serveur = { fichiers: new Map(), coupe: false }

/** @param {Request | string} requete */
const fetchDuServeur = async (requete) => {
    if (serveur.coupe) {
        throw new TypeError('Failed to fetch')
    }

    const corps = serveur.fichiers.get(new URL(typeof requete === 'string' ? requete : requete.url).pathname)

    return corps === undefined ? new Response('introuvable', { status: 404 }) : new Response(corps, { status: 200 })
}

/** Le nom d'un fichier de page dans une construction. */
const fichierDeLaPage = (nom, hachage) =>
    `assets/${nom.slice(DOSSIER_DES_PAGES.length, -'.vue'.length).replaceAll('/', '-')}-${hachage}.js`

/**
 * Une construction : son manifeste, comme Vite l'écrit, ses fichiers, comme
 * le serveur les sert, et le precache que le greffon de la fermeture en tire.
 * L'entrée, la séance et l'accueil changent de hachage d'une construction à
 * l'autre ; Vue reste le même fichier. L'accueil charge les graphiques nommés,
 * qui seuls importent Chart.js ; l'entrée charge chaque page, une page qui
 * n'est pas gardée comprise.
 *
 * @param {string} hachage
 */
const construction = (hachage) => {
    const pages = pointsDeDepartDuPrecache().slice(1)
    const statistiques = `${DOSSIER_DES_PAGES}Stats/Index.vue`
    const manifeste = {
        [ENTREE_DE_L_APPLICATION]: {
            file: `assets/main-${hachage}.js`,
            isEntry: true,
            css: [`assets/main-${hachage}.css`],
            imports: ['_vue-core-Commun.js'],
            dynamicImports: [...pages, statistiques, `_SyncService-${hachage}.js`],
        },
        '_vue-core-Commun.js': { file: 'assets/vue-core-Commun.js' },
        [`_SyncService-${hachage}.js`]: { file: `assets/SyncService-${hachage}.js` },
        [`_BaseChart-${hachage}.js`]: { file: `assets/BaseChart-${hachage}.js` },
        [statistiques]: { file: fichierDeLaPage(statistiques, hachage) },
    }

    for (const page of pages) {
        manifeste[page] = { file: fichierDeLaPage(page, hachage), imports: ['_vue-core-Commun.js'] }
    }

    manifeste[`${DOSSIER_DES_PAGES}Dashboard.vue`].dynamicImports = [...GRAPHIQUES_INDISPONIBLES_HORS_LIGNE]
    manifeste[`${DOSSIER_DES_PAGES}Workouts/Show.vue`].css = [`assets/Show-${hachage}.css`]

    for (const graphique of GRAPHIQUES_INDISPONIBLES_HORS_LIGNE) {
        manifeste[graphique] = {
            file: `assets/${graphique.split('/').at(-1).replace('.vue', '')}-${hachage}.js`,
            imports: [`_BaseChart-${hachage}.js`],
        }
    }

    const fichiers = new Map([['/build/hors-ligne.html', `<!DOCTYPE html><title>Hors ligne ${hachage}</title>`]])

    for (const entree of Object.values(manifeste)) {
        for (const fichier of [entree.file, ...(entree.css ?? [])]) {
            fichiers.set(`/build/${fichier}`, `${fichier} de la construction ${hachage}`)
        }
    }

    const pwa = {
        entrees: [],
        extendManifestEntries(rappel) {
            pwa.entrees = rappel(pwa.entrees)
        },
    }
    const greffon = greffonDeLaFermetureDuPrecache()
    greffon.configResolved({ plugins: [{ name: 'vite-plugin-pwa', api: pwa }], build: { manifest: 'manifest.json' } })
    greffon.writeBundle.call(
        {
            error: (message) => {
                throw new Error(message)
            },
        },
        {},
        { 'manifest.json': { type: 'asset', source: JSON.stringify(manifeste) } },
    )

    return {
        precache: [{ url: '/build/hors-ligne.html', revision: hachage }, ...pwa.entrees],
        fichiers,
        page: (nom) => `${ORIGINE}/build/${fichierDeLaPage(`${DOSSIER_DES_PAGES}${nom}.vue`, hachage)}`,
        fichier: (chemin) => `${ORIGINE}/build/${chemin}`,
    }
}

/** Les écouteurs du worker en cours de chargement. */
let ecouteursEnCharge = {}

/**
 * Déploie une construction et charge son worker, comme un navigateur charge
 * chaque worker neuf : un module neuf, Workbox compris, puis l'installation
 * et l'activation, chacune attendue jusqu'au bout.
 *
 * @param {ReturnType<typeof construction>} deployee
 */
const deployer = async (deployee) => {
    serveur.fichiers = deployee.fichiers
    ecouteursEnCharge = {}
    self.__WB_MANIFEST = deployee.precache
    vi.resetModules()
    await import('@/sw.js')

    const ecouteurs = ecouteursEnCharge

    for (const type of ['install', 'activate']) {
        const evenement = new EvenementProlongeable(type)

        for (const ecouteur of ecouteurs[type] ?? []) {
            ecouteur(evenement)
        }

        await Promise.all(evenement.attentes)
    }

    return ecouteurs
}

/**
 * Une requête de la page au worker actif. Sans `respondWith`, le navigateur
 * va lui-même au réseau.
 *
 * @param {Record<string, Function[]>} ecouteurs
 * @param {string} adresse
 * @returns {Promise<{ reponse: Response | null, parLeWorker: boolean }>}
 */
const demander = async (ecouteurs, adresse) => {
    const evenement = new EvenementDeRequete(new Request(adresse))

    for (const ecouteur of ecouteurs.fetch ?? []) {
        ecouteur(evenement)
    }

    const parLeWorker = evenement.reponses.length > 0
    const reponse = await (parLeWorker ? evenement.reponses[0] : fetchDuServeur(evenement.request)).catch(() => null)
    await Promise.all(evenement.attentes).catch(() => undefined)

    return { reponse, parLeWorker }
}

/** Le texte d'une réponse, ou `null` sans réponse. */
const texte = async (resultat) => (resultat.reponse === null ? null : resultat.reponse.text())

let caches

beforeAll(() => {
    vi.spyOn(self, 'addEventListener').mockImplementation((type, ecouteur) => {
        ;(ecouteursEnCharge[type] ??= []).push(ecouteur)
    })
})

beforeEach(() => {
    caches = creerDesCachesEnMemoire(`${ORIGINE}/sw.js`)
    serveur.coupe = false
    vi.stubGlobal('caches', caches)
    vi.stubGlobal('fetch', fetchDuServeur)
    vi.stubGlobal('ExtendableEvent', EvenementProlongeable)
    vi.stubGlobal('FetchEvent', EvenementDeRequete)
    vi.stubGlobal('__WB_DISABLE_DEV_LOGS', true)
    vi.stubGlobal('registration', {
        scope: `${ORIGINE}/`,
        navigationPreload: { enable: vi.fn(async () => undefined) },
        pushManager: {},
        showNotification: vi.fn(),
    })
    vi.stubGlobal('clients', { claim: vi.fn(async () => undefined), matchAll: vi.fn(async () => []) })
    vi.stubGlobal('skipWaiting', vi.fn())
})

afterEach(() => {
    vi.unstubAllGlobals()
    delete self.__WB_MANIFEST
})

afterAll(() => {
    vi.restoreAllMocks()
})

describe('une page restée sur la construction précédente', () => {
    it('garde sans réseau les morceaux que le nouveau precache n’a plus', async () => {
        const premiere = construction('Un')
        const seconde = construction('Deux')

        await deployer(premiere)
        expect(caches.adresses(PRECACHE)).toContain(premiere.page('Workouts/Show'))
        const worker = await deployer(seconde)
        serveur.coupe = true

        // Workbox a retiré la première construction du precache, à l'activation.
        expect(caches.adresses(PRECACHE)).not.toContain(premiere.page('Workouts/Show'))
        expect(caches.adresses(PRECACHE)).toContain(seconde.page('Workouts/Show'))

        // La page, chargée sous la première construction, ouvre la séance depuis sa copie.
        const seance = await demander(worker, premiere.page('Workouts/Show'))
        const feuille = await demander(worker, premiere.fichier('assets/Show-Un.css'))

        expect(seance.parLeWorker).toBe(true)
        await expect(texte(seance)).resolves.toBe('assets/Workouts-Show-Un.js de la construction Un')
        await expect(texte(feuille)).resolves.toBe('assets/Show-Un.css de la construction Un')
        expect(caches.adresses(CACHE_DE_LA_CONSTRUCTION_PRECEDENTE)).toEqual(
            expect.arrayContaining([premiere.page('Dashboard'), premiere.fichier('assets/main-Un.js')]),
        )
    })

    it('reçoit de la route du precache ce que les deux constructions partagent, une seule réponse', async () => {
        await deployer(construction('Un'))
        const worker = await deployer(construction('Deux'))
        serveur.coupe = true

        const vue = await demander(worker, `${ORIGINE}/build/assets/vue-core-Commun.js`)

        expect(vue.parLeWorker).toBe(true)
        await expect(texte(vue)).resolves.toBe('assets/vue-core-Commun.js de la construction Un')
        expect(caches.adresses(CACHE_DE_LA_CONSTRUCTION_PRECEDENTE)).not.toContain(
            `${ORIGINE}/build/assets/vue-core-Commun.js`,
        )
    })

    it('ne garde qu’une construction en arrière : la troisième efface la première', async () => {
        const premiere = construction('Un')
        const seconde = construction('Deux')

        await deployer(premiere)
        await deployer(seconde)
        const worker = await deployer(construction('Trois'))
        serveur.coupe = true

        await expect(texte(await demander(worker, premiere.page('Workouts/Show')))).resolves.toBeNull()
        await expect(texte(await demander(worker, seconde.page('Workouts/Show')))).resolves.toBe(
            'assets/Workouts-Show-Deux.js de la construction Deux',
        )
    })

    it('ne met de côté ni les graphiques, restés hors du precache, ni une page qui n’est pas gardée', async () => {
        const premiere = construction('Un')

        await deployer(premiere)
        await deployer(construction('Deux'))

        const misDeCote = caches.adresses(CACHE_DE_LA_CONSTRUCTION_PRECEDENTE)

        expect(misDeCote).not.toContain(premiere.fichier('assets/WeeklyVolumeChart-Un.js'))
        expect(misDeCote).not.toContain(premiere.fichier('assets/BaseChart-Un.js'))
        expect(misDeCote).not.toContain(premiere.page('Stats/Index'))
        expect(misDeCote).not.toContain(`${ORIGINE}/build/hors-ligne.html`)
    })
})

describe('les copies d’une construction à l’autre', () => {
    const fabrique = { courante: null }

    /** Une clé de 32 octets en base64url, comme le serveur la tire. */
    const cleAuHasard = () =>
        btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=+$/, '')

    const accueil = {
        component: 'Dashboard',
        url: '/dashboard',
        version: 'version-des-actifs',
        encryptHistory: true,
        clearHistory: false,
        props: {
            auth: { user: { id: 7, name: 'Camille Horsligne', email: 'camille@example.org', active_workout: null } },
            errors: {},
            recentWorkouts: [{ id: 3, name: 'Séance du matin' }],
        },
    }

    /**
     * Le magasin des copies tel que le charge le code d'une construction :
     * un module neuf, sur la base du même appareil.
     */
    const magasinDUneConstruction = async () => {
        const { creerLeMagasinDesCopies, ouvrirLaBaseDuNavigateur } = await import('@/Utils/copiesHorsLigne')

        return creerLeMagasinDesCopies({ ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique.courante) })
    }

    beforeEach(() => {
        fabrique.courante = creerUneFabriqueEnMemoire()
        document.cookie = `gt_copies=${cleAuHasard()}; path=/`
        localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, 'compte:7')
    })

    afterEach(() => {
        document.cookie = 'gt_copies=; path=/; max-age=0'
        localStorage.clear()
        vi.doUnmock('@/Utils/pagesGardees')
    })

    it('se lisent encore après un déploiement qui garde le contrat', async () => {
        vi.resetModules()
        const avant = await magasinDUneConstruction()
        expect(await avant.ecrireUneCopie(accueil)).toBe('ecrite')
        await avant.fermer()

        await deployer(construction('Un'))
        await deployer(construction('Deux'))
        vi.resetModules()
        const apres = await magasinDUneConstruction()
        const lue = await apres.lireUneCopie('/dashboard')
        await apres.fermer()

        expect(lue?.page.props.recentWorkouts).toEqual([{ id: 3, name: 'Séance du matin' }])
        expect(lue?.page.component).toBe('Dashboard')
    })

    it('ne se lisent plus après un déploiement qui change le contrat, et s’effacent', async () => {
        vi.resetModules()
        const avant = await magasinDUneConstruction()
        expect(await avant.ecrireUneCopie(accueil)).toBe('ecrite')
        await avant.fermer()

        await deployer(construction('Deux'))
        vi.resetModules()
        vi.doMock('@/Utils/pagesGardees', async (importOriginal) => {
            const original = await importOriginal()

            return { ...original, VERSION_DU_CONTRAT: original.VERSION_DU_CONTRAT + 1 }
        })
        const apres = await magasinDUneConstruction()
        const lue = await apres.lireUneCopie('/dashboard')
        await apres.fermer()

        expect(lue).toBeNull()
        expect([...fabrique.courante.enregistrements('gym-tracker-copies', 'pages').keys()]).toEqual([])
    })
})
