import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { creerUneFabriqueEnMemoire } from './baseIndexeeEnMemoire'
import { envelopperLaCoquille } from '@/sw/coquilleServie'
import { AVANT_LA_SALLE, pageHorsLigne } from '@/sw/pageHorsLigne'
import {
    MAGASIN_DES_PAGES,
    MAGASIN_DE_L_ETAT,
    NOM_DE_LA_BASE,
    TEMOIN,
    creerLeMagasinDesCopies,
    ouvrirLaBaseDuNavigateur,
} from '@/Utils/copiesHorsLigne'
import { COMPOSANT_HORS_LIGNE, demarrerHorsLigne, pagesSansReseau } from '@/Utils/demarrageHorsLigne'
import { CLEF_DU_TITULAIRE_DE_L_APPAREIL } from '@/Utils/historiqueDuCompte'
import { AGE_MAXIMAL_D_UNE_COPIE_MS } from '@/Utils/pagesGardees'
import { noterQueLeServeurRepond, useEtatDuReseau } from '@/composables/useEtatDuReseau'

/*
 * Le démarrage dans la coquille hors ligne, depuis le vrai magasin des copies
 * sur une base en mémoire (#2048, partie F) : ce qui se monte, et ce qui ne se
 * monte jamais. Chaque vérification qui échoue purge le magasin et laisse le
 * contenu statique de la coquille tel que le worker l'a servi.
 */

const COMPTE = 7

/** La table des routes que l'écriture garde avec chaque copie. */
const routesDe = (marque) => ({
    url: 'http://localhost:3000',
    port: 3000,
    defaults: {},
    routes: { marque: { uri: marque } },
})

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

const retirerLeTemoin = () => {
    document.cookie = `${TEMOIN}=; path=/; max-age=0`
}

/** @param {number} compte */
const poserLeTitulaire = (compte) => localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, `compte:${compte}`)

/**
 * Une page d'Inertia du compte, telle qu'une réponse complète la donne.
 *
 * @param {{ compte?: number, url?: string, component?: string, props?: object, nom?: string, seanceEnCours?: number | null }} [options]
 */
const pageDe = ({
    compte = COMPTE,
    url = '/dashboard',
    component = 'Dashboard',
    props = {},
    nom = 'Camille Horsligne',
    seanceEnCours = null,
} = {}) => ({
    component,
    url,
    version: 'version-des-actifs',
    encryptHistory: true,
    clearHistory: false,
    sharedProps: ['auth', 'flash', 'is_testing'],
    props: {
        errors: {},
        auth: {
            user: {
                id: compte,
                name: nom,
                active_workout: seanceEnCours === null ? null : { id: seanceEnCours, name: 'Séance du jour' },
            },
        },
        flash: { success: null, error: null },
        is_testing: false,
        ...props,
    },
})

/** La séance `identifiant`, page gardée. */
const pageDeLaSeance = (identifiant, options = {}) =>
    pageDe({
        url: `/workouts/${identifiant}`,
        component: 'Workouts/Show',
        props: { workout: { id: identifiant, name: `Séance ${identifiant}`, notes: null } },
        ...options,
    })

let fabrique
let magasin
let instant

const copiesRangees = () => fabrique.enregistrements(NOM_DE_LA_BASE, MAGASIN_DES_PAGES)
const etatRange = () => fabrique.enregistrements(NOM_DE_LA_BASE, MAGASIN_DE_L_ETAT).get('etat')

/**
 * Écrit une copie à l'instant `quand`, avec sa table des routes.
 *
 * @param {object} page
 * @param {{ quand?: number, routes?: object | null }} [options]
 */
const ecrire = async (page, { quand = instant, routes = routesDe(page.url) } = {}) => {
    instant = quand

    expect(await magasin.ecrireUneCopie(page, { ziggy: routes })).toBe('ecrite')
}

/**
 * La coquille que le worker sert à `adresse`, sur une fenêtre à elle, puis son
 * démarrage depuis le magasin du test.
 *
 * @param {string} adresse
 * @param {string} [raison]
 */
