import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
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
// Les cases « Envoyer aussi en Push » seulement : les jours de rappel ont les leurs.
const pushCheckboxes = (wrapper) =>
    wrapper.findAll('input[type="checkbox"]').filter((box) => box.element.closest('[dusk="reminder-days"]') === null)
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

/**
 * Le bouton tournait sans fin quand une étape du navigateur ne répondait
 * jamais, et tout échec donnait le même message : impossible de savoir où ça
 * casse depuis un téléphone. Chaque étape est nommée, et bornée à 20 s.
 */
describe('UpdateNotificationPreferencesForm — activation par étapes', () => {
    const bouton = (wrapper) => wrapper.find('[dusk="enable-push"]')
    const enAttente = () => new Promise(() => {})
    const worker = () => navigator.serviceWorker

    beforeEach(() => {
        // setImmediate reste réel : flushPromises s'en sert.
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    it('nomme l’étape sur le bouton pendant l’activation', async () => {
        const registration = await worker().ready
        registration.pushManager.subscribe.mockImplementationOnce(enAttente)

        const wrapper = mountForm()
        await bouton(wrapper).trigger('click')
        await flushPromises()

        expect(bouton(wrapper).text()).toContain('Abonnement')
        expect(bouton(wrapper).attributes('aria-busy')).toBe('true')

        wrapper.unmount()
    })

    it('abandonne un abonnement que le navigateur ne fournit jamais', async () => {
        const registration = await worker().ready
        registration.pushManager.subscribe.mockImplementationOnce(enAttente)

        const wrapper = mountForm()
        await bouton(wrapper).trigger('click')
        await flushPromises()
        await vi.advanceTimersByTimeAsync(20_000)
        await flushPromises()

        expect(error(wrapper).text()).toContain('« Abonnement »')
        expect(error(wrapper).text()).toContain('20 s')
        expect(bouton(wrapper).text()).toBe('Activer')
        expect(post).not.toHaveBeenCalled()
        expect(unsubscribe).not.toHaveBeenCalled()

        wrapper.unmount()
    })

    it('abandonne un service worker qui ne devient jamais actif', async () => {
        const actif = worker().ready
        worker().ready = enAttente()

        const wrapper = mountForm()
        await bouton(wrapper).trigger('click')
        await flushPromises()

        expect(bouton(wrapper).text()).toContain('Service worker')

        await vi.advanceTimersByTimeAsync(20_000)
        await flushPromises()

        expect(error(wrapper).text()).toContain('« Service worker »')
        expect(post).not.toHaveBeenCalled()

        worker().ready = actif
        wrapper.unmount()
    })

    it('rapporte le refus du serveur avec son message', async () => {
        post.mockRejectedValue({
            response: { status: 422, data: { message: 'L’endpoint doit désigner un hôte joignable et public.' } },
        })

        const wrapper = mountForm()
        await bouton(wrapper).trigger('click')
        await flushPromises()

        expect(error(wrapper).text()).toContain('« Enregistrement »')
        expect(error(wrapper).text()).toContain('hôte joignable')
        expect(unsubscribe).toHaveBeenCalledTimes(1)

        wrapper.unmount()
    })

    it('nomme le statut HTTP quand le serveur répond sans message', async () => {
        post.mockRejectedValue({ response: { status: 500, data: {} } })

        const wrapper = mountForm()
        await bouton(wrapper).trigger('click')
        await flushPromises()

        expect(error(wrapper).text()).toContain('HTTP 500')

        wrapper.unmount()
    })

    it('ne dépasse pas le délai quand chaque étape répond', async () => {
        post.mockResolvedValue({})

        const wrapper = mountForm()
        await bouton(wrapper).trigger('click')
        await flushPromises()
        await vi.advanceTimersByTimeAsync(20_000)

        expect(banner(wrapper).exists()).toBe(false)
        expect(error(wrapper).exists()).toBe(false)

        wrapper.unmount()
    })
})

/**
 * Le rappel part à 18 h les jours choisis ; la préférence est une liste de
 * numéros ISO (1 = lundi, 7 = dimanche), tous cochés par défaut.
 */
describe('UpdateNotificationPreferencesForm — jours de rappel', () => {
    const jour = (wrapper, iso) => wrapper.find(`[dusk="reminder-day-${iso}"] input[type="checkbox"]`)

    it('propose tous les jours par défaut et les envoie avec la sauvegarde', async () => {
        const wrapper = mountForm({ hasPushSubscription: true })

        expect(wrapper.findAll('[dusk^="reminder-day-"]')).toHaveLength(7)

        await wrapper.find('form').trigger('submit')
        await flushPromises()

        expect(patch.mock.calls.at(-1)[1].days.training_reminder).toEqual([1, 2, 3, 4, 5, 6, 7])

        wrapper.unmount()
    })

    it('reprend les jours enregistrés, puis coche et décoche en gardant la liste triée', async () => {
        const wrapper = mountForm({
            hasPushSubscription: true,
            preferences: { training_reminder: { is_enabled: true, is_push_enabled: false, days: [1, 3, 5] } },
        })

        expect(jour(wrapper, 1).element.checked).toBe(true)
        expect(jour(wrapper, 2).element.checked).toBe(false)

        await jour(wrapper, 7).setValue(true)
        await jour(wrapper, 1).setValue(false)
        await wrapper.find('form').trigger('submit')
        await flushPromises()

        expect(patch.mock.calls.at(-1)[1].days.training_reminder).toEqual([3, 5, 7])

        wrapper.unmount()
    })

    it('cache les jours quand le rappel est coupé', async () => {
        const wrapper = mountForm({
            hasPushSubscription: true,
            preferences: { training_reminder: { is_enabled: false, is_push_enabled: false, days: [1] } },
        })

        expect(wrapper.find('[dusk="reminder-days"]').exists()).toBe(false)

        wrapper.unmount()
    })
})

/**
 * Le serveur gardait un abonnement que le navigateur avait perdu (PWA
 * réinstallée) : la bannière restait cachée, les envois partaient dans le
 * vide, et rien ne permettait de se réabonner (vu en production le
 * 2026-09-04).
 */
describe('un abonnement que le navigateur a perdu', () => {
    it('ramène la bannière quand le navigateur n’a plus d’abonnement alors que le serveur en garde un', async () => {
        const wrapper = mountForm({ hasPushSubscription: true })
        expect(banner(wrapper).exists()).toBe(false)

        await flushPromises()

        expect(banner(wrapper).text()).toContain('Activer les notifications Push')
        expect(pushCheckboxes(wrapper)).toHaveLength(0)
        wrapper.unmount()
    })

    it('garde les cases quand le navigateur a encore son abonnement', async () => {
        const registration = await navigator.serviceWorker.ready
        registration.pushManager.getSubscription.mockResolvedValueOnce({
            endpoint: 'https://push.example/abc',
            unsubscribe,
        })

        const wrapper = mountForm({ hasPushSubscription: true })
        await flushPromises()

        expect(banner(wrapper).exists()).toBe(false)
        expect(pushCheckboxes(wrapper).length).toBeGreaterThan(0)
        wrapper.unmount()
    })
})
