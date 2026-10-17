import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { creerUneFabriqueEnMemoire } from './baseIndexeeEnMemoire'
import {
    CANAL_DES_COPIES,
    CLEF_DE_L_ANNONCE,
    MAGASIN_DES_PAGES,
    MAGASIN_DE_L_ETAT,
    NOM_DE_LA_BASE,
    TEMOIN,
    TEMOIN_EN_HTTPS,
    creerLeMagasinDesCopies,
    magasinDesCopies,
    ouvrirLaBaseDuNavigateur,
} from '@/Utils/copiesHorsLigne'
import { CLEF_DU_TITULAIRE_DE_L_APPAREIL } from '@/Utils/historiqueDuCompte'
import { PLAFOND_DES_COPIES_EN_OCTETS, VERSION_DU_CONTRAT } from '@/Utils/pagesGardees'

/**
 * Le magasin des copies hors ligne (#2048, partie D) : chiffrées par la clé
 * du témoin que le serveur pose et efface avec la session, gardées pour un
 * seul compte, et derrière une purge qui fait barrière. La base est celle,
 * en mémoire, de `baseIndexeeEnMemoire.js` (jsdom n'a pas IndexedDB) ; le
 * chiffrement, le `crypto.subtle` de Node 24, que jsdom laisse passer.
 */

/** Une clé de 32 octets tirés au hasard, en base64url, comme le serveur la tire. */
const cleAuHasard = () =>
    btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '')

/**
 * Pose le témoin comme le navigateur le garde : un nom `__Host-` n'est admis
 * que `Secure`, sur `/`.
 *
 * @param {string} cle
 */
const poserLeTemoin = (cle, nom = TEMOIN) => {
    document.cookie = `${nom}=${cle}; path=/${nom === TEMOIN_EN_HTTPS ? '; secure' : ''}`
}

const retirerLeTemoin = () => {
    document.cookie = `${TEMOIN}=; path=/; max-age=0`
    document.cookie = `${TEMOIN_EN_HTTPS}=; path=/; secure; max-age=0`
}

/** @param {number | string} compte */
const poserLeTitulaire = (compte) => localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, `compte:${compte}`)

/**
 * Une page d'Inertia d'un compte, telle qu'une réponse complète la donne.
 *
 * @param {{ compte?: number, url?: string, component?: string, props?: object, deferredProps?: object, seanceEnCours?: number | null, encryptHistory?: boolean }} [options]
 */
const pageDe = ({
    compte = 7,
    url = '/dashboard',
    component = 'Dashboard',
    props = {},
    deferredProps,
    seanceEnCours = null,
    encryptHistory = true,
} = {}) => ({
    component,
    url,
    version: 'version-des-actifs',
    encryptHistory,
    clearHistory: false,
    props: {
        auth: {
            user: {
                id: compte,
                name: 'Camille Horsligne',
                email: 'camille.horsligne@example.org',
                active_workout: seanceEnCours === null ? null : { id: seanceEnCours, name: 'Séance du jour' },
            },
        },
        errors: {},
        ...props,
    },
    ...(deferredProps === undefined ? {} : { deferredProps }),
})

/** La séance `identifiant`, page gardée. */
const pageDeLaSeance = (identifiant, options = {}) =>
    pageDe({ url: `/workouts/${identifiant}`, component: 'Workouts/Show', ...options })

let fabrique
let onglets = []
let instant

/**
 * Un onglet de la page : son magasin, sur la base de l'appareil.
 *
 * @param {object} [moyens]
 */
const ouvrirUnOnglet = (moyens = {}) => {
    const onglet = creerLeMagasinDesCopies({
        ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique),
        horloge: () => instant,
        ...moyens,
    })

    onglets.push(onglet)

    return onglet
}

const copiesRangees = () => fabrique.enregistrements(NOM_DE_LA_BASE, MAGASIN_DES_PAGES)
const cheminsRanges = () => [...copiesRangees().keys()].sort()
const etatRange = () => fabrique.enregistrements(NOM_DE_LA_BASE, MAGASIN_DE_L_ETAT).get('etat')

/**
 * Un chiffrement que le test retient : l'écriture qui chiffre attend que le
 * test la libère, pendant qu'un autre onglet agit.
 */
const chiffrementRetenu = () => {
    let liberer
    const retenue = new Promise((resoudre) => {
        liberer = resoudre
    })
    const appels = []

    return {
        subtle: {
            digest: (...arguments_) => crypto.subtle.digest(...arguments_),
            importKey: (...arguments_) => crypto.subtle.importKey(...arguments_),
            decrypt: (...arguments_) => crypto.subtle.decrypt(...arguments_),
            encrypt: async (...arguments_) => {
                appels.push(arguments_)
                await retenue

                return crypto.subtle.encrypt(...arguments_)
            },
        },
        enCours: () => vi.waitFor(() => expect(appels.length).toBeGreaterThan(0)),
        liberer: () => liberer(),
    }
}

/**
 * Une fenêtre sans `BroadcastChannel`, dont les évènements ne viennent que de
 * `evenements` : un onglet que les annonces des autres n'atteignent pas tant
 * que le test ne les relaie pas.
 *
 * @param {EventTarget} evenements
 */
