import { describe, it, expect, vi, beforeAll, beforeEach, afterEach, afterAll } from 'vitest'
import { createApp, h } from 'vue'

import { creerUneFabriqueEnMemoire } from '../utils/baseIndexeeEnMemoire'
import { CLEF_DU_TITULAIRE_DE_L_APPAREIL } from '@/Utils/historiqueDuCompte'
import {
    RequeteSimulee,
    relayerLesRejetsNonGeres,
    reponseInertia,
    requetes,
    reseau,
    uneTache,
} from '../utils/reseauSimule'

/*
 * La déconnexion et la suppression du compte oublient les copies hors ligne
 * des pages AVANT leur envoi (#2048, décision 6 du propriétaire), avec le
 * vrai client d'Inertia, le vrai layout, la vraie question de #2050 et le
 * vrai magasin, sur une base en mémoire.
 *
 * Avant l'envoi, et même quand il échoue faute de réseau : une réponse
 * perdue ne laisse rien de lisible sur l'appareil. Jamais avant que la
 * personne ait choisi de partir : « Rester connecté » n'oublie rien. Et la
 * file hors ligne reste au compte tant que la déconnexion n'a pas abouti
 * (#1964, #2050).
 */

// Le détachement de l'appareil a ses propres tests (useDeconnexion.test.js) : ici, seulement l'appel.
const appareil = vi.hoisted(() => ({ detacher: vi.fn() }))
vi.mock('@/composables/useAbonnementPush', () => ({
    detacherLAppareil: (...args) => {
        appareil.detacher(...args)

        return Promise.resolve()
    },
    marquerLAbonnementARetransmettre: () => {},
    rapprocherLAbonnementPush: () => Promise.resolve(true),
}))

const COMPTE = 1

let routeur
let SyncService
let magasin
let fabrique
let application
let relais
let NOM_DE_LA_BASE
let MAGASIN_DES_PAGES
let MAGASIN_DE_L_ETAT
let TEMOIN

/** Le compte, tel que chaque page le déclare. */
const auth = () => ({
    user: { id: COMPTE, name: 'Camille Horsligne', unread_notifications_count: 0, active_workout: null },
})

/** Le profil du compte, d'où l'on se déconnecte. */
const pageDuProfil = () => ({
    component: 'Profil',
    url: '/profile',
    version: 'v1',
    props: { flash: {}, is_testing: false, auth: { user: { ...auth().user, latest_achievement: null } } },
})

/** L'écran de connexion, où mènent la déconnexion et la suppression acceptées. */
const pageDeConnexion = () => ({
    component: 'Connexion',
    url: '/login',
    version: 'v1',
    props: { flash: {}, auth: { user: null } },
})

/** Une page gardée pour la salle, telle qu'une réponse complète la donne. */
const pageGardee = (url, component) => ({
    component,
    url,
    version: 'v1',
    encryptHistory: true,
    clearHistory: false,
    props: { errors: {}, auth: auth() },
})

const route = (nom) => (nom === undefined ? { current: () => false } : `/${nom}`)
const copiesRangees = () => fabrique.enregistrements(NOM_DE_LA_BASE, MAGASIN_DES_PAGES)
const etatRange = () => fabrique.enregistrements(NOM_DE_LA_BASE, MAGASIN_DE_L_ETAT).get('etat')
const comptesDeLaFile = () => JSON.parse(localStorage.getItem('offline_sync_queue') ?? '[]').map((e) => e.compte)
const logouts = () => requetes.filter((requete) => requete.url.endsWith('/logout'))
const suppressions = () => requetes.filter((requete) => requete.url.endsWith('/profile.destroy'))
const bouton = (dusk) => document.querySelector(`[dusk="${dusk}"]`)
const question = () => document.querySelector('dialog[open]')
const contenu = () => document.getElementById('contenu')?.textContent

/** Ce que le magasin gardait au moment où chaque déconnexion ou suppression est partie. */
const auDepart = []

/** Deux copies du compte, chiffrées par la clé du témoin. */
const garderDesCopies = async () => {
    expect(await magasin.ecrireUneCopie(pageGardee('/dashboard', 'Dashboard'))).toBe('ecrite')
    expect(await magasin.ecrireUneCopie(pageGardee('/workouts', 'Workouts/Index'))).toBe('ecrite')
    expect(copiesRangees().size).toBe(2)
}

/** Une modification faite sans réseau : la file la garde pour le compte connecté. */
const modifierHorsLigne = (id) =>
    expect(SyncService.patch(`/api/v1/sets/${id}`, { weight: 80 })).rejects.toMatchObject({ isOffline: true })