const demarrer = async (adresse, raison = 'reseau') => {
    const precachee = new Response(
        pageHorsLigne({ feuilles: ['/build/assets/main-Cd34.css'], moduleDeLEntree: '/build/assets/main-Ab12.js' }),
    )
    const document = new DOMParser().parseFromString(
        await (await envelopperLaCoquille(precachee, raison)).text(),
        'text/html',
    )
    const fenetre = Object.assign(new EventTarget(), { document, location: new URL(adresse, 'http://localhost:3000') })
    const corpsStatique = document.body.innerHTML
    const recharger = vi.fn()
    const resultat = await demarrerHorsLigne({ fenetre, magasin: () => magasin, recharger, attendre: vi.fn() })
    const donnees = document.querySelector('script[data-page="app"]')

    return {
        fenetre,
        resultat,
        recharger,
        corpsStatique,
        page: donnees === null ? null : JSON.parse(donnees.textContent),
    }
}

/**
 * Ce que montre une coquille laissée telle quelle : son corps intact, aucune
 * page écrite, et le rechargement au retour du réseau.
 *
 * @param {Awaited<ReturnType<typeof demarrer>>} demarrage
 */
const attendreUneCoquilleStatique = (demarrage) => {
    expect(demarrage.resultat.montee).toBe(false)
    expect(demarrage.page).toBeNull()
    expect(demarrage.fenetre.document.body.innerHTML).toBe(demarrage.corpsStatique)
    expect(demarrage.fenetre.document.querySelector('#app')).toBeNull()
    expect(demarrage.fenetre.document.body.textContent).toContain(AVANT_LA_SALLE)
    expect(demarrage.fenetre.Ziggy).toBeUndefined()

    demarrage.fenetre.dispatchEvent(new Event('online'))

    expect(demarrage.recharger).toHaveBeenCalledTimes(1)
}

beforeEach(() => {
    fabrique = creerUneFabriqueEnMemoire()
    instant = Date.UTC(2026, 9, 19, 16, 0)
    magasin = creerLeMagasinDesCopies({
        ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique),
        horloge: () => instant,
    })
    poserLeTemoin(cleAuHasard())
    poserLeTitulaire(COMPTE)
    noterQueLeServeurRepond()
})

afterEach(async () => {
    await magasin.fermer()
    retirerLeTemoin()
    localStorage.clear()
})

describe('une adresse qui a sa copie', () => {
    it('monte la copie : la page écrite par textContent, l’historique chiffré, le auth le plus récent, la marque', async () => {
        const debut = instant

        await ecrire(pageDeLaSeance(12, { nom: 'Ancien nom', seanceEnCours: 12 }), { quand: debut })
        await ecrire(pageDe({ nom: 'Nom le plus récent', seanceEnCours: 12 }), { quand: debut + 60_000 })

        const { resultat, page, fenetre } = await demarrer('/workouts/12')

        expect(resultat).toMatchObject({ montee: true, raison: 'copie' })
        expect(page.component).toBe('Workouts/Show')
        expect(page.url).toBe('/workouts/12')
        expect(page.version).toBe('version-des-actifs')
        expect(page.encryptHistory).toBe(true)
        expect(page.clearHistory).toBe(false)
        expect(page.props.workout).toEqual({ id: 12, name: 'Séance 12', notes: null })
        expect(page.props.auth.user.name).toBe('Nom le plus récent')
        expect(page.props.horsLigne).toMatchObject({ copieDu: debut, raison: 'reseau' })
        expect(page.props.errors).toEqual({})
        expect(page.flash).toEqual({})
        expect(page.deferredProps).toBeUndefined()

        // La table des routes de la copie elle-même, et #app à la place de <main>.
        expect(fenetre.Ziggy).toEqual(routesDe('/workouts/12'))
        expect(fenetre.document.querySelector('main')).toBeNull()
        expect(fenetre.document.querySelector('#app')).not.toBeNull()
        expect(fenetre.document.querySelector('script[data-page="app"]').nextElementSibling.id).toBe('app')
        expect(fenetre.document.querySelector('script[data-page="app"]').getAttribute('type')).toBe('application/json')

        // Une copie montrée : le serveur ne répond pas, la sonde le redemandera.
        expect(useEtatDuReseau().joignable.value).toBe(false)
    })

    it('au délai, marque la copie « lent », et `/` monte l’accueil', async () => {
        await ecrire(pageDe())

        const { resultat, page } = await demarrer('/', 'delai')

        expect(resultat.montee).toBe(true)
        expect(page.component).toBe('Dashboard')
        expect(page.url).toBe('/dashboard')
        expect(page.props.horsLigne.raison).toBe('lent')
    })

    /*
     * Un `</script>`, un `<!--` ou un U+2028 dans une note de séance : la page
     * s'écrit par `textContent`, jamais par du HTML, et ce ne sont que des
     * données.
     */
    it('garde comme données un </script>, un <!-- et un U+2028 d’une note de séance', async () => {
        const note = 'Fin</script><script>window.piratee = true</script><!-- reste  après -->'

        await ecrire(
            pageDeLaSeance(12, {
                props: { workout: { id: 12, name: 'Séance 12', notes: note } },
            }),
        )

        const { page, fenetre } = await demarrer('/workouts/12')

        expect(page.props.workout.notes).toBe(note)
        expect(fenetre.document.querySelectorAll('script')).toHaveLength(2)
        expect(fenetre.document.querySelectorAll('script:not([src])')).toHaveLength(1)
        expect(fenetre.document.querySelector('script[data-page="app"]').textContent).toContain('</script><script>')
        expect(window.piratee).toBeUndefined()
        expect(fenetre.piratee).toBeUndefined()
    })
})