const fenetreSansCanal = (evenements = new EventTarget()) => ({
    document,
    localStorage,
    addEventListener: (...arguments_) => evenements.addEventListener(...arguments_),
    removeEventListener: (...arguments_) => evenements.removeEventListener(...arguments_),
})

/** Tout ce que la base garde, en texte : ce qu'un autre programme de l'appareil lirait. */
const toutCeQueLaBaseGarde = () =>
    JSON.stringify(
        [...copiesRangees().values(), etatRange()].map((enregistrement) =>
            Object.fromEntries(
                Object.entries(enregistrement ?? {}).map(([cle, valeur]) => [
                    cle,
                    valeur instanceof Uint8Array || Object.prototype.toString.call(valeur) === '[object ArrayBuffer]'
                        ? new TextDecoder('latin1').decode(valeur)
                        : valeur,
                ]),
            ),
        ),
    )

beforeEach(() => {
    fabrique = creerUneFabriqueEnMemoire()
    instant = Date.parse('2026-10-12T18:04:00Z')
    localStorage.clear()
    retirerLeTemoin()
})

afterEach(async () => {
    await Promise.all(onglets.map((onglet) => onglet.fermer()))
    onglets = []
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
    retirerLeTemoin()
})

describe('le chiffrement', () => {
    it('rend la page écrite, et ne garde en clair que le chemin, le composant, les dates et l’empreinte', async () => {
        const cle = cleAuHasard()
        const ziggy = { url: 'https://app.example.org', routes: { 'tools.wilks': { uri: 'tools/wilks' } } }
        const page = pageDe({ props: { recentWorkouts: [{ name: 'Jambes du mardi', notes: 'Genou fragile' }] } })
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cle)
        poserLeTitulaire(7)

        expect(await onglet.ecrireUneCopie(page, { ziggy })).toBe('ecrite')

        const lue = await onglet.lireUneCopie('/dashboard')

        expect(lue.page).toEqual(page)
        expect(lue.ziggy).toEqual(ziggy)
        expect(lue.copieDu).toBe(instant)

        const [copie] = copiesRangees().values()

        expect(Object.keys(copie).sort()).toEqual([
            'chemin',
            'chiffre',
            'composant',
            'copieDu',
            'ecriteLe',
            'empreinte',
            'iv',
        ])
        expect(copie).toMatchObject({
            chemin: '/dashboard',
            composant: 'Dashboard',
            copieDu: instant,
            ecriteLe: instant,
        })
        expect(etatRange()).toEqual({
            ouvert: true,
            compte: '7',
            empreinte: copie.empreinte,
            contrat: VERSION_DU_CONTRAT,
            empreintesFermees: [],
            dernierRejeu: null,
        })

        const garde = toutCeQueLaBaseGarde()

        for (const secret of [
            'Camille',
            'camille.horsligne',
            'Jambes du mardi',
            'Genou',
            'tools/wilks',
            'Séance',
            cle,
        ]) {
            expect(garde).not.toContain(secret)
        }

        expect(Object.values(localStorage).join('\n')).not.toContain(cle)
    })

    it('tire un vecteur d’initialisation neuf pour chaque écriture', async () => {
        const onglet = ouvrirUnOnglet()
        const vecteurs = new Set()
        const chiffres = new Set()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)

        for (const url of ['/dashboard', '/dashboard', '/dashboard']) {
            expect(await onglet.ecrireUneCopie(pageDe({ url }))).toBe('ecrite')

            const copie = copiesRangees().get('/dashboard')

            expect(copie.iv).toHaveLength(12)
            vecteurs.add(copie.iv.join(','))
            chiffres.add(new TextDecoder('latin1').decode(copie.chiffre))
        }

        expect(await onglet.ecrireUneCopie(pageDe({ url: '/tools', component: 'Tools/Index' }))).toBe('ecrite')
        vecteurs.add(copiesRangees().get('/tools').iv.join(','))

        expect(vecteurs.size).toBe(4)
        expect(chiffres.size).toBe(3)
    })

    it('refuse une copie déplacée sous un autre chemin : le chemin est authentifié', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        await onglet.ecrireUneCopie(pageDeLaSeance(4, { seanceEnCours: 4 }))
        await onglet.ecrireUneCopie(pageDeLaSeance(5, { seanceEnCours: 4 }))

        expect((await onglet.lireUneCopie('/workouts/5'))?.page.url).toBe('/workouts/5')

        copiesRangees().set('/workouts/5', { ...copiesRangees().get('/workouts/4'), chemin: '/workouts/5' })

        expect(await onglet.lireUneCopie('/workouts/5')).toBeNull()
        expect((await onglet.lireUneCopie('/workouts/4'))?.page.url).toBe('/workouts/4')
    })

    it('refuse une copie rangée pour un autre compte : le compte est authentifié', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        await onglet.ecrireUneCopie(pageDe())

        etatRange().compte = '8'
        poserLeTitulaire(8)

        expect(await onglet.lireUneCopie('/dashboard')).toBeNull()
    })

    it('refuse une clé fausse, même quand l’état porte son empreinte', async () => {
        const vraie = cleAuHasard()
        const fausse = cleAuHasard()
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(vraie)
        poserLeTitulaire(7)
        await onglet.ecrireUneCopie(pageDe())

        const autreAppareil = creerUneFabriqueEnMemoire()
        const faussaire = ouvrirUnOnglet({ ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(autreAppareil) })

        poserLeTemoin(fausse)
        await faussaire.ecrireUneCopie(pageDe())

        const empreinteDeLaFausse = autreAppareil
            .enregistrements(NOM_DE_LA_BASE, MAGASIN_DE_L_ETAT)
            .get('etat').empreinte

        etatRange().empreinte = empreinteDeLaFausse
        copiesRangees().get('/dashboard').empreinte = empreinteDeLaFausse

        expect(await onglet.lireUneCopie('/dashboard')).toBeNull()
    })
})