/** Attend la fin de la visite que `geste` mène, puis une tâche et la base au repos. */
const jusquALaFinDeLaVisite = async (geste) => {
    let finie = false
    const arreter = routeur.on('finish', () => {
        finie = true
    })

    await geste()
    await vi.waitUntil(() => finie)
    arreter()
    await uneTache()
    await fabrique.auRepos()
}

const cliquerDeconnexion = async () => {
    document.querySelector('[data-testid="logout-button"]').click()
    await uneTache()
}

const allerALaSuppression = () =>
    new Promise((fini) => routeur.replace({ ...pageDuProfil(), component: 'Suppression', onFinish: fini }))

const supprimerLeCompte = async () => {
    document.querySelector('[data-testid="delete-account-button"]').click()
    await uneTache()
    document.querySelector('[data-testid="confirm-delete-button"]').click()
    await vi.waitUntil(() => suppressions().length === 1)
}

beforeAll(async () => {
    fabrique = creerUneFabriqueEnMemoire()
    // La base de l'appareil : celle que le magasin de la page ouvre.
    vi.stubGlobal('indexedDB', fabrique)
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.stubGlobal('fetch', () => Promise.reject(new TypeError('Failed to fetch')))
    vi.stubGlobal('route', route)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

    // L'état du magasin au moment exact où la requête part.
    const envoyer = RequeteSimulee.prototype.send
    vi.spyOn(RequeteSimulee.prototype, 'send').mockImplementation(function (corps) {
        if (this.url.endsWith('/logout') || this.url.endsWith('/profile.destroy')) {
            auDepart.push({ copies: copiesRangees().size, etat: structuredClone(etatRange()) })
        }

        return envoyer.call(this, corps)
    })

    document.body.replaceChildren(Object.assign(document.createElement('div'), { id: 'app' }))
    const pageInitiale = Object.assign(document.createElement('script'), {
        type: 'application/json',
        textContent: JSON.stringify(pageDuProfil()),
    })
    pageInitiale.dataset.page = 'app'
    document.body.appendChild(pageInitiale)
    window.history.replaceState(null, '', '/profile')

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router
    SyncService = (await import('@/Utils/SyncService')).default
    const copies = await import('@/Utils/copiesHorsLigne')
    ;({ NOM_DE_LA_BASE, MAGASIN_DES_PAGES, MAGASIN_DE_L_ETAT, TEMOIN } = copies)
    magasin = copies.magasinDesCopies()
    const { installerLesVisitesHorsLigne } = await import('@/Utils/visitesHorsLigne')
    const { seDeconnecter } = await import('@/composables/useDeconnexion')
    const { default: AuthenticatedLayout } = await import('@/Layouts/AuthenticatedLayout.vue')
    const { default: GuestLayout } = await import('@/Layouts/GuestLayout.vue')
    const { default: DeleteUserForm } = await import('@/Pages/Profile/Partials/DeleteUserForm.vue')

    installerLesVisitesHorsLigne({ routeur })

    const pages = {
        Profil: {
            render: () =>
                h(AuthenticatedLayout, null, () => [
                    h('p', { id: 'contenu' }, 'Profil'),
                    h(
                        'button',
                        { type: 'button', 'data-testid': 'logout-button', onClick: seDeconnecter },
                        'Déconnexion',
                    ),
                ]),
        },
        Suppression: { render: () => h(AuthenticatedLayout, null, () => h(DeleteUserForm)) },
        Connexion: { render: () => h(GuestLayout, null, () => h('p', { id: 'contenu' }, 'Connexion')) },
    }

    await inertia.createInertiaApp({
        page: pageDuProfil(),
        resolve: (nom) => pages[nom],
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) })
            application.config.globalProperties.route = route
            application.directive('press', {})
            application.use(plugin).mount(el)
        },
    })
})

beforeEach(async () => {
    reseau.mode = 'muet'
    reseau.prochains = []
    requetes.length = 0
    auDepart.length = 0
    appareil.detacher.mockClear()
    relais = relayerLesRejetsNonGeres()

    // Chaque cas repart du profil du compte, sa file vide, le magasin vide, une clé neuve.
    await new Promise((fini) => routeur.replace({ ...pageDuProfil(), onFinish: fini }))
    SyncService.oublierLeCompte(COMPTE)
    SyncService.definirLeCompte(COMPTE)
    localStorage.clear()
    sessionStorage.clear()
    await fabrique.auRepos()
    copiesRangees().clear()
    fabrique.enregistrements(NOM_DE_LA_BASE, MAGASIN_DE_L_ETAT).clear()
    localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, `compte:${COMPTE}`)
    document.cookie = `${TEMOIN}=${btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '')}; path=/`
})

