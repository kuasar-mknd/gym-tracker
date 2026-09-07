import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'

const reseau = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@/Utils/http', () => ({ http: { post: (...args) => reseau.post(...args) } }))

import { useAbonnementPush } from '@/composables/useAbonnementPush'

const abonnement = (endpoint = 'https://push.example/abc') => ({
    endpoint,
    unsubscribe: vi.fn().mockResolvedValue(true),
})

const navigateur = ({ existant = null, souscrire = abonnement(), workerMuet = false } = {}) => {
    const pushManager = {
        getSubscription: vi.fn().mockResolvedValue(existant),
        subscribe: vi.fn().mockResolvedValue(souscrire),
    }
    Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: {
            ready: workerMuet
                ? Promise.reject(new Error('le navigateur n’a pas répondu en 20 s'))
                : Promise.resolve({ pushManager }),
        },
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

afterEach(() => {})

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

    it('rouvre la bannière quand le service worker ne répond pas', async () => {
        navigateur({ workerMuet: true })
        const push = monter({ dejaAbonne: true })
        await flushPromises()

        /*
         * Le cas qui a piégé un iPhone, et l’inverse exact du choix précédent.
         *
         * `dejaAbonne` répond pour le COMPTE et non pour l’appareil : un
         * abonnement pris sur un autre navigateur le rend vrai partout. Quand le
         * worker ne répondait pas, l’ancien code gardait cette valeur « pour ne
         * pas contredire le serveur » — et le téléphone se retrouvait sans
         * bannière, donc sans le seul bouton qui demande l’autorisation, sans
         * message, et sans aucun moyen d’en sortir.
         *
         * Un bandeau de trop se referme d’un clic ; un bandeau manquant ne se
         * rattrape par rien. En cas de doute, on montre.
         */
        expect(push.pushRegistered.value).toBe(false)
    })

    it('rend au serveur un abonnement que le navigateur tient et qu’il ignore', async () => {
        navigateur({ existant: abonnement('https://push.example/orphelin') })
        const push = monter({ dejaAbonne: false })
        await flushPromises()

        // Sans cela, l’abonnement existe dans le navigateur, la bannière ne
        // s’affiche pas puisque l’appareil est bien abonné, et aucun envoi ne
        // part jamais vers lui : personne n’a de raison de s’en apercevoir.
        expect(reseau.post).toHaveBeenCalledWith(
            '/push-subscriptions.update',
            expect.objectContaining({ endpoint: 'https://push.example/orphelin' }),
            expect.any(Object),
        )
        expect(push.pushRegistered.value).toBe(true)
    })

    it('n’écrit rien quand le serveur et le navigateur sont déjà d’accord', async () => {
        navigateur({ existant: abonnement() })
        monter({ dejaAbonne: true })
        await flushPromises()

        // Chaque écriture coûte de 350 ms à 1,7 s sur le NAS : la page de profil
        // n’a pas à en produire une à chaque ouverture.
        expect(reseau.post).not.toHaveBeenCalled()
    })
})
