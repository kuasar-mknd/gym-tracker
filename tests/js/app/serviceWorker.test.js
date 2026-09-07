import { describe, it, expect, vi, beforeAll, beforeEach, afterAll } from 'vitest'

const clientsClaim = vi.fn()
const cleanupOutdatedCaches = vi.fn()
const precacheAndRoute = vi.fn()

vi.mock('workbox-core', () => ({ clientsClaim: (...args) => clientsClaim(...args) }))
vi.mock('workbox-precaching', () => ({
    cleanupOutdatedCaches: (...args) => cleanupOutdatedCaches(...args),
    precacheAndRoute: (...args) => precacheAndRoute(...args),
}))

/**
 * The worker is a module of side effects: importing it *is* running it. So the
 * globals a worker scope would provide are put in place first, the two
 * `addEventListener` calls are captured off the spy, and the handlers are then
 * driven directly — there is no worker environment here to dispatch to them.
 *
 * What is worth pinning down: the two calls at the top, which are the whole fix
 * for a PWA that kept serving a stale build after every deploy, and the push
 * handler's permission gate, which is the difference between a silent no-op and
 * a thrown error inside `event.waitUntil`.
 *
 * Both handlers also hand their work to `event.waitUntil`, which is what keeps
 * the worker alive until that work finishes — without it the browser is free to
 * kill the process mid-notification. The two spies below return distinct
 * sentinels so the tests can assert *which* promise was handed over, rather than
 * merely that something was.
 */
const notificationShown = Promise.resolve('notification shown')
const windowOpened = Promise.resolve('window opened')

const showNotification = vi.fn(() => notificationShown)
const openWindow = vi.fn(() => windowOpened)
const skipWaiting = vi.fn()
const listeners = {}

/**
 * Les fenetres que le worker trouvera au moment du clic. Un tableau vide est le
 * cas « application fermee » ; le test le remplit pour le cas « deja ouverte ».
 */
const fenetresOuvertes = []
const matchAll = vi.fn(() => Promise.resolve(fenetresOuvertes))

/**
 * Une fenetre cliente, telle que `clients.matchAll` en rend.
 *
 * `navigate` n'est offert qu'aux fenetres que ce worker controle : l'omettre
 * reproduit une fenetre qu'il ne controle pas encore.
 *
 * @param {{navigate?: false|(() => Promise<unknown>)}} options
 */
const fenetreCliente = ({ navigate } = {}) => ({
    focus: vi.fn(() => Promise.resolve('fenetre reprise')),
    ...(navigate === false ? {} : { navigate: vi.fn(navigate ?? (() => Promise.resolve())) }),
})

const manifest = [{ url: '/assets/app.js', revision: 'abc' }]

const originalClients = globalThis.clients

/*
 * Les globales du worker sont posées AVANT toute importation, pas dans un
 * `beforeAll` : Vitest 5 évalue le module de test — et donc son graphe — avant
 * de lancer les crochets, si bien que `sw.js` tournait sur un `self` encore nu
 * et ses quatre appels de tête ne touchaient plus les espions.
 */
vi.spyOn(self, 'addEventListener').mockImplementation((type, handler) => {
    listeners[type] = handler
})

self.skipWaiting = skipWaiting
self.__WB_MANIFEST = manifest
self.registration = { showNotification }
globalThis.clients = { openWindow, matchAll }

/*
 * `self.Notification` n'est deliberement pas pose.
 *
 * C'est l'interface globale de notification dans la PORTEE DU WORKER, qui n'est
 * pas celle de la page, et rien ne garantit qu'un navigateur l'y expose. Le
 * gestionnaire s'appuyait dessus pour decider s'il devait afficher quoi que ce
 * soit ; le test, lui, la posait — donc le seul cas qui comptait, celui ou elle
 * est absente, n'etait jamais joue, et la suite restait verte sur un worker qui
 * n'affichait rien.
 */
delete self.Notification

/**
 * Ce que le module a fait en s'important, relevé tout de suite.
 *
 * Vitest 5 vide l'historique des espions avant chaque test : le relever dans le
 * test, c'est le relever après l'effacement, et l'assertion échoue sur un
 * module qui a pourtant bien tourné.
 */
const auDemarrage = {}