describe('la clé du témoin', () => {
    it('sans témoin, ou sous une autre forme, rien ne s’écrit ni ne s’ouvre', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTitulaire(7)

        expect(await onglet.ecrireUneCopie(pageDe())).toBe('sans-cle')

        poserLeTemoin('pas-une-cle')

        expect(await onglet.ecrireUneCopie(pageDe())).toBe('sans-cle')
        expect(await onglet.lireUneCopie('/dashboard')).toBeNull()
        expect(fabrique.ouvertures()).toBe(1)
        expect(copiesRangees().size).toBe(0)
    })

    it('se lit aussi sous son nom réservé à HTTPS', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard(), TEMOIN_EN_HTTPS)
        poserLeTitulaire(7)

        expect(await onglet.ecrireUneCopie(pageDe())).toBe('ecrite')
        expect(await onglet.lireUneCopie('/dashboard')).not.toBeNull()
    })

    it('partie, ne fait plus rien lire, et le magasin se purge en fermant la clé', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        await onglet.ecrireUneCopie(pageDe())

        const empreinte = etatRange().empreinte

        retirerLeTemoin()

        expect(await onglet.lireUneCopie('/dashboard')).toBeNull()
        expect(copiesRangees().size).toBe(0)
        expect(etatRange()).toMatchObject({
            ouvert: false,
            compte: null,
            empreinte: null,
            empreintesFermees: [empreinte],
        })
    })

    it('fermée par la purge, ne fait plus rien écrire ni lire, même reposée par une réponse en vol', async () => {
        const cle = cleAuHasard()
        const premier = ouvrirUnOnglet()
        const second = ouvrirUnOnglet()

        poserLeTemoin(cle)
        poserLeTitulaire(7)
        expect(await premier.ecrireUneCopie(pageDe())).toBe('ecrite')

        await premier.oublierLesCopies({ fermerLeTemoin: true })
        await vi.waitFor(() => expect(second.generationCourante()).toBe(1))
        retirerLeTemoin()
        poserLeTemoin(cle)

        expect(await second.ecrireUneCopie(pageDe())).toBe('cle-fermee')
        expect(await premier.ecrireUneCopie(pageDe())).toBe('cle-fermee')
        expect(await second.lireUneCopie('/dashboard')).toBeNull()
        expect(copiesRangees().size).toBe(0)
        expect(etatRange().ouvert).toBe(false)

        poserLeTemoin(cleAuHasard())

        expect(await second.ecrireUneCopie(pageDe())).toBe('ecrite')
    })

    it('fermée par la déconnexion avant que le magasin lui ait été ouvert, ne l’ouvre jamais', async () => {
        const cle = cleAuHasard()
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cle)
        poserLeTitulaire(7)
        await onglet.oublierLesCopies({ fermerLeTemoin: true })

        expect(await onglet.ecrireUneCopie(pageDe())).toBe('cle-fermee')
    })

    it('changée pendant l’écriture, fait refuser l’écriture dans sa transaction', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)

        const ecriture = onglet.ecrireUneCopie(pageDe())

        poserLeTemoin(cleAuHasard())

        expect(await ecriture).toBe('cle-changee')
        expect(copiesRangees().size).toBe(0)
    })

    it('d’une session plus récente, purge le magasin à la lecture sans fermer l’ancienne', async () => {
        const ancienne = cleAuHasard()
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(ancienne)
        poserLeTitulaire(7)
        await onglet.ecrireUneCopie(pageDe())
        poserLeTemoin(cleAuHasard())

        expect(await onglet.lireUneCopie('/dashboard')).toBeNull()
        expect(copiesRangees().size).toBe(0)
        expect(etatRange()).toMatchObject({ ouvert: false, empreintesFermees: [] })
    })
})

