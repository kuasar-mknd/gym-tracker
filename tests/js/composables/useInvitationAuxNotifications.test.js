import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * L'invitation aux notifications après une séance (#1848).
 *
 * `journal` garde l'ordre de ce que l'activation fait faire au navigateur et
 * au serveur : l'exigence d'iOS porte sur l'ORDRE (la permission dans le
 * geste, avant toute attente), et une liste d'appels sans ordre la laisserait
 * passer à l'envers.
 */
const journal = []

/**
 * L'abonnement part par `Utils/http`, comme depuis le profil ; l'écriture des
 * records par la file hors ligne, comme les préférences du profil.
 */
const reseau = vi.hoisted(() => ({ post: vi.fn(), patch: vi.fn() }))
vi.mock('@/Utils/http', () => ({
    http: { post: (...args) => reseau.post(...args) },
}))
vi.mock('@/Utils/SyncService', () => ({
    default: { patch: (...args) => reseau.patch(...args) },
}))

/**
 * Le module de l'abonnement garde ce que le chargement a déjà transmis : chaque
 * test part d'un chargement neuf, le `localStorage` restant celui de l'appareil.
 */
let useInvitationAuxNotifications

const chargementSuivant = async () => {
    vi.resetModules()
    ;({ useInvitationAuxNotifications } = await import('@/composables/useInvitationAuxNotifications'))
}

const UTILISATEUR = 42
const CLEF_DU_REFUS = 'gym-tracker:invitation-notifications-refusee'
const CLEF_DU_MEMO = 'gym-tracker:abonnement-push-transmis'

const abonnement = (endpoint = 'https://push.example/neuf') => ({
    endpoint,
    unsubscribe: vi.fn().mockResolvedValue(true),
    toJSON: () => ({ endpoint, keys: { p256dh: 'p', auth: 'a' } }),
})

/**
 * Un navigateur qui sait le push, dans l'état de permission donné.
 *
 * `reponse` est ce que l'invite système rendra ; la permission du navigateur
 * suit, comme dans un vrai navigateur.
 */
const navigateur = ({
    permission = 'default',
    reponse = 'granted',
    existant = null,
    souscrire = abonnement(),
} = {}) => {
    const pushManager = {
        getSubscription: vi.fn().mockResolvedValue(existant),
        subscribe: vi.fn().mockImplementation(async () => {
            journal.push('abonnement')

            return souscrire
        }),
    }

    Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: { ready: Promise.resolve({ pushManager }) },
    })

    const notification = {
        permission,
        requestPermission: vi.fn().mockImplementation(() => {
            journal.push('permission')
            notification.permission = reponse

            return Promise.resolve(reponse)
        }),
    }

    globalThis.Notification = notification
    Object.defineProperty(window, 'Notification', { configurable: true, writable: true, value: notification })

    return { pushManager, notification }
}

/** Un navigateur sans push : Safari sur iPhone, hors de l'application installée. */
const navigateurSansPush = () => {
    delete window.Notification
    delete globalThis.Notification
    Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: undefined })
    delete navigator.serviceWorker
}

let montages = []

const monter = ({ vapidPublicKey = 'BAbc', utilisateurId = UTILISATEUR, autreInvitation = ref(false) } = {}) => {
    let invitation
    const wrapper = mount(
        defineComponent({
            setup() {
                invitation = useInvitationAuxNotifications({
                    vapidPublicKey,
                    utilisateurId,
                    uneAutreInvitationPasseAvant: autreInvitation,
                })

                return () => h('div')
            },
        }),
    )
    montages.push(wrapper)

    return { ...invitation, autreInvitation }
}

/** Monte, puis laisse le navigateur répondre sur l'état de l'appareil. */
const monterEtVerifier = async (options) => {
    const invitation = monter(options)
    await flushPromises()

    return invitation
}

beforeEach(async () => {
    vi.clearAllMocks()
    journal.length = 0
    window.localStorage.clear()
    await chargementSuivant()
    globalThis.route = (nom) => `/${nom}`
    reseau.post.mockImplementation(async (url) => {
        journal.push(`post ${url}`)

        return {}
    })
    reseau.patch.mockImplementation(async (url) => {
        journal.push(`patch ${url}`)

        return { status: 204, data: null }
    })
})

afterEach(() => {
    for (const wrapper of montages.splice(0)) {
        wrapper.unmount()
    }

    vi.restoreAllMocks()
})

