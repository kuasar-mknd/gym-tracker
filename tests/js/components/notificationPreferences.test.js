import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const page = {
    props: {
        // Must be decodable by atob(): the component runs it through
        // urlBase64ToUint8Array before subscribing.
        vapidPublicKey: 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U',
    },
}
const post = vi.fn()
const patch = vi.fn()

vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }))
vi.mock('axios', () => ({ default: { post: (...args) => post(...args) } }))
vi.mock('@/Utils/SyncService', () => ({ default: { patch: (...args) => patch(...args) } }))

import UpdateNotificationPreferencesForm from '@/Pages/Profile/Partials/UpdateNotificationPreferencesForm.vue'

const unsubscribe = vi.fn()

beforeAll(() => {
    globalThis.route = (name) => `/${name}`

    // The component needs both to consider push supported.
    globalThis.Notification = { permission: 'default', requestPermission: vi.fn() }
    Object.defineProperty(window, 'Notification', { writable: true, value: globalThis.Notification })
    Object.defineProperty(navigator, 'serviceWorker', {
        writable: true,
        configurable: true,
        value: {
            ready: Promise.resolve({
                pushManager: {
                    getSubscription: vi.fn().mockResolvedValue(null),
                    subscribe: vi.fn().mockImplementation(() => Promise.resolve({ unsubscribe })),
                },
            }),
        },
    })
})

const mountForm = (props = {}) =>
    mount(UpdateNotificationPreferencesForm, {
        props: { preferences: {}, hasPushSubscription: false, ...props },
        global: { stubs: { GlassSection: false } },
    })

const banner = (wrapper) => wrapper.find('h4')
const pushCheckboxes = (wrapper) => wrapper.findAll('input[type="checkbox"]')
const error = (wrapper) => wrapper.find('[dusk="notification-push-error"]')

beforeEach(() => {
    post.mockReset()
    patch.mockReset()
    unsubscribe.mockReset()
    unsubscribe.mockResolvedValue(undefined)
    globalThis.Notification.requestPermission = vi.fn().mockResolvedValue('granted')
    patch.mockResolvedValue({})
})

describe('UpdateNotificationPreferencesForm', () => {
    it('offers activation when the server holds no subscription', () => {
        const wrapper = mountForm()

        expect(banner(wrapper).text()).toContain('Activer les notifications Push')
        expect(pushCheckboxes(wrapper)).toHaveLength(0)

        wrapper.unmount()
    })

    it('offers the push toggles when the server already holds one', () => {
        const wrapper = mountForm({ hasPushSubscription: true })

        expect(banner(wrapper).exists()).toBe(false)
        expect(pushCheckboxes(wrapper).length).toBeGreaterThan(0)

        wrapper.unmount()
    })

    /**
     * The defect this file exists for. The panel used to switch into its
     * "push is on" state off Notification.permission, which the browser grants
     * before the subscription reaches the server. A failed POST therefore hid
     * the activation banner and offered the push checkboxes: the user enabled
     * them, saved, received nothing, and had no way back.
     */
    it('stays on the activation banner when the server refuses the subscription', async () => {
        post.mockRejectedValue(new Error('419'))

        const wrapper = mountForm()
        await wrapper.find('button[type="button"]').trigger('click')
        await flushPromises()

        expect(banner(wrapper).text()).toContain('Activer les notifications Push')
        expect(pushCheckboxes(wrapper)).toHaveLength(0)
        expect(error(wrapper).exists()).toBe(true)

        wrapper.unmount()
    })

    /**
     * A browser subscription the server does not hold can never deliver
     * anything, and the next attempt discards it anyway.
     */
    it('drops the browser subscription when the server refuses it', async () => {
        post.mockRejectedValue(new Error('500'))

        const wrapper = mountForm()
        await wrapper.find('button[type="button"]').trigger('click')
        await flushPromises()

        expect(unsubscribe).toHaveBeenCalledTimes(1)

        wrapper.unmount()
    })

    it('switches to the push toggles once the server accepts', async () => {
        post.mockResolvedValue({})

        const wrapper = mountForm()
        await wrapper.find('button[type="button"]').trigger('click')
        await flushPromises()

        expect(banner(wrapper).exists()).toBe(false)
        expect(pushCheckboxes(wrapper).length).toBeGreaterThan(0)
        expect(error(wrapper).exists()).toBe(false)

        wrapper.unmount()
    })

    it('says so when the browser has blocked notifications', async () => {
        globalThis.Notification.requestPermission = vi.fn().mockResolvedValue('denied')

        const wrapper = mountForm()
        await wrapper.find('button[type="button"]').trigger('click')
        await flushPromises()

        expect(error(wrapper).text()).toContain('navigateur')
        expect(post).not.toHaveBeenCalled()

        wrapper.unmount()
    })

    /**
     * The catch handled isOffline and 422 and nothing else, so a 419 on an
     * expired token left the toggles showing values the server never stored.
     */
    it('reports a preferences save that the server rejected', async () => {
        patch.mockRejectedValue({ response: { status: 419 } })

        const wrapper = mountForm({ hasPushSubscription: true })
        await wrapper.find('form').trigger('submit')
        await flushPromises()

        expect(error(wrapper).exists()).toBe(true)

        wrapper.unmount()
    })

    it('keeps quiet when the save succeeds', async () => {
        const wrapper = mountForm({ hasPushSubscription: true })
        await wrapper.find('form').trigger('submit')
        await flushPromises()

        expect(error(wrapper).exists()).toBe(false)

        wrapper.unmount()
    })
})