describe('le compte', () => {
    it('ne garde que la page d’un compte, dans un historique chiffré, titulaire de l’appareil', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(8)

        expect(await onglet.ecrireUneCopie(pageDe())).toBe('autre-titulaire')

        poserLeTitulaire(7)

        expect(await onglet.ecrireUneCopie(pageDe({ encryptHistory: false }))).toBe('historique-en-clair')
        expect(await onglet.ecrireUneCopie({ ...pageDe(), props: { auth: { user: null } } })).toBe('sans-compte')
        expect(fabrique.ouvertures()).toBe(0)
    })

    it('change de titulaire pendant l’écriture : l’écriture est refusée dans sa transaction', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)

        const ecriture = onglet.ecrireUneCopie(pageDe())

        poserLeTitulaire(8)

        expect(await ecriture).toBe('autre-titulaire')
        expect(copiesRangees().size).toBe(0)
    })

    it('un autre compte, sous une autre clé, vide d’abord le magasin et ferme l’ancienne clé', async () => {
        const deA = cleAuHasard()
        const premier = ouvrirUnOnglet()
        const second = ouvrirUnOnglet()
        const annonce = vi.fn()

        second.surUnePurge(annonce)
        poserLeTemoin(deA)
        poserLeTitulaire(7)
        await premier.ecrireUneCopie(pageDe())
        await premier.ecrireUneCopie(pageDe({ url: '/tools', component: 'Tools/Index' }))

        const empreinteDeA = etatRange().empreinte

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(8)

        expect(await premier.ecrireUneCopie(pageDe({ compte: 8 }))).toBe('ecrite')
        expect(cheminsRanges()).toEqual(['/dashboard'])
        expect(etatRange()).toMatchObject({ ouvert: true, compte: '8', empreintesFermees: [empreinteDeA] })
        await vi.waitFor(() => expect(annonce).toHaveBeenCalledTimes(1))

        poserLeTemoin(deA)
        poserLeTitulaire(7)

        expect(await premier.ecrireUneCopie(pageDe())).toBe('cle-fermee')
        expect(await premier.lireUneCopie('/dashboard')).toBeNull()
    })

    it('change de titulaire sous la même clé : la lecture purge et ferme la clé', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        await onglet.ecrireUneCopie(pageDe())

        const empreinte = etatRange().empreinte

        poserLeTitulaire(8)

        expect(await onglet.lireUneCopie('/dashboard')).toBeNull()
        expect(etatRange()).toMatchObject({ ouvert: false, empreintesFermees: [empreinte] })
    })
})

describe('la liste blanche', () => {
    it('ne garde ni une page écartée, ni une autre vue d’une page gardée', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)

        expect(await onglet.ecrireUneCopie(pageDe({ url: '/daily-journals', component: 'Journal/Index' }))).toBe(
            'non-gardee',
        )
        expect(await onglet.ecrireUneCopie(pageDe({ url: '/workouts?page=2', component: 'Workouts/Index' }))).toBe(
            'non-gardee',
        )
        expect(await onglet.ecrireUneCopie(pageDe({ url: '/dashboard', component: 'Auth/Login' }))).toBe('non-gardee')
        expect(await onglet.lireUneCopie('https://app.example.org/dashboard')).toBeNull()
        expect(fabrique.ouvertures()).toBe(0)
    })

    it('range l’accueil demandé par la racine sous sa clé normalisée', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)

        expect(await onglet.ecrireUneCopie(pageDe({ url: '/' }))).toBe('ecrite')
        expect(cheminsRanges()).toEqual(['/dashboard'])
        expect(await onglet.lireUneCopie('/')).not.toBeNull()
    })
})

describe('la génération', () => {
    it('refuse une écriture différée décidée avant une purge', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)

        const decidee = onglet.generationCourante()

        await onglet.oublierLesCopies()

        expect(onglet.generationCourante()).toBe(decidee + 1)
        expect(await onglet.ecrireUneCopie(pageDe(), { generation: decidee })).toBe('perimee')
        expect(copiesRangees().size).toBe(0)
        expect(await onglet.ecrireUneCopie(pageDe())).toBe('ecrite')
    })

    it('refuse l’écriture en vol quand la purge part pendant son chiffrement', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)

        const ecriture = onglet.ecrireUneCopie(pageDe())
        const purge = onglet.oublierLesCopies({ fermerLEtat: false })

        expect(await ecriture).toBe('perimee')
        expect(await purge).toBe(true)
        expect(copiesRangees().size).toBe(0)
        expect(etatRange()?.ouvert).toBe(false)
    })
})

describe('l’annonce aux autres onglets', () => {
    it('passe par BroadcastChannel : l’autre onglet coupe ses écritures et son préchauffage', async () => {
        const premier = ouvrirUnOnglet()
        const second = ouvrirUnOnglet()
        const prechauffage = vi.fn()
        const arretDuSuivi = second.surUnePurge(prechauffage)
        const decidee = second.generationCourante()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)

        await premier.oublierLesCopies()
        await vi.waitFor(() => expect(prechauffage).toHaveBeenCalledTimes(1))

        expect(second.generationCourante()).toBe(decidee + 1)
        expect(await second.ecrireUneCopie(pageDe(), { generation: decidee })).toBe('perimee')

        arretDuSuivi()
        await premier.oublierLesCopies()
        await second.oublierLesCopies()

        expect(prechauffage).toHaveBeenCalledTimes(1)
    })

    it('ne prend pour une purge que son annonce', async () => {
        const onglet = ouvrirUnOnglet()
        const intrus = new BroadcastChannel(CANAL_DES_COPIES)
        const prechauffage = vi.fn()

        onglet.surUnePurge(prechauffage)
        intrus.postMessage({ type: 'autre-chose' })
        intrus.postMessage('copies-oubliees')
        intrus.postMessage({ type: 'copies-oubliees' })

        await vi.waitFor(() => expect(prechauffage).toHaveBeenCalledTimes(1))
        intrus.close()

        expect(onglet.generationCourante()).toBe(1)
    })

    it('même perdue, ne laisse pas l’écriture en vol d’un autre onglet ranger une clé fermée', async () => {
        const premier = ouvrirUnOnglet({ fenetre: fenetreSansCanal() })
        const retenu = chiffrementRetenu()
        const second = ouvrirUnOnglet({ fenetre: fenetreSansCanal(), subtle: retenu.subtle })

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        expect(await premier.ecrireUneCopie(pageDe())).toBe('ecrite')

        const ecriture = second.ecrireUneCopie(pageDe({ url: '/tools', component: 'Tools/Index' }))

        await retenu.enCours()
        await premier.oublierLesCopies({ fermerLeTemoin: true })
        retenu.liberer()

        expect(second.generationCourante()).toBe(0)
        expect(await ecriture).toBe('cle-fermee')
        expect(copiesRangees().size).toBe(0)
    })
})