describe('une adresse sans copie', () => {
    it('monte la page HorsLigne : ce qui s’ouvre sans réseau, avec les props partagées de la copie la plus récente', async () => {
        const debut = instant

        await ecrire(pageDeLaSeance(9, { seanceEnCours: 12 }), { quand: debut })
        await ecrire(pageDeLaSeance(12, { seanceEnCours: 12 }), { quand: debut + 1_000 })
        await ecrire(pageDe({ url: '/tools', component: 'Tools/Index', seanceEnCours: 12 }), { quand: debut + 2_000 })
        await ecrire(pageDe({ nom: 'Le plus récent', seanceEnCours: 12 }), { quand: debut + 3_000 })

        const { resultat, page, fenetre } = await demarrer('/stats?periode=mois')

        expect(resultat).toMatchObject({ montee: true, raison: COMPOSANT_HORS_LIGNE })
        expect(page.component).toBe('HorsLigne')
        expect(page.url).toBe('/stats?periode=mois')
        expect(page.encryptHistory).toBe(true)
        expect(page.props.auth.user.name).toBe('Le plus récent')
        expect(page.props.flash).toEqual({ success: null, error: null })
        expect(page.props.is_testing).toBe(false)
        expect(page.props.horsLigne).toMatchObject({ copieDu: debut + 3_000, raison: 'reseau' })
        expect(page.props.pagesSansReseau.map(({ chemin, enCours }) => [chemin, enCours])).toEqual([
            ['/dashboard', false],
            ['/workouts/12', true],
            ['/workouts/9', false],
            ['/tools', false],
        ])
        expect(fenetre.Ziggy).toEqual(routesDe('/dashboard'))
    })

    it('prend le auth et les routes de la copie la plus récente, quel que soit son chemin', async () => {
        const debut = instant

        await ecrire(pageDe({ nom: 'Ancien nom', seanceEnCours: 12 }), { quand: debut })
        await ecrire(pageDeLaSeance(12, { nom: 'Nom le plus récent', seanceEnCours: 12 }), { quand: debut + 1_000 })

        const { page, fenetre } = await demarrer('/stats')

        expect(page.props.auth.user.name).toBe('Nom le plus récent')
        expect(page.props.horsLigne.copieDu).toBe(debut + 1_000)
        expect(fenetre.Ziggy).toEqual(routesDe('/workouts/12'))
    })

    it('monte aussi la page HorsLigne à une adresse gardée jamais ouverte', async () => {
        await ecrire(pageDe())

        const { page } = await demarrer('/tools/1rm', 'delai')

        expect(page.component).toBe('HorsLigne')
        expect(page.props.horsLigne.raison).toBe('lent')
        expect(page.props.pagesSansReseau.map(({ chemin }) => chemin)).toEqual(['/dashboard'])
    })

    it('laisse la coquille telle quelle sans aucune copie lisible, sans rien purger', async () => {
        await ecrire(pageDe(), { quand: instant })
        instant += AGE_MAXIMAL_D_UNE_COPIE_MS + 1

        const demarrage = await demarrer('/dashboard')

        expect(demarrage.resultat.raison).toBe('sans-copie')
        attendreUneCoquilleStatique(demarrage)
        expect(copiesRangees().size).toBe(1)
        expect(etatRange().ouvert).toBe(true)
    })

    it('laisse la coquille telle quelle sans la table des routes, sans rien purger', async () => {
        await ecrire(pageDe(), { routes: null })

        const demarrage = await demarrer('/dashboard')

        expect(demarrage.resultat.raison).toBe('sans-routes')
        attendreUneCoquilleStatique(demarrage)
        expect(copiesRangees().size).toBe(1)
    })

    it('range ce qui s’ouvre sans réseau dans l’ordre de la liste blanche, la séance en cours d’abord', () => {
        expect(
            pagesSansReseau(
                [
                    { chemin: '/tools/1rm', composant: 'Tools/OneRepMax', copieDu: 5 },
                    { chemin: '/workouts/3', composant: 'Workouts/Show', copieDu: 9 },
                    { chemin: '/workouts/4', composant: 'Workouts/Show', copieDu: 1 },
                    { chemin: '/workouts/5', composant: 'Workouts/Show', copieDu: 7 },
                    { chemin: '/dashboard', composant: 'Dashboard', copieDu: 2 },
                ],
                '/workouts/4',
            ).map(({ chemin }) => chemin),
        ).toEqual(['/dashboard', '/workouts/4', '/workouts/3', '/workouts/5', '/tools/1rm'])
    })
})

