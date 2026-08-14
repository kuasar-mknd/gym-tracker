import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * A subscription the browser has dropped has to be dropped on the server too.
 *
 * Unsubscribing only tells the browser to stop honouring an endpoint; the row
 * stayed in `push_subscriptions` for good. Every re-activation therefore left
 * another orphan, and each one is an endpoint the app keeps pushing to — the
 * provider answers 410 Gone every time, for a subscription nobody can receive.
 *
 * The `push-subscriptions.destroy` route existed for exactly this and was
 * called from nowhere but its own tests.
 */
const post = vi.fn()

vi.mock('@inertiajs/vue3', async () => {
    const { reactive: makeReactive } = await vi.importActual('vue')

    return {
        // A real-shaped VAPID key: the component decodes it with atob, and an
        // invalid one throws before a single request is made — which is how two
        // of these tests first passed for the wrong reason.
        usePage: () => ({
            props: {
                vapidPublicKey:
                    'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U',
            },
        }),
        useForm: (fields) => makeReactive({ ...fields, errors: {}, processing: false }),
        router: { post: vi.fn(), patch: vi.fn() },
    }
})

vi.mock('axios', () => ({ default: { post: (...args) => post(...args) } }))

import UpdateNotificationPreferencesForm from '@/Pages/Profile/Partials/UpdateNotificationPreferencesForm.vue'

beforeAll(() => {
    globalThis.route = (name) => `/${name}`
})

/** A browser subscription that records whether it was unsubscribed. */
const subscription = (endpoint) => ({
    endpoint,
    unsubscribe: vi.fn().mockResolvedValue(true),
    toJSON: () => ({ endpoint }),
})

const setUpBrowser = ({ existing = null, created = subscription('https://push.example/new') } = {}) => {
    const pushManager = {
        getSubscription: vi.fn().mockResolvedValue(existing),
        subscribe: vi.fn().mockResolvedValue(created),
    }

    globalThis.Notification = { requestPermission: vi.fn().mockResolvedValue('granted') }

    Object.defineProperty(window.navigator, 'serviceWorker', {
        configurable: true,
        value: { ready: Promise.resolve({ pushManager }) },
    })

    return { pushManager, created }
}

/**
 * Routed by URL rather than by call order. Which request comes first depends on
 * whether a previous subscription existed, so `mockRejectedValueOnce` would
 * reject the cleanup in one test and the save in another — the test would then
 * pass or fail for a reason it never stated.
 */
const failing = new Set()

beforeEach(() => {
    vi.clearAllMocks()
    failing.clear()
    post.mockImplementation((url) =>
        [...failing].some((fragment) => url.includes(fragment))
            ? Promise.reject(new Error('réseau'))
            : Promise.resolve({ data: {} }),
    )
    window.axios = { post: (...args) => post(...args) }
})

afterEach(() => {
    delete window.axios
})

const mountForm = (props = {}) =>
    mount(UpdateNotificationPreferencesForm, {
        props: { hasPushSubscription: false, preferences: {}, ...props },
        global: {
            directives: { press: {} },
            mocks: { route: globalThis.route },
            stubs: {
                GlassCard: { template: '<div><slot /></div>' },
                GlassButton: { props: ['disabled'], template: '<button :disabled="disabled"><slot /></button>' },
            },
        },
    })

/** The endpoints handed to the destroy route, in order. */
const forgotten = () =>
    post.mock.calls.filter(([url]) => url.includes('destroy')).map(([, payload]) => payload.endpoint)

describe('activating push notifications', () => {
    it('tells the server to forget the subscription it just replaced', async () => {
        const stale = subscription('https://push.example/stale')
        setUpBrowser({ existing: stale })

        const wrapper = mountForm()
        await wrapper.vm.enablePush()
        await flushPromises()

        expect(stale.unsubscribe).toHaveBeenCalledTimes(1)
        // The old row would otherwise sit in the table for good, collecting a
        // 410 on every push the app attempts.
        expect(forgotten()).toEqual(['https://push.example/stale'])

        wrapper.unmount()
    })

    it('asks for nothing when there was no previous subscription', async () => {
        setUpBrowser({ existing: null })

        const wrapper = mountForm()
        await wrapper.vm.enablePush()
        await flushPromises()

        expect(forgotten()).toEqual([])

        wrapper.unmount()
    })

    it('drops the new subscription on both sides when saving it fails', async () => {
        const created = subscription('https://push.example/doomed')
        setUpBrowser({ existing: null, created })
        failing.add('update')

        const wrapper = mountForm()
        await wrapper.vm.enablePush()
        await flushPromises()

        // The browser holds a subscription the server never recorded — or
        // recorded and then failed around. Either way it can deliver nothing,
        // so both sides let it go.
        expect(created.unsubscribe).toHaveBeenCalledTimes(1)
        expect(forgotten()).toEqual(['https://push.example/doomed'])

        wrapper.unmount()
    })

    it('still subscribes when the cleanup call itself fails', async () => {
        const stale = subscription('https://push.example/stale')
        const { pushManager } = setUpBrowser({ existing: stale })
        failing.add('destroy')

        const wrapper = mountForm()
        await wrapper.vm.enablePush()
        await flushPromises()

        // Housekeeping must not stand between the user and the thing they
        // asked for. A missed cleanup leaves one orphan; a refused
        // subscription leaves them with no notifications at all.
        expect(pushManager.subscribe).toHaveBeenCalledTimes(1)

        wrapper.unmount()
    })
})
