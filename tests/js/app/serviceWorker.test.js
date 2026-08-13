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

const manifest = [{ url: '/assets/app.js', revision: 'abc' }]

const originalClients = globalThis.clients

beforeAll(async () => {
    vi.spyOn(self, 'addEventListener').mockImplementation((type, handler) => {
        listeners[type] = handler
    })

    self.skipWaiting = skipWaiting
    self.__WB_MANIFEST = manifest
    self.registration = { showNotification }
    self.Notification = { permission: 'granted' }
    globalThis.clients = { openWindow }

    await import('@/sw.js')
})

afterAll(() => {
    globalThis.clients = originalClients
    vi.restoreAllMocks()
})

beforeEach(() => {
    showNotification.mockClear()
    openWindow.mockClear()
    self.Notification = { permission: 'granted' }
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
        expect(skipWaiting).toHaveBeenCalled()
        expect(clientsClaim).toHaveBeenCalled()
    })

    it('jette les précaches des versions précédentes', () => {
        expect(cleanupOutdatedCaches).toHaveBeenCalled()
    })

    it('précache le manifeste que le build lui injecte', () => {
        // Precaching a literal list instead would freeze the worker on whatever
        // asset names existed the day it was written.
        expect(precacheAndRoute).toHaveBeenCalledWith(manifest)
    })
})

describe('réception d’une notification push', () => {
    it('ne notifie pas tant que la permission n’a pas été accordée', () => {
        self.Notification = { permission: 'default' }
        listeners.push({ data: { json: () => ({ title: 'Séance' }) }, waitUntil: () => {} })

        self.Notification = { permission: 'denied' }
        listeners.push({ data: { json: () => ({ title: 'Séance' }) }, waitUntil: () => {} })

        expect(showNotification).not.toHaveBeenCalled()
    })

    it('notifie dès que la permission est accordée', () => {
        // The other half of the gate: a handler that returns unconditionally
        // passes the check above and shows nothing, ever.
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
            action_url: '/workouts/12',
            actions: [{ action: 'open', title: 'Ouvrir' }],
        })

        // Field by field: a handler that put the body in the title, or the icon
        // in the badge, still shows a notification carrying all the same strings.
        expect(title).toBe('Repos terminé')
        expect(options).toEqual({
            body: 'Série suivante',
            icon: '/timer.svg',
            badge: '/badge.svg',
            data: '/workouts/12',
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
            data: '/',
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
        expect(options.data).toBe('/')
    })
})

describe('clic sur une notification', () => {
    /**
     * Drives the notificationclick handler the way the browser would.
     *
     * @param {string|undefined} data - The target the notification carries.
     * @returns {{close: Function, waited: Array}} The close spy, and whatever
     *   the handler asked the browser to wait on.
     */
    const clicked = (data) => {
        const close = vi.fn()
        const waited = []

        listeners.notificationclick({
            notification: { close, data },
            waitUntil: (promise) => waited.push(promise),
        })

        return { close, waited }
    }

    it('ferme la notification et ouvre la page qu’elle transportait', () => {
        const { close } = clicked('/workouts/12')

        // Opening a fixed '/' would drop the user on the dashboard for a
        // notification that was about one specific session.
        expect(close).toHaveBeenCalled()
        expect(openWindow).toHaveBeenCalledWith('/workouts/12')
    })

    it('retient le worker en vie jusqu’à ce que la page soit ouverte', () => {
        // openWindow is asynchronous: a worker shut down before it resolves
        // closes the notification and opens nothing.
        expect(clicked('/workouts/12').waited).toEqual([windowOpened])
    })
})