describe('chaque vérification qui échoue purge, et laisse le contenu statique', () => {
    it('sans témoin : la clé de l’état se ferme', async () => {
        await ecrire(pageDe())

        const empreinte = etatRange().empreinte

        retirerLeTemoin()

        const demarrage = await demarrer('/dashboard')

        expect(demarrage.resultat.raison).toBe('sans-cle')
        attendreUneCoquilleStatique(demarrage)
        expect(copiesRangees().size).toBe(0)
        expect(etatRange()).toMatchObject({ ouvert: false })
        expect(etatRange().empreintesFermees).toContain(empreinte)
    })

    it('sous une autre clé : la copie d’une autre session ne se lit pas', async () => {
        await ecrire(pageDe())
        poserLeTemoin(cleAuHasard())

        const demarrage = await demarrer('/dashboard')

        expect(demarrage.resultat.raison).toBe('autre-cle')
        attendreUneCoquilleStatique(demarrage)
        expect(copiesRangees().size).toBe(0)
    })

    /*
     * Une clé fermée, que la réponse en vol d'un autre onglet aurait reposée,
     * ne fait jamais rien monter, même quand le magasin est ouvert pour une
     * autre.
     */
    it('sous une clé fermée : rien ne se monte, même le magasin ouvert pour une autre clé', async () => {
        const fermee = cleAuHasard()

        poserLeTemoin(fermee)
        await ecrire(pageDe())
        await magasin.oublierLesCopies({ fermerLeTemoin: true })
        poserLeTemoin(cleAuHasard())
        await ecrire(pageDe())
        poserLeTemoin(fermee)

        const demarrage = await demarrer('/dashboard')

        expect(demarrage.resultat.raison).toBe('cle-fermee')
        attendreUneCoquilleStatique(demarrage)
        expect(copiesRangees().size).toBe(0)
    })

    it('un autre titulaire de l’appareil : les copies du compte précédent ne se montent pas', async () => {
        await ecrire(pageDe())
        poserLeTitulaire(COMPTE + 1)

        const demarrage = await demarrer('/dashboard')

        expect(demarrage.resultat.raison).toBe('autre-titulaire')
        attendreUneCoquilleStatique(demarrage)
        expect(copiesRangees().size).toBe(0)
        expect(etatRange().ouvert).toBe(false)
    })

    /*
     * Les données authentifiées du chiffrement nomment le compte : une copie
     * rangée pour un autre compte, sous la même clé, ne se déchiffre pas pour
     * celui de l'état, même quand l'état est falsifié pour le croire.
     */
    it('une copie d’un autre compte, sous la même clé, ne se monte jamais', async () => {
        poserLeTitulaire(COMPTE + 1)
        await ecrire(pageDe({ compte: COMPTE + 1, nom: 'Autre compte' }))
        poserLeTitulaire(COMPTE)
        etatRange().compte = String(COMPTE)

        const demarrage = await demarrer('/dashboard')

        expect(demarrage.resultat.raison).toBe('illisible')
        attendreUneCoquilleStatique(demarrage)
        expect(demarrage.fenetre.document.body.textContent).not.toContain('Autre compte')
        expect(copiesRangees().size).toBe(0)
    })

    it('une copie déplacée sous un autre chemin ne se monte pas', async () => {
        await ecrire(pageDe({ url: '/tools', component: 'Tools/Index' }))

        const deplacee = { ...copiesRangees().get('/tools'), chemin: '/dashboard', composant: 'Dashboard' }

        copiesRangees().delete('/tools')
        copiesRangees().set('/dashboard', deplacee)

        const demarrage = await demarrer('/dashboard')

        expect(demarrage.resultat.raison).toBe('illisible')
        attendreUneCoquilleStatique(demarrage)
        expect(copiesRangees().size).toBe(0)
    })

    it('un autre contrat : l’état se vide sans fermer sa clé', async () => {
        await ecrire(pageDe())

        const empreinte = etatRange().empreinte

        etatRange().contrat = 99

        const demarrage = await demarrer('/dashboard')

        expect(demarrage.resultat.raison).toBe('autre-contrat')
        attendreUneCoquilleStatique(demarrage)
        expect(copiesRangees().size).toBe(0)
        expect(etatRange().empreintesFermees).not.toContain(empreinte)
    })

    it('un magasin fermé, après une déconnexion : rien ne se monte', async () => {
        await ecrire(pageDe())
        await magasin.oublierLesCopies({ fermerLeTemoin: true })

        const demarrage = await demarrer('/dashboard')

        expect(demarrage.resultat.raison).toBe('ferme')
        attendreUneCoquilleStatique(demarrage)
        expect(copiesRangees().size).toBe(0)
    })

    it('un magasin qui ne s’ouvre pas : rien ne se monte, et rien ne lève', async () => {
        await magasin.fermer()
        magasin = creerLeMagasinDesCopies({ ouvrirLaBase: () => Promise.reject(new Error('IndexedDB refusé')) })

        const demarrage = await demarrer('/dashboard')

        expect(demarrage.resultat.raison).toBe('indisponible')
        attendreUneCoquilleStatique(demarrage)
    })

    it('sans crypto.subtle : rien ne se monte, et rien ne lève', async () => {
        await ecrire(pageDe())
        await magasin.fermer()
        magasin = creerLeMagasinDesCopies({ ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique), subtle: null })

        const demarrage = await demarrer('/dashboard')

        expect(demarrage.resultat.raison).toBe('indisponible')
        attendreUneCoquilleStatique(demarrage)
    })

    /*
     * Une purge annoncée pendant que la copie se déchiffre (une déconnexion
     * dans un autre onglet, le 401 d'un rejeu) ne laisse rien monter de ce
     * qui se lisait avant elle.
     */
    it('une purge pendant le déchiffrement ne laisse rien monter', async () => {
        await ecrire(pageDe())
        await magasin.fermer()

        let liberer
        const retenue = new Promise((resoudre) => {
            liberer = resoudre
        })
        let dechiffrements = 0

        magasin = creerLeMagasinDesCopies({
            ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique),
            horloge: () => instant,
            subtle: {
                digest: (...arguments_) => crypto.subtle.digest(...arguments_),
                importKey: (...arguments_) => crypto.subtle.importKey(...arguments_),
                encrypt: (...arguments_) => crypto.subtle.encrypt(...arguments_),
                decrypt: async (...arguments_) => {
                    dechiffrements += 1
                    await retenue

                    return crypto.subtle.decrypt(...arguments_)
                },
            },
        })

        const enCours = demarrer('/dashboard')

        await vi.waitUntil(() => dechiffrements > 0)
        await magasin.oublierLesCopies()
        liberer()

        const demarrage = await enCours

        expect(demarrage.resultat.raison).toBe('perimee')
        attendreUneCoquilleStatique(demarrage)
        expect(copiesRangees().size).toBe(0)
    })
})
