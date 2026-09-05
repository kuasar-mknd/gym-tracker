import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'

const reseau = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('axios', () => ({ default: { post: (...args) => reseau.post(...args) } }))

import { useAbonnementPush } from '@/composables/useAbonnementPush'

const abonnement = (endpoint = 'https://push.example/abc') => ({
    endpoint,
    unsubscribe: vi.fn().mockResolvedValue(true),
})

const navigateur = ({ existant = null, souscrire = abonnement() } = {}) => {
    const pushManager = {
        getSubscription: vi.fn().mockResolvedValue(existant),
        subscribe: vi.fn().mockResolvedValue(souscrire),
    }
    Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: { ready: Promise.resolve({ pushManager }) },
    })
    globalThis.Notification = { requestPermission: vi.fn().mockResolvedValue('granted') }
    Object.defineProperty(window, 'Notification', {
        configurable: true,
        writable: true,
        value: globalThis.Notification,
    })

    return pushManager
}

const monter = ({ dejaAbonne = false, vapidPublicKey = 'BAbc' } = {}) => {
    const apresAbonnement = vi.fn()
    let push
    const wrapper = mount(
        defineComponent({
            setup() {
                push = useAbonnementPush({ vapidPublicKey, dejaAbonne, apresAbonnement })

                return () => h('div')
            },
        }),
    )

    return { wrapper, apresAbonnement, ...push }
}

beforeEach(() => {
    vi.clearAllMocks()
    reseau.post.mockResolvedValue({})
    globalThis.route = (nom) => `/${nom}`
})

afterEach(() => {
    delete window.axios
})

describe('activer les notifications push', () => {
    it('s’abonne, enregistre l’abonnement, puis rend la main au formulaire', async () => {
        const gestionnaire = navigateur()
        const push = monter()

        await push.enablePush()

        expect(gestionnaire.subscribe).toHaveBeenCalledWith(expect.objectContaining({ userVisibleOnly: true }))
        expect(reseau.post).toHaveBeenCalledWith(
            '/push-subscriptions.update',
            expect.objectContaining({ endpoint: 'https://push.example/abc' }),
            expect.any(Object),
        )
        expect(push.pushRegistered.value).toBe(true)
        expect(push.apresAbonnement).toHaveBeenCalledTimes(1)
        expect(push.isSubscribing.value).toBe(false)
        expect(push.pushError.value).toBeNull()
    })

    it('dit où ça bloque quand le navigateur refuse, sans rien enregistrer', async () => {
        navigateur()
        globalThis.Notification.requestPermission.mockResolvedValue('denied')
        const push = monter()

        await push.enablePush()

        expect(push.pushError.value).toContain('refusé')
        expect(reseau.post).not.toHaveBeenCalled()
        expect(push.apresAbonnement).not.toHaveBeenCalled()
    })

    it('oublie d’abord un abonnement périmé, côté navigateur comme côté serveur', async () => {
        const perime = abonnement('https://push.example/vieux')
        navigateur({ existant: perime })
        const push = monter()

        await push.enablePush()

        expect(perime.unsubscribe).toHaveBeenCalledTimes(1)
        expect(reseau.post).toHaveBeenCalledWith(
            '/push-subscriptions.destroy',
            { endpoint: 'https://push.example/vieux' },
            expect.any(Object),
        )
        expect(push.pushRegistered.value).toBe(true)
    })

    it('abandonne un abonnement que le serveur n’a pas gardé, et nomme l’étape qui a échoué', async () => {
        const neuf = abonnement()
        navigateur({ souscrire: neuf })
        reseau.post.mockImplementation((url) =>
            url.endsWith('push-subscriptions.update')
                ? Promise.reject({ response: { status: 500, data: {} } })
                : Promise.resolve({}),
        )
        const push = monter()

        await push.enablePush()

        expect(neuf.unsubscribe).toHaveBeenCalledTimes(1)
        expect(reseau.post).toHaveBeenCalledWith(
            '/push-subscriptions.destroy',
            { endpoint: neuf.endpoint },
            expect.any(Object),
        )
        expect(push.pushRegistered.value).toBe(false)
        expect(push.pushError.value).toContain('Enregistrement')
        expect(push.pushError.value).toContain('HTTP 500')
        expect(push.apresAbonnement).not.toHaveBeenCalled()
    })
})

describe('l’état de l’abonnement au montage', () => {
    it('ne contredit pas le serveur quand le navigateur tient encore l’abonnement', async () => {
        navigateur({ existant: abonnement() })
        const push = monter({ dejaAbonne: true })
        await flushPromises()

        expect(push.pushRegistered.value).toBe(true)
    })

    it('rouvre la bannière quand le navigateur a perdu l’abonnement que le serveur garde', async () => {
        navigateur({ existant: null })
        const push = monter({ dejaAbonne: true })
        await flushPromises()

        expect(push.pushRegistered.value).toBe(false)
    })
})