describe('quand l’invitation se propose', () => {
    it('se propose une fois l’appareil vérifié, quand rien ne s’y oppose', async () => {
        navigateur()
        const invitation = monter()

        // Pas avant que le navigateur ait dit ce que tient l'appareil : une carte
        // qui apparaît puis disparaît aussitôt se lit comme un défaut.
        expect(invitation.visible.value).toBe(false)

        await flushPromises()

        expect(invitation.visible.value).toBe(true)
    })

    it('se propose aussi quand la permission est accordée mais qu’aucun abonnement n’est tenu', async () => {
        navigateur({ permission: 'granted', existant: null })

        expect((await monterEtVerifier()).visible.value).toBe(true)
    })

    it('se tait quand le navigateur ne sait pas le push', async () => {
        navigateurSansPush()

        expect((await monterEtVerifier()).visible.value).toBe(false)
    })

    it('se tait quand le serveur n’a pas de clé VAPID', async () => {
        navigateur()

        expect((await monterEtVerifier({ vapidPublicKey: null })).visible.value).toBe(false)
    })

    it('se tait quand la permission a déjà été refusée', async () => {
        const { pushManager } = navigateur({ permission: 'denied' })

        expect((await monterEtVerifier()).visible.value).toBe(false)
        // Rien à demander : la page ne peut plus redemander un refus du système.
        expect(pushManager.getSubscription).not.toHaveBeenCalled()
    })

    it('se tait quand l’appareil tient déjà un abonnement transmis pour ce compte', async () => {
        navigateur({ permission: 'granted', existant: abonnement('https://push.example/transmis') })
        window.localStorage.setItem(
            CLEF_DU_MEMO,
            JSON.stringify({ utilisateur: String(UTILISATEUR), endpoint: 'https://push.example/transmis' }),
        )

        expect((await monterEtVerifier()).visible.value).toBe(false)
    })

    it('se tait sur un appareil donné à un autre compte, sans le lui prendre', async () => {
        navigateur({ permission: 'granted', existant: abonnement('https://push.example/de-7') })
        window.localStorage.setItem(
            CLEF_DU_MEMO,
            JSON.stringify({ utilisateur: '7', endpoint: 'https://push.example/de-7' }),
        )

        const invitation = await monterEtVerifier()

        // Seule l'activation du profil fait changer un appareil de mains
        // (.ai/rules/js.md) : une carte d'accueil ne doit pas l'y inviter.
        expect(invitation.visible.value).toBe(false)
        expect(reseau.post).not.toHaveBeenCalled()
    })

    it('ne revient plus sur cet appareil après un refus', async () => {
        navigateur()
        const premiere = await monterEtVerifier()

        premiere.refuser()

        expect(premiere.visible.value).toBe(false)
        expect(window.localStorage.getItem(CLEF_DU_REFUS)).not.toBeNull()

        // La séance suivante : un autre montage, après un autre chargement.
        await chargementSuivant()
        navigateur()

        expect((await monterEtVerifier()).visible.value).toBe(false)
    })

    it('reste possible, et se ferme, quand le stockage est bloqué', async () => {
        navigateur()
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('stockage refusé')
        })
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('stockage refusé')
        })

        const invitation = await monterEtVerifier()

        expect(invitation.visible.value).toBe(true)
        expect(() => invitation.refuser()).not.toThrow()
        expect(invitation.visible.value).toBe(false)
    })
})

describe('une seule carte à la fois', () => {
    it('cède la place à l’invitation d’installation quand elle est déjà là', async () => {
        navigateur()

        const invitation = await monterEtVerifier({ autreInvitation: ref(true) })

        expect(invitation.visible.value).toBe(false)
    })

    it('se retire si l’invitation d’installation arrive après elle, et attend une autre séance', async () => {
        navigateur()
        const invitation = await monterEtVerifier()

        expect(invitation.visible.value).toBe(true)

        // `beforeinstallprompt` arrive quand il veut.
        invitation.autreInvitation.value = true
        await nextTick()

        expect(invitation.visible.value).toBe(false)

        // Refermer l'autre ne la fait pas revenir : elle attend la séance suivante.
        invitation.autreInvitation.value = false
        await nextTick()

        expect(invitation.visible.value).toBe(false)
        expect(window.localStorage.getItem(CLEF_DU_REFUS)).toBeNull()
    })
})

