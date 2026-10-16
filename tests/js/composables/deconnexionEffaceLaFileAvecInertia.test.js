import { describe, it, expect, vi, beforeAll, beforeEach, afterEach, afterAll } from 'vitest'
import { createApp, h } from 'vue'
import {
    RequeteSimulee,
    relayerLesRejetsNonGeres,
    reponseInertia,
    requetes,
    reseau,
    uneTache,
} from '../utils/reseauSimule'

/*
 * Se déconnecter quand des écritures faites hors ligne attendent encore, avec
 * le vrai client d'Inertia, le vrai layout et la vraie file (#2050).
 *
 * La file hors ligne garde en clair, sur l'appareil, la charge de chaque
 * écriture (poids, répétitions, notes). La déconnexion la gardait pour le
 * compte qui partait, jusqu'à sa prochaine connexion (#1964) : sur un appareil
 * partagé, n'importe qui la lisait. Le propriétaire a tranché : prévenir, puis
 * effacer. File non vide, la déconnexion ne part pas : elle dit combien
 * d'écritures seront perdues, et seul « Se déconnecter quand même » déconnecte ;
 * acceptée, elle efface la file du compte, ses refus et la dernière recherche
 * d'exercice. File vide, elle part sans question. Sans réseau, elle n'a pas
 * lieu et n'efface rien (#2048).
 *
 * Le réseau est celui de `reseauSimule.js` : Inertia attend que le test serve
 * la déconnexion, et `fetch`, par où passe la file, ne joint jamais le
 * serveur : ce qui est mis en file y reste.
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
const MESSAGE_DEUX =
    "2 modifications faites hors ligne n'ont pas encore été envoyées. Si tu te déconnectes maintenant, elles seront effacées de cet appareil et perdues. Reste connecté pour qu'elles partent dès que le serveur répondra."

let routeur
let SyncService
let application
let relais

/** Le profil du compte, d'où l'on se déconnecte. */
const pageDuProfil = () => ({
    component: 'Profil',
    url: '/profile',
    version: 'v1',
    props: {
        flash: {},
        is_testing: false,
        auth: {
            user: {
                id: COMPTE,
                name: 'Samuel',
                unread_notifications_count: 0,
                active_workout: null,
                latest_achievement: null,
            },
        },
    },
})

/** L'écran de connexion, où mène la déconnexion acceptée. */
const pageDeConnexion = () => ({
    component: 'Connexion',
    url: '/login',
    version: 'v1',
    props: { flash: {}, auth: { user: null } },
})

const route = (nom) => {
    if (nom === undefined) {
        return { current: () => false }
    }

    return `/${nom}`
}

const fileStockee = () => JSON.parse(localStorage.getItem('offline_sync_queue') ?? '[]')
const comptesDeLaFile = () => fileStockee().map((entree) => entree.compte)
const logouts = () => requetes.filter((requete) => requete.url.endsWith('/logout'))
const question = () => document.querySelector('dialog[open]')
const texteDeLaQuestion = () => question()?.textContent.replace(/\s+/g, ' ').trim() ?? ''
const bouton = (dusk) => document.querySelector(`[dusk="${dusk}"]`)
const contenu = () => document.getElementById('contenu')?.textContent

/** Une modification faite sans réseau : la file la garde pour le compte connecté. */
const modifierHorsLigne = (id) =>
    expect(SyncService.patch(`/api/v1/sets/${id}`, { weight: 80, notes: `Note privée ${id}` })).rejects.toMatchObject({
        isOffline: true,
    })

/** Attend la fin de la visite que `geste` mène, puis une tâche : `onSuccess` passe après la page. */
const jusquALaFinDeLaVisite = async (geste) => {
    let finie = false
    const arreter = routeur.on('finish', () => {
        finie = true
    })

    await geste()
    await vi.waitUntil(() => finie)
    arreter()
    await uneTache()
}