beforeAll(async () => {
    await import('@/sw.js')

    auDemarrage.skipWaiting = skipWaiting.mock.calls.length
    auDemarrage.clientsClaim = clientsClaim.mock.calls.length
    auDemarrage.cleanupOutdatedCaches = cleanupOutdatedCaches.mock.calls.length
    auDemarrage.precache = precacheAndRoute.mock.calls[0]
})

afterAll(() => {
    globalThis.clients = originalClients
    vi.restoreAllMocks()
})

beforeEach(() => {
    showNotification.mockClear()
    openWindow.mockClear()
    matchAll.mockClear()
    fenetresOuvertes.length = 0
})

/**
 * Drives the push handler the way the browser would.
 *
 * @param {Object|undefined} payload - The JSON body, or undefined for a bare push.
 * @returns {{shown: Array, waited: Array}} The arguments handed to
 *   showNotification, and whatever the handler asked the browser to wait on.
 */
const pushed = (payload) => {
    const waited = []
    listeners.push({
        data: payload === undefined ? undefined : { json: () => payload },
        waitUntil: (promise) => waited.push(promise),
    })

    return { shown: showNotification.mock.calls[0], waited }
}

describe('mise en service du worker', () => {
    it('prend la main immédiatement au lieu d’attendre la fermeture des onglets', () => {
        // Without these two the new worker sits in "waiting" forever: an
        // installed PWA is suspended, never closed, so it kept serving the build
        // it was installed with.
        expect(auDemarrage.skipWaiting).toBeGreaterThan(0)
        expect(auDemarrage.clientsClaim).toBeGreaterThan(0)
    })

    it('jette les précaches des versions précédentes', () => {
        expect(auDemarrage.cleanupOutdatedCaches).toBeGreaterThan(0)
    })

    it('précache le manifeste que le build lui injecte', () => {
        // Precaching a literal list instead would freeze the worker on whatever
        // asset names existed the day it was written.
        expect(auDemarrage.precache).toEqual([manifest])
    })
})

describe('réception d’une notification push', () => {
    it('affiche même là où le worker n’expose pas Notification', () => {
        // Le cas iOS, et la raison d’être du correctif. Le gestionnaire sortait
        // sur `!(self.Notification && …)` : là où l’interface n’est pas exposée
        // dans la portée du worker, la garde était fausse à CHAQUE push et rien
        // ne s’affichait jamais. Elle ne protégeait de rien — la spécification
        // interdit de délivrer un push à un abonnement dont la permission a été
        // retirée — et elle coûtait tout, puisqu’un worker qui reçoit sans rien
        // montrer se fait révoquer son abonnement.
        expect(self.Notification).toBeUndefined()

        expect(pushed({ title: 'Séance' }).shown[0]).toBe('Séance')
    })

    it('retient le worker en vie jusqu’à ce que la notification soit affichée', () => {
        // Showing the notification without handing the promise to waitUntil
        // leaves the browser free to kill the worker mid-flight, and the push
        // then lands sometimes — which is the worst of the three outcomes.
        expect(pushed({ title: 'Séance' }).waited).toEqual([notificationShown])
    })

    it('reprend chaque champ du message à sa place', () => {
        const {
            shown: [title, options],
        } = pushed({
            title: 'Repos terminé',
            body: 'Série suivante',
            icon: '/timer.svg',
            data: { url: '/workouts/12' },
            actions: [{ action: 'open', title: 'Ouvrir' }],
        })

        // Field by field: a handler that put the body in the title, or the icon
        // in the badge, still shows a notification carrying all the same strings.
        expect(title).toBe('Repos terminé')
        expect(options).toEqual({
            body: 'Série suivante',
            icon: '/timer.svg',
            badge: '/badge.svg',
            data: { url: '/workouts/12' },
            actions: [{ action: 'open', title: 'Ouvrir' }],
        })
    })

    it('comble un message incomplet plutôt que d’afficher des trous', () => {
        const {
            shown: [title, options],
        } = pushed({})

        expect(title).toBe('Gym Tracker')
        expect(options).toEqual({
            body: 'Nouvelle notification !',
            icon: '/logo.svg',
            badge: '/badge.svg',
            data: { url: '/' },
            actions: [],
        })
    })

    it('survit à un push sans charge utile', () => {
        // A push with no body at all is legal, and `event.data.json()` on it
        // throws — inside a handler that is a rejected waitUntil and no
        // notification, for a message the server did send.
        const {
            shown: [title, options],
        } = pushed(undefined)

        expect(title).toBe('Gym Tracker')
        expect(options.data).toEqual({ url: '/' })
    })

    it('survit à une charge utile que json() refuse de lire', () => {
        // Troisième chemin muet : `json()` lève sur un corps qui n’est pas du
        // JSON, l’exception traversait `waitUntil`, et le navigateur comptait
        // une notification promise puis jamais montrée.
        const waited = []
        listeners.push({
            data: {
                json: () => {
                    throw new SyntaxError('Unexpected token')
                },
            },
            waitUntil: (promise) => waited.push(promise),
        })

        expect(showNotification).toHaveBeenCalledWith('Gym Tracker', expect.objectContaining({ data: { url: '/' } }))
        expect(waited).toEqual([notificationShown])
    })
})