describe('l’écriture d’une réponse partielle en vol', () => {
    it('ne range rien quand le magasin s’est fermé entre-temps', async () => {
        const premier = ouvrirUnOnglet({ fenetre: fenetreSansCanal() })
        const retenu = chiffrementRetenu()
        const second = ouvrirUnOnglet({ fenetre: fenetreSansCanal(), subtle: retenu.subtle })
        const page = pageDe({ props: { recentPRs: [] } })

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        await premier.ecrireUneCopie(page)

        const completion = second.completerUneCopie(page, ['recentPRs'])

        await retenu.enCours()
        await premier.oublierLesCopies({ fermerLEtat: false })
        retenu.liberer()

        expect(await completion).toBe('etat-ferme')
        expect(copiesRangees().size).toBe(0)
    })

    it('ne recouvre pas une copie récrite entre-temps', async () => {
        const premier = ouvrirUnOnglet()
        const retenu = chiffrementRetenu()
        const second = ouvrirUnOnglet({ subtle: retenu.subtle })
        const page = pageDe({ props: { recentPRs: [] } })

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        await premier.ecrireUneCopie(page)

        const completion = second.completerUneCopie({ ...page, props: { ...page.props, recentPRs: [1] } }, [
            'recentPRs',
        ])

        await retenu.enCours()
        instant += 1000
        await premier.ecrireUneCopie({ ...page, props: { ...page.props, recentPRs: [2] } })
        retenu.liberer()

        expect(await completion).toBe('concurrente')
        expect((await premier.lireUneCopie('/dashboard')).page.props.recentPRs).toEqual([2])
    })

    it('passe sans BroadcastChannel par l’évènement storage, que l’autre onglet reçoit', async () => {
        const evenementsDuSecond = new EventTarget()
        const premier = ouvrirUnOnglet({ fenetre: fenetreSansCanal() })
        const second = ouvrirUnOnglet({ fenetre: fenetreSansCanal(evenementsDuSecond) })
        const prechauffage = vi.fn()
        const ecrire = Storage.prototype.setItem
        const relais = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(function (cle, valeur) {
            ecrire.call(this, cle, valeur)
            evenementsDuSecond.dispatchEvent(new StorageEvent('storage', { key: cle, newValue: valeur }))
        })

        second.surUnePurge(prechauffage)

        const decidee = second.generationCourante()

        await premier.oublierLesCopies()
        relais.mockRestore()

        expect(prechauffage).toHaveBeenCalledTimes(1)
        expect(second.generationCourante()).toBe(decidee + 1)
        expect(localStorage.getItem(CLEF_DE_L_ANNONCE)).toBeNull()

        evenementsDuSecond.dispatchEvent(new StorageEvent('storage', { key: CLEF_DE_L_ANNONCE, newValue: null }))
        evenementsDuSecond.dispatchEvent(new StorageEvent('storage', { key: 'autre-chose', newValue: '1' }))

        expect(second.generationCourante()).toBe(decidee + 1)
    })
})