describe('l’activation', () => {
    it('demande la permission dans le geste, puis s’abonne, puis allume les records sur le serveur', async () => {
        const { notification } = navigateur()
        const invitation = await monterEtVerifier()

        const activation = invitation.activer()

        // Avant toute attente : iOS n'ouvre l'invite que dans le geste lui-même.
        expect(notification.requestPermission).toHaveBeenCalledTimes(1)

        await activation

        expect(journal).toEqual([
            'permission',
            'abonnement',
            'post /push-subscriptions.update',
            'patch /profile.push-preferences.update',
        ])
        expect(reseau.patch).toHaveBeenCalledWith(
            '/profile.push-preferences.update',
            { types: ['personal_record'] },
            expect.any(Object),
        )
        expect(invitation.activee.value).toBe(true)
        expect(invitation.erreur.value).toBeNull()
    })

    it('n’attend rien avant de demander la permission, même après une première tentative', async () => {
        const { notification } = navigateur()
        notification.requestPermission.mockImplementationOnce(() => {
            journal.push('permission')

            return Promise.reject(new Error('invite interrompue'))
        })
        const invitation = await monterEtVerifier()
        await invitation.activer()

        // Une invite qui lève n'est pas un refus : la carte reste, sans mémo.
        expect(invitation.visible.value).toBe(true)
        expect(invitation.erreur.value).toContain('Permission')
        expect(window.localStorage.getItem(CLEF_DU_REFUS)).toBeNull()

        const seconde = invitation.activer()

        expect(notification.requestPermission).toHaveBeenCalledTimes(2)
        await seconde

        expect(invitation.activee.value).toBe(true)
    })

    it('nomme l’étape en cours, jusqu’à l’écriture des records', async () => {
        navigateur()
        let ecrire
        reseau.patch.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    ecrire = resolve
                }),
        )
        const invitation = await monterEtVerifier()

        const activation = invitation.activer()

        // Le bouton dit où l'on en est : une étape muette tourne sans fin.
        expect(invitation.enCours.value).toBe(true)
        expect(invitation.etapeEnCours.value).toBe('Permission')

        await flushPromises()

        expect(invitation.etapeEnCours.value).toBe('Préférences')

        ecrire({ status: 204, data: null })
        await activation

        expect(invitation.enCours.value).toBe(false)
        expect(invitation.etapeEnCours.value).toBeNull()
    })

    it('ne lance qu’une activation sur un double appui', async () => {
        const { notification } = navigateur()
        const invitation = await monterEtVerifier()

        await Promise.all([invitation.activer(), invitation.activer()])

        expect(notification.requestPermission).toHaveBeenCalledTimes(1)
        expect(reseau.patch).toHaveBeenCalledTimes(1)
    })

    it.each([
        ['refusée', 'denied'],
        ['laissée sans réponse', 'default'],
    ])('se retire pour de bon quand la permission est %s, sans rien écrire', async (_cas, reponse) => {
        navigateur({ reponse })
        const invitation = await monterEtVerifier()

        await invitation.activer()

        // Sans cela, la carte revenait à chaque séance pour une invite que
        // l'utilisateur venait d'écarter.
        expect(invitation.visible.value).toBe(false)
        expect(window.localStorage.getItem(CLEF_DU_REFUS)).not.toBeNull()
        expect(journal).toEqual(['permission'])

        await chargementSuivant()
        navigateur({ permission: reponse })

        expect((await monterEtVerifier()).visible.value).toBe(false)
    })

    it('reste, et dit l’étape, quand l’abonnement échoue', async () => {
        const { pushManager } = navigateur()
        pushManager.subscribe.mockRejectedValue(new Error('service push injoignable'))
        const invitation = await monterEtVerifier()

        await invitation.activer()

        expect(invitation.visible.value).toBe(true)
        expect(invitation.activee.value).toBe(false)
        expect(invitation.erreur.value).toContain('Abonnement')
        expect(reseau.patch).not.toHaveBeenCalled()
        // Un échec technique n'est pas un refus : la carte reviendra.
        expect(window.localStorage.getItem(CLEF_DU_REFUS)).toBeNull()
    })

    it('réessaie la seule écriture des records quand elle a échoué, sans redemander l’abonnement', async () => {
        const { notification, pushManager } = navigateur()
        reseau.patch.mockRejectedValueOnce({ response: { status: 500, data: {} } })
        const invitation = await monterEtVerifier()

        await invitation.activer()

        // Abonné mais rien d'allumé : une activation qui n'enverrait rien. La
        // carte le dit et garde son bouton.
        expect(invitation.visible.value).toBe(true)
        expect(invitation.activee.value).toBe(false)
        expect(invitation.erreur.value).toContain('records')

        await invitation.activer()

        expect(notification.requestPermission).toHaveBeenCalledTimes(1)
        expect(pushManager.subscribe).toHaveBeenCalledTimes(1)
        expect(reseau.patch).toHaveBeenCalledTimes(2)
        expect(invitation.activee.value).toBe(true)
        expect(invitation.erreur.value).toBeNull()
    })

    it('confie à la file hors ligne l’écriture des records quand le réseau lâche après l’abonnement', async () => {
        navigateur()
        reseau.patch.mockRejectedValueOnce({ isOffline: true, queueId: 'file-1' })
        const invitation = await monterEtVerifier()

        await invitation.activer()

        // La file la rejouera au retour du réseau : l'appareil, abonné, ne
        // restera pas sans rien d'allumé.
        expect(invitation.activee.value).toBe(true)
        expect(invitation.erreur.value).toBeNull()
    })

    it('se referme après la confirmation, sans enregistrer de refus', async () => {
        navigateur()
        const invitation = await monterEtVerifier()
        await invitation.activer()

        expect(invitation.visible.value).toBe(true)

        invitation.fermer()

        expect(invitation.visible.value).toBe(false)
        expect(window.localStorage.getItem(CLEF_DU_REFUS)).toBeNull()
    })

    it('garde la carte quand l’invitation d’installation arrive pendant l’activation', async () => {
        navigateur()
        const invitation = await monterEtVerifier()

        const activation = invitation.activer()
        invitation.autreInvitation.value = true
        await activation
        await nextTick()

        // L'utilisateur a déjà répondu : lui retirer la carte lui cacherait le
        // résultat de son geste.
        expect(invitation.visible.value).toBe(true)
        expect(invitation.activee.value).toBe(true)
    })
})