describe('clic sur une notification', () => {
    /**
     * Drives the notificationclick handler the way the browser would.
     *
     * @param {{data?: object, action?: string}} evenement - Ce que la
     *   notification transporte, et le bouton touché s’il y en a un.
     * @returns {{close: Function, waited: Array}} The close spy, and whatever
     *   the handler asked the browser to wait on.
     */
    const clicked = ({ data, action } = {}) => {
        const close = vi.fn()
        const waited = []

        listeners.notificationclick({
            action,
            notification: { close, data },
            waitUntil: (promise) => waited.push(promise),
        })

        return { close, waited }
    }

    it('ferme la notification et ouvre la page qu’elle transportait', async () => {
        const { close, waited } = clicked({ data: { url: '/workouts/12' } })
        await Promise.all(waited)

        // Opening a fixed '/' would drop the user on the dashboard for a
        // notification that was about one specific session. La destination se
        // lisait dans un champ `action_url` que le serveur n’a jamais envoyé.
        expect(close).toHaveBeenCalled()
        expect(openWindow).toHaveBeenCalledWith('/workouts/12')
    })

    it('retient le worker en vie jusqu’à ce que la page soit ouverte', async () => {
        // openWindow is asynchronous: a worker shut down before it resolves
        // closes the notification and opens nothing.
        const { waited } = clicked({ data: { url: '/workouts/12' } })

        expect(waited).toHaveLength(1)
        await expect(waited[0]).resolves.toBe('window opened')
    })

    it('reprend la fenêtre déjà ouverte au lieu d’en empiler une seconde', async () => {
        const fenetre = fenetreCliente()
        fenetresOuvertes.push(fenetre)

        const { waited } = clicked({ data: { url: '/stats' } })
        await Promise.all(waited)

        // En installé, `openWindow` pose un second exemplaire de l’application
        // par-dessus celui que l’utilisateur avait déjà.
        expect(openWindow).not.toHaveBeenCalled()
        expect(fenetre.navigate).toHaveBeenCalledWith('/stats')
        expect(fenetre.focus).toHaveBeenCalled()
    })

    it('met la fenêtre au premier plan même quand elle refuse de naviguer', async () => {
        const fenetre = fenetreCliente({ navigate: () => Promise.reject(new Error('non contrôlée')) })
        fenetresOuvertes.push(fenetre)

        const { waited } = clicked({ data: { url: '/stats' } })
        await Promise.all(waited)

        // `navigate()` refuse une fenêtre que le worker ne contrôle pas encore.
        // Son échec ne doit pas emporter le geste attendu, qui est de revenir à
        // l’application.
        expect(fenetre.focus).toHaveBeenCalled()
        expect(openWindow).not.toHaveBeenCalled()
    })

    it('suit le bouton touché quand la notification en portait un', async () => {
        const { waited } = clicked({ action: '/achievements', data: { url: '/' } })
        await Promise.all(waited)

        // Un bouton d’action porte sa propre destination ; iOS ne les affiche
        // pas, d’où le repli sur celle que la notification transporte.
        expect(openWindow).toHaveBeenCalledWith('/achievements')
    })

    it('retombe sur l’accueil quand la notification ne transporte rien', async () => {
        const { waited } = clicked({})
        await Promise.all(waited)

        expect(openWindow).toHaveBeenCalledWith('/')
    })
})