/** Clique « Déconnexion » sur le profil, et laisse la question s'ouvrir s'il y a lieu. */
const cliquerDeconnexion = async () => {
    document.querySelector('[data-testid="logout-button"]').click()
    await uneTache()
}

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.stubGlobal('fetch', () => Promise.reject(new TypeError('Failed to fetch')))
    vi.stubGlobal('route', route)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

    document.body.replaceChildren(Object.assign(document.createElement('div'), { id: 'app' }))
    // La page rendue par le serveur, d'où la file lit le compte connecté à son chargement.
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
    const { installerLesVisitesHorsLigne } = await import('@/Utils/visitesHorsLigne')
    const { seDeconnecter } = await import('@/composables/useDeconnexion')
    const { default: AuthenticatedLayout } = await import('@/Layouts/AuthenticatedLayout.vue')
    const { default: GuestLayout } = await import('@/Layouts/GuestLayout.vue')

    installerLesVisitesHorsLigne({ routeur })

    const pages = {
        // Le bouton du profil, tel que `Pages/Profile/Index.vue` le pose.
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
    appareil.detacher.mockClear()
    relais = relayerLesRejetsNonGeres()

    // Chaque cas repart du profil du compte, sa file vide.
    await new Promise((fini) => routeur.replace({ ...pageDuProfil(), onFinish: fini }))
    SyncService.oublierLeCompte(COMPTE)
    SyncService.oublierLeCompte(2)
    SyncService.definirLeCompte(COMPTE)
    localStorage.clear()
    sessionStorage.clear()
})

afterEach(() => {
    relais.retirer()
    vi.useRealTimers()
})