describe('le plafond', () => {
    it('garde la séance en cours et les trois séances closes les plus récentes', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)

        for (const identifiant of [9, 1, 2, 3, 4]) {
            instant += 1000
            expect(await onglet.ecrireUneCopie(pageDeLaSeance(identifiant, { seanceEnCours: 9 }))).toBe('ecrite')
        }

        expect(cheminsRanges()).toEqual(['/workouts/2', '/workouts/3', '/workouts/4', '/workouts/9'])

        instant += 1000
        expect(await onglet.ecrireUneCopie(pageDeLaSeance(1, { seanceEnCours: 9 }))).toBe('ecrite')
        expect(cheminsRanges()).toEqual(['/workouts/1', '/workouts/3', '/workouts/4', '/workouts/9'])
    })

    it('fait sortir la plus ancienne d’abord, jamais la séance en cours', async () => {
        const onglet = ouvrirUnOnglet()
        const lourde = (octets) => ({ volumineux: 'x'.repeat(octets) })

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)

        const ecrire = async (page) => {
            instant += 1000

            return onglet.ecrireUneCopie(page)
        }

        expect(await ecrire(pageDeLaSeance(9, { seanceEnCours: 9, props: lourde(500_000) }))).toBe('ecrite')
        expect(await ecrire(pageDe({ seanceEnCours: 9, props: lourde(450_000) }))).toBe('ecrite')
        expect(
            await ecrire(
                pageDe({ url: '/workouts', component: 'Workouts/Index', seanceEnCours: 9, props: lourde(450_000) }),
            ),
        ).toBe('ecrite')
        expect(cheminsRanges()).toEqual(['/dashboard', '/workouts', '/workouts/9'])

        expect(
            await ecrire(
                pageDe({ url: '/exercises', component: 'Exercises/Index', seanceEnCours: 9, props: lourde(450_000) }),
            ),
        ).toBe('ecrite')
        expect(cheminsRanges()).toEqual(['/exercises', '/workouts', '/workouts/9'])

        const volume = [...copiesRangees().values()].reduce(
            (total, copie) => total + copie.chiffre.byteLength + copie.iv.byteLength,
            0,
        )

        expect(volume).toBeLessThanOrEqual(PLAFOND_DES_COPIES_EN_OCTETS)
    })

    it('refuse une copie qui dépasse seule le plafond', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        await onglet.ecrireUneCopie(pageDe({ url: '/tools', component: 'Tools/Index' }))

        expect(
            await onglet.ecrireUneCopie(pageDe({ props: { volumineux: 'x'.repeat(PLAFOND_DES_COPIES_EN_OCTETS) } })),
        ).toBe('trop-grande')
        expect(cheminsRanges()).toEqual(['/tools'])
    })

    it('efface une copie de plus de sept jours, qui ne se lit déjà plus', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        await onglet.ecrireUneCopie(pageDe())

        instant += 7 * 24 * 60 * 60 * 1000

        expect(await onglet.lireUneCopie('/dashboard')).not.toBeNull()

        instant += 1

        expect(await onglet.lireUneCopie('/dashboard')).toBeNull()

        await onglet.ecrireUneCopie(pageDe({ url: '/tools', component: 'Tools/Index' }))

        expect(cheminsRanges()).toEqual(['/tools'])
    })
})

describe('les réponses partielles et les props différées', () => {
    it('ne versent que leurs props, sans toucher à auth, et gardent la date de chacune', async () => {
        const onglet = ouvrirUnOnglet()
        const complete = pageDe({ deferredProps: { default: ['analyticalStats'] }, props: { recentPRs: [] } })

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)

        expect(await onglet.completerUneCopie(complete, ['recentPRs'])).toBe('sans-copie')
        expect(await onglet.ecrireUneCopie(complete)).toBe('ecrite')

        const ecriteA = instant

        instant += 60_000

        const partielle = {
            ...complete,
            props: {
                ...complete.props,
                auth: { user: { ...complete.props.auth.user, name: 'Autre nom' } },
                analyticalStats: { weeklyVolume: 1200 },
            },
        }

        expect(await onglet.completerUneCopie(partielle, ['analyticalStats', 'auth'])).toBe('ecrite')
        expect(await onglet.completerUneCopie(partielle, ['auth'])).toBe('sans-props')

        const lue = await onglet.lireUneCopie('/dashboard')

        expect(lue.page.props.analyticalStats).toEqual({ weeklyVolume: 1200 })
        expect(lue.page.props.auth.user.name).toBe('Camille Horsligne')
        expect(lue.datesDesProps).toMatchObject({
            auth: ecriteA,
            recentPRs: ecriteA,
            analyticalStats: ecriteA + 60_000,
        })
        expect(lue).toMatchObject({ copieDu: ecriteA, ecriteLe: ecriteA + 60_000 })

        instant += 60_000

        expect(await onglet.ecrireUneCopie(complete)).toBe('ecrite')

        const relue = await onglet.lireUneCopie('/dashboard')

        expect(relue.page.props.analyticalStats).toEqual({ weeklyVolume: 1200 })
        expect(relue.datesDesProps.analyticalStats).toBe(ecriteA + 60_000)
        expect(relue.copieDu).toBe(ecriteA + 120_000)
    })

    it('ne rouvrent pas un magasin fermé', async () => {
        const onglet = ouvrirUnOnglet()
        const page = pageDe({ props: { recentPRs: [] } })

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        await onglet.ecrireUneCopie(page)
        await onglet.oublierLesCopies({ fermerLEtat: false })

        expect(await onglet.completerUneCopie(page, ['recentPRs'])).toBe('sans-copie')
        expect(etatRange().ouvert).toBe(false)
    })
})

describe('le contrat', () => {
    it('d’une autre version fait oublier les copies, sans fermer la clé', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        await onglet.ecrireUneCopie(pageDe())

        etatRange().contrat = VERSION_DU_CONTRAT + 1

        expect(await onglet.lireUneCopie('/dashboard')).toBeNull()
        expect(copiesRangees().size).toBe(0)
        expect(etatRange()).toMatchObject({ ouvert: false, empreintesFermees: [] })
        expect(await onglet.ecrireUneCopie(pageDe())).toBe('ecrite')
    })

    it('d’une autre version, à l’écriture, vide le magasin et le rouvre sous la même clé', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        await onglet.ecrireUneCopie(pageDe({ url: '/tools', component: 'Tools/Index' }))

        etatRange().contrat = VERSION_DU_CONTRAT - 1

        expect(await onglet.ecrireUneCopie(pageDe())).toBe('ecrite')
        expect(cheminsRanges()).toEqual(['/dashboard'])
        expect(etatRange()).toMatchObject({ ouvert: true, contrat: VERSION_DU_CONTRAT, empreintesFermees: [] })
        expect(await onglet.ecrireUneCopie(pageDe())).toBe('ecrite')
    })
})