afterEach(async () => {
    routeur.cancelAll()
    await uneTache()
    relais.retirer()
})

afterAll(() => {
    application.unmount()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('se déconnecter', () => {
    it('oublie les copies avant que la déconnexion parte, et ferme la clé du témoin', async () => {
        await garderDesCopies()
        const empreinte = etatRange().empreinte

        await jusquALaFinDeLaVisite(async () => {
            await cliquerDeconnexion()
            await vi.waitUntil(() => logouts().length === 1)
            logouts()[0].repondre(reponseInertia(pageDeConnexion()))
        })

        expect(auDepart).toEqual([
            { copies: 0, etat: expect.objectContaining({ ouvert: false, empreintesFermees: [empreinte] }) },
        ])
        expect(contenu()).toBe('Connexion')
        expect(relais.rejets).toEqual([])
    })

    it('sans réseau, n’a pas lieu, mais les copies sont oubliées, et la file reste au compte', async () => {
        await garderDesCopies()
        await modifierHorsLigne(1)
        reseau.prochains = ['coupe']

        await jusquALaFinDeLaVisite(async () => {
            await cliquerDeconnexion()
            bouton('confirm-dialog-confirm').click()
            await vi.waitUntil(() => logouts().length === 1)
        })

        expect(contenu()).toBe('Profil')
        expect(document.querySelector('[role="alert"][aria-live]')?.textContent).toContain(
            "Pas de réseau : la déconnexion n'a pas eu lieu.",
        )
        expect(auDepart).toEqual([{ copies: 0, etat: expect.objectContaining({ ouvert: false }) }])
        expect(copiesRangees().size).toBe(0)
        expect(comptesDeLaFile()).toEqual(['1'])
        expect(relais.rejets).toEqual([])
    })

    it('« Rester connecté » n’oublie rien', async () => {
        await garderDesCopies()
        await modifierHorsLigne(1)

        await cliquerDeconnexion()
        expect(question()).not.toBeNull()
        bouton('confirm-dialog-cancel').click()
        await vi.waitUntil(() => question() === null)
        await fabrique.auRepos()

        expect(logouts()).toEqual([])
        expect(copiesRangees().size).toBe(2)
        expect(etatRange()).toMatchObject({ ouvert: true, compte: String(COMPTE), empreintesFermees: [] })
        expect((await magasin.lireUneCopie('/dashboard')).page.component).toBe('Dashboard')
    })
})

describe('supprimer le compte', () => {
    it.each([
        ['se perd en route', 'muet'],
        ['ne part pas, faute de réseau', 'coupe'],
    ])('dont la réponse %s laisse le magasin vide', async (_, mode) => {
        await allerALaSuppression()
        await garderDesCopies()
        reseau.prochains = [mode]

        await supprimerLeCompte()
        await uneTache()
        await fabrique.auRepos()

        expect(copiesRangees().size).toBe(0)
        expect(etatRange()).toMatchObject({ ouvert: false })
        expect(relais.rejets).toEqual([])
    })

    it('refusée (mot de passe), ne coûte que les copies : la clé reste, la page suivante les refait', async () => {
        await allerALaSuppression()
        await garderDesCopies()

        await jusquALaFinDeLaVisite(async () => {
            await supprimerLeCompte()
            suppressions()[0].repondre(
                reponseInertia({
                    ...pageDuProfil(),
                    component: 'Suppression',
                    props: { ...pageDuProfil().props, errors: { password: 'Mot de passe incorrect.' } },
                }),
            )
        })

        expect(copiesRangees().size).toBe(0)
        expect(etatRange()).toMatchObject({ ouvert: false, empreintesFermees: [] })
        expect(await magasin.ecrireUneCopie(pageGardee('/dashboard', 'Dashboard'))).toBe('ecrite')
    })

    it('acceptée, ferme aussi la clé : une réponse en vol qui la reposerait ne rouvre rien', async () => {
        await allerALaSuppression()
        await garderDesCopies()

        await jusquALaFinDeLaVisite(async () => {
            await supprimerLeCompte()
            suppressions()[0].repondre(reponseInertia(pageDeConnexion()))
        })

        expect(contenu()).toBe('Connexion')
        expect(copiesRangees().size).toBe(0)
        expect(etatRange().empreintesFermees).toHaveLength(1)
        expect(await magasin.ecrireUneCopie(pageGardee('/dashboard', 'Dashboard'))).toBe('cle-fermee')
        expect(relais.rejets).toEqual([])
    })
})