afterAll(() => {
    application.unmount()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('se déconnecter avec des écritures en attente', () => {
    it('part sans question quand la file du compte est vide, et efface la recherche d’exercice', async () => {
        localStorage.setItem('gymtracker_add_exercise_search', 'Développé couché')

        await jusquALaFinDeLaVisite(async () => {
            await cliquerDeconnexion()
            expect(question()).toBeNull()
            await vi.waitUntil(() => logouts().length === 1)
            logouts()[0].repondre(reponseInertia(pageDeConnexion()))
        })

        expect(appareil.detacher).toHaveBeenCalledTimes(1)
        expect(contenu()).toBe('Connexion')
        expect(localStorage.getItem('gymtracker_add_exercise_search')).toBeNull()
        // Rien n'a été effacé : l'écran de connexion n'a rien à dire.
        expect(document.querySelector('[dusk="ecritures-effacees-a-la-deconnexion"]')).toBeNull()
        expect(relais.rejets).toEqual([])
    })

    it('dit combien seront perdues, et « Rester connecté » ne déconnecte pas et garde tout', async () => {
        await modifierHorsLigne(1)
        await modifierHorsLigne(2)
        localStorage.setItem('gymtracker_add_exercise_search', 'Squat')

        await cliquerDeconnexion()

        expect(texteDeLaQuestion()).toContain('Se déconnecter et perdre ces modifications ?')
        expect(texteDeLaQuestion()).toContain(MESSAGE_DEUX)
        expect(bouton('confirm-dialog-cancel').textContent.trim()).toBe('Rester connecté')
        expect(bouton('confirm-dialog-confirm').textContent.trim()).toBe('Se déconnecter quand même')
        // Rien n'est parti : ni la déconnexion, ni le détachement de l'appareil.
        expect(logouts()).toEqual([])
        expect(appareil.detacher).not.toHaveBeenCalled()

        bouton('confirm-dialog-cancel').click()
        await vi.waitUntil(() => question() === null)

        expect(logouts()).toEqual([])
        expect(appareil.detacher).not.toHaveBeenCalled()
        expect(contenu()).toBe('Profil')
        expect(comptesDeLaFile()).toEqual(['1', '1'])
        expect(SyncService.enAttente()).toBe(2)
        expect(localStorage.getItem('gymtracker_add_exercise_search')).toBe('Squat')
    })

    it('« Se déconnecter quand même » efface la file du compte une fois la déconnexion acceptée, et le dit', async () => {
        await modifierHorsLigne(1)
        await modifierHorsLigne(2)
        // L'écriture d'un autre compte, laissée par une session expirée : elle reste à lui.
        SyncService.addToQueue({ method: 'patch', url: '/api/v1/sets/9', data: { reps: 3 } }, '2')
        localStorage.setItem('gymtracker_add_exercise_search', 'Squat')
        localStorage.setItem('draft_set_12', JSON.stringify({ weight: 82.5 }))

        await jusquALaFinDeLaVisite(async () => {
            await cliquerDeconnexion()
            bouton('confirm-dialog-confirm').click()
            await vi.waitUntil(() => logouts().length === 1)

            // Rien n'est effacé avant la réponse : la déconnexion peut encore échouer.
            expect(comptesDeLaFile()).toEqual(['1', '1', '2'])

            logouts()[0].repondre(reponseInertia(pageDeConnexion()))
        })

        expect(appareil.detacher).toHaveBeenCalledTimes(1)
        expect(contenu()).toBe('Connexion')
        expect(comptesDeLaFile()).toEqual(['2'])
        expect(SyncService.queue.map((entree) => entree.compte)).toEqual(['2'])
        expect(localStorage.getItem('gymtracker_add_exercise_search')).toBeNull()
        expect(localStorage.getItem('draft_set_12')).toBeNull()
        expect(document.querySelector('[dusk="ecritures-effacees-a-la-deconnexion"]').textContent.trim()).toBe(
            "2 modifications faites hors ligne n'avaient pas encore été envoyées : elles ont été effacées de cet appareil avec la déconnexion.",
        )
        // Le contenu des écritures n'est écrit nulle part ailleurs.
        expect(JSON.stringify({ ...localStorage })).not.toContain('Note privée')
        expect(JSON.stringify({ ...sessionStorage })).not.toContain('Note privée')
        expect(relais.rejets).toEqual([])
    })

    it('compte aussi ce qu’un autre onglet a mis en file pour ce compte, et l’efface', async () => {
        await modifierHorsLigne(1)
        // Un autre onglet a mis en file, après le chargement de celui-ci, une écriture que sa copie ne connaît pas.
        localStorage.setItem(
            'offline_sync_queue',
            JSON.stringify([
                ...fileStockee(),
                {
                    method: 'post',
                    url: '/api/v1/sets',
                    data: { reps: 5 },
                    id: 'autre-onglet',
                    timestamp: new Date().toISOString(),
                    compte: '1',
                },
            ]),
        )

        await cliquerDeconnexion()

        expect(SyncService.enAttente()).toBe(1)
        expect(texteDeLaQuestion()).toContain(MESSAGE_DEUX)

        await jusquALaFinDeLaVisite(async () => {
            bouton('confirm-dialog-confirm').click()
            await vi.waitUntil(() => logouts().length === 1)
            logouts()[0].repondre(reponseInertia(pageDeConnexion()))
        })

        expect(fileStockee()).toEqual([])
    })

    it('sans réseau, la déconnexion n’a pas lieu, le dit, et n’efface rien', async () => {
        await modifierHorsLigne(1)
        localStorage.setItem('gymtracker_add_exercise_search', 'Squat')
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
        expect(comptesDeLaFile()).toEqual(['1'])
        expect(SyncService.enAttente()).toBe(1)
        expect(localStorage.getItem('gymtracker_add_exercise_search')).toBe('Squat')
        expect(sessionStorage.getItem('gym-tracker:ecritures-effacees-a-la-deconnexion')).toBeNull()
        expect(relais.rejets).toEqual([])
    })
})

describe('trente jours au plus', () => {
    /** L'application revient au premier plan : la file se vide, et commence par abandonner ce qui a trop attendu. */
    const revenirAuPremierPlan = async () => {
        Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true })
        document.dispatchEvent(new Event('visibilitychange'))
        await SyncService.pending
        await uneTache()
    }

    const toast = () => document.querySelector('[role="alert"][aria-live]')?.textContent.replace(/\s+/g, ' ').trim()

    it('garde une écriture 29 et 30 jours, l’abandonne au-delà, et le dit au compte sur l’écran ouvert', async () => {
        vi.useFakeTimers({ toFake: ['Date'] })
        vi.setSystemTime(new Date('2026-09-15T08:00:00.000Z'))
        await modifierHorsLigne(1)

        vi.setSystemTime(new Date('2026-10-14T08:00:00.000Z'))
        await revenirAuPremierPlan()
        expect(SyncService.enAttente()).toBe(1)

        vi.setSystemTime(new Date('2026-10-15T08:00:00.000Z'))
        await revenirAuPremierPlan()
        expect(SyncService.enAttente()).toBe(1)
        expect(toast()).toBeUndefined()

        vi.setSystemTime(new Date('2026-10-15T08:00:00.001Z'))
        await revenirAuPremierPlan()

        expect(SyncService.enAttente()).toBe(0)
        expect(fileStockee()).toEqual([])
        expect(toast()).toContain(
            "Une modification faite hors ligne le 15/09/2026 n'a pas pu être enregistrée : gardée 30 jours sur cet appareil sans pouvoir partir, elle a été abandonnée et effacée. Vérifie tes séances.",
        )

        // Plus rien à perdre : la déconnexion part sans question.
        await jusquALaFinDeLaVisite(async () => {
            await cliquerDeconnexion()
            expect(question()).toBeNull()
            await vi.waitUntil(() => logouts().length === 1)
            logouts()[0].repondre(reponseInertia(pageDeConnexion()))
        })

        expect(contenu()).toBe('Connexion')
    })
})