describe('l’hygiène du chargement', () => {
    it('efface les copies de plus de sept jours, et garde celles de sept jours tout juste', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        await onglet.ecrireUneCopie(pageDe())
        instant += 1
        await onglet.ecrireUneCopie(pageDe({ url: '/tools', component: 'Tools/Index' }))

        instant += 7 * 24 * 60 * 60 * 1000

        expect(await onglet.entretenir()).toBe(1)
        expect(cheminsRanges()).toEqual(['/tools'])
        expect(etatRange()).toMatchObject({ ouvert: true, compte: '7' })

        instant += 1

        expect(await onglet.entretenir()).toBe(1)
        expect(cheminsRanges()).toEqual([])
    })

    it('vide et ferme un magasin d’un autre contrat, sans fermer sa clé', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        await onglet.ecrireUneCopie(pageDe())
        await onglet.ecrireUneCopie(pageDe({ url: '/tools', component: 'Tools/Index' }))
        etatRange().contrat = VERSION_DU_CONTRAT + 1

        expect(await onglet.entretenir()).toBe(2)
        expect(copiesRangees().size).toBe(0)
        expect(etatRange()).toMatchObject({ ouvert: false, contrat: VERSION_DU_CONTRAT, empreintesFermees: [] })

        // La session continue : la réponse complète suivante rouvre le magasin, sous ce contrat.
        expect(await onglet.ecrireUneCopie(pageDe())).toBe('ecrite')
    })

    it('ne touche à rien de récent, ni à un magasin vide', async () => {
        const onglet = ouvrirUnOnglet()

        expect(await onglet.entretenir()).toBe(0)

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        await onglet.ecrireUneCopie(pageDe())

        expect(await onglet.entretenir()).toBe(0)
        expect(cheminsRanges()).toEqual(['/dashboard'])
    })
})

describe('le changement de titulaire de l’appareil', () => {
    it('oublie les copies d’un autre compte que le titulaire, et ferme leur clé', async () => {
        const onglet = ouvrirUnOnglet()
        const prevenus = vi.fn()

        onglet.surUnePurge(prevenus)
        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        await onglet.ecrireUneCopie(pageDe())
        const empreinte = etatRange().empreinte

        // Un invité, ou un autre compte, a reçu une page dans un onglet.
        localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, 'invite')

        expect(await onglet.oublierLesCopiesDUnAutreTitulaire()).toBe(true)
        expect(copiesRangees().size).toBe(0)
        expect(etatRange()).toMatchObject({ ouvert: false, compte: null, empreintesFermees: [empreinte] })
        expect(prevenus).toHaveBeenCalledTimes(1)
    })

    it('ne touche pas au magasin du titulaire, ni à un magasin fermé', async () => {
        const onglet = ouvrirUnOnglet()

        expect(await onglet.oublierLesCopiesDUnAutreTitulaire()).toBe(false)

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        await onglet.ecrireUneCopie(pageDe())

        expect(await onglet.oublierLesCopiesDUnAutreTitulaire()).toBe(false)
        expect(cheminsRanges()).toEqual(['/dashboard'])

        await onglet.oublierLesCopies()
        poserLeTitulaire(8)

        expect(await onglet.oublierLesCopiesDUnAutreTitulaire()).toBe(false)
    })

    /*
     * L'onglet qui reçoit la page du compte suivant ouvre le magasin pour
     * lui ; un autre onglet apprend le changement après coup, par l'évènement
     * `storage`. Fermer alors la clé de l'état fermerait celle du compte
     * suivant, privé de copies jusqu'au terme de sa session.
     */
    it('appris après coup par un autre onglet, ne ferme pas la clé du compte suivant', async () => {
        const premier = ouvrirUnOnglet()
        const second = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        await premier.ecrireUneCopie(pageDe())

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(8)
        expect(await premier.oublierLesCopiesDUnAutreTitulaire()).toBe(true)
        expect(await premier.ecrireUneCopie(pageDe({ compte: 8 }))).toBe('ecrite')

        expect(await second.oublierLesCopiesDUnAutreTitulaire()).toBe(false)
        expect(etatRange()).toMatchObject({ ouvert: true, compte: '8' })
        expect((await second.lireUneCopie('/dashboard')).page.props.auth.user.id).toBe(8)
    })

    it('sans crypto.subtle, n’ouvre même pas la base', async () => {
        const onglet = ouvrirUnOnglet({ subtle: null })

        expect(await onglet.oublierLesCopiesDUnAutreTitulaire()).toBe(false)
        expect(await onglet.entretenir()).toBe(0)
        expect(fabrique.ouvertures()).toBe(0)
    })
})

describe('sans les moyens du chiffrement ou de la base', () => {
    it('sans crypto.subtle, rien ne s’écrit ni ne se lit, sans erreur', async () => {
        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)
        vi.stubGlobal('crypto', { getRandomValues: (octets) => octets })

        const onglet = ouvrirUnOnglet()

        await expect(onglet.ecrireUneCopie(pageDe())).resolves.toBe('indisponible')
        await expect(onglet.completerUneCopie(pageDe(), ['errors'])).resolves.toBe('indisponible')
        await expect(onglet.lireUneCopie('/dashboard')).resolves.toBeNull()
        await expect(onglet.oublierLesCopies({ fermerLeTemoin: true })).resolves.toBe(true)
        expect(copiesRangees().size).toBe(0)
    })

    it('sans IndexedDB, comme sous jsdom, rien ne s’écrit ni ne se lit, sans erreur', async () => {
        const magasin = magasinDesCopies()

        expect(magasinDesCopies()).toBe(magasin)
        expect(globalThis.indexedDB).toBeUndefined()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)

        await expect(magasin.ecrireUneCopie(pageDe())).resolves.toBe('indisponible')
        await expect(magasin.lireUneCopie('/dashboard')).resolves.toBeNull()
        await expect(magasin.oublierLesCopies()).resolves.toBe(false)
        await magasin.fermer()
    })

    /*
     * La déconnexion et la suppression du compte oublient les copies avant
     * leur envoi : un magasin qui ne pourrait pas même se créer ne doit pas
     * les empêcher de partir.
     */
    it('la page oublie sans jamais lever, même quand son magasin ne peut pas se créer', async () => {
        vi.resetModules()
        vi.stubGlobal(
            'TextEncoder',
            class {
                constructor() {
                    throw new Error('Un navigateur qui refuse')
                }
            },
        )
        const { oublierLesCopies, oublierLesCopiesDUnAutreTitulaire } = await import('@/Utils/copiesHorsLigne')

        expect(() => oublierLesCopies({ fermerLeTemoin: true })).not.toThrow()
        await expect(oublierLesCopies({ fermerLeTemoin: true })).resolves.toBe(false)
        await expect(oublierLesCopiesDUnAutreTitulaire()).resolves.toBe(false)
    })

    it('quand le cookie ou le stockage sont refusés, rien ne s’écrit, sans erreur', async () => {
        const refuse = () => {
            throw new DOMException('Refusé', 'SecurityError')
        }
        const sansCookie = ouvrirUnOnglet({
            fenetre: {
                document: {
                    get cookie() {
                        return refuse()
                    },
                },
                localStorage,
            },
        })
        const sansStockage = ouvrirUnOnglet({
            fenetre: {
                document,
                get localStorage() {
                    return refuse()
                },
            },
        })

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)

        expect(await sansCookie.ecrireUneCopie(pageDe())).toBe('sans-cle')
        expect(await sansStockage.ecrireUneCopie(pageDe())).toBe('autre-titulaire')
        await expect(sansStockage.oublierLesCopies()).resolves.toBe(true)
    })

    it('une page qui ne se lit pas en JSON ne fait rien écrire', async () => {
        const onglet = ouvrirUnOnglet()

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)

        expect(await onglet.ecrireUneCopie(pageDe({ props: { compteur: 10n } }))).toBe('erreur')
        expect(copiesRangees().size).toBe(0)
    })

    it('quand IndexedDB refuse de s’ouvrir, rien ne s’écrit, et l’ouverture se retente', async () => {
        let refus = 0
        const onglet = ouvrirUnOnglet({
            ouvrirLaBase: () => {
                refus += 1

                return ouvrirLaBaseDuNavigateur({
                    open: () => {
                        throw new DOMException('Refusé', 'SecurityError')
                    },
                })
            },
        })

        poserLeTemoin(cleAuHasard())
        poserLeTitulaire(7)

        expect(await onglet.ecrireUneCopie(pageDe())).toBe('indisponible')
        expect(await onglet.ecrireUneCopie(pageDe())).toBe('indisponible')
        expect(refus).toBe(2)
    })
})

describe('la base en mémoire des tests', () => {
    it('refuse une requête faite après avoir attendu autre chose, comme un navigateur', async () => {
        const base = await ouvrirLaBaseDuNavigateur(fabrique)
        const transaction = base.transaction([MAGASIN_DES_PAGES], 'readwrite')

        await new Promise((resoudre) => setTimeout(resoudre, 0))

        expect(() => transaction.objectStore(MAGASIN_DES_PAGES).put({ chemin: '/dashboard' })).toThrow(
            expect.objectContaining({ name: 'TransactionInactiveError' }),
        )
    })

    it('annule tout ce qu’une transaction avait écrit', async () => {
        const base = await ouvrirLaBaseDuNavigateur(fabrique)
        const transaction = base.transaction([MAGASIN_DES_PAGES], 'readwrite')
        const pages = transaction.objectStore(MAGASIN_DES_PAGES)

        pages.put({ chemin: '/dashboard' }).onsuccess = () => transaction.abort()

        await new Promise((resoudre) => {
            transaction.onabort = resoudre
        })

        expect(copiesRangees().size).toBe(0)
    })
})
