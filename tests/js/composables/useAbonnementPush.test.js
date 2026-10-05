import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'

const reseau = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@/Utils/http', () => ({ http: { post: (...args) => reseau.post(...args) } }))

/**
 * Le module garde ce que le chargement en cours a déjà transmis, pour ne pas
 * écrire deux fois quand le layout et le profil rapprochent ensemble. Chaque
 * test part donc d'un chargement neuf, et `chargementSuivant()` simule la
 * réouverture de l'application : le module repart de zéro, le `localStorage`
 * reste.
 */
let useAbonnementPush
let rapprocherLAbonnementPush
let marquerLAbonnementARetransmettre
let retransmettreLAbonnementPush
let appareilDonneAUnAutreCompte

const chargementSuivant = async () => {
    vi.resetModules()
    ;({
        useAbonnementPush,
        rapprocherLAbonnementPush,
        marquerLAbonnementARetransmettre,
        retransmettreLAbonnementPush,
        appareilDonneAUnAutreCompte,
    } = await import('@/composables/useAbonnementPush'))
}

const UTILISATEUR = 42

const abonnement = (endpoint = 'https://push.example/abc') => ({
    endpoint,
    unsubscribe: vi.fn().mockResolvedValue(true),
    toJSON: () => ({ endpoint, keys: { p256dh: 'p', auth: 'a' } }),
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

const monter = ({ dejaAbonne = false, vapidPublicKey = 'BAbc', utilisateurId = UTILISATEUR } = {}) => {
    const apresAbonnement = vi.fn()
    let push
    const wrapper = mount(
        defineComponent({
            setup() {
                push = useAbonnementPush({ vapidPublicKey, dejaAbonne, apresAbonnement, utilisateurId })

                return () => h('div')
            },
        }),
    )

    return { wrapper, apresAbonnement, ...push }
}

/** Les écritures parties, par route : `[[route, adresse], …]`. */
const ecritures = () => reseau.post.mock.calls.map(([url, corps]) => [url.slice(1), corps.endpoint])

/** Un chargement précédent a transmis cet abonnement pour ce compte. */
const dejaTransmis = async (endpoint, utilisateur = UTILISATEUR) => {
    const pushManager = navigateur({ existant: abonnement(endpoint) })
    await rapprocherLAbonnementPush(utilisateur)
    await chargementSuivant()
    reseau.post.mockClear()

    return pushManager
}

beforeEach(async () => {
    vi.clearAllMocks()
    window.localStorage.clear()
    await chargementSuivant()
    reseau.post.mockResolvedValue({})
    globalThis.route = (nom) => `/${nom}`
})

afterEach(() => {
    vi.restoreAllMocks()
})

describe('activer les notifications push', () => {
    it('s’abonne, enregistre l’abonnement, puis rend la main au formulaire', async () => {
        const gestionnaire = navigateur()
        const push = monter()

        expect(await push.enablePush()).toBe('abonne')

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

        // Un refus se distingue d'une panne : l'invitation de l'accueil ne se
        // représente pas après un refus, et reste après une panne.
        expect(await push.enablePush()).toBe('refuse')

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

        expect(await push.enablePush()).toBe('echoue')

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

    it('se souvient de l’abonnement activé : l’ouverture suivante n’écrit rien', async () => {
        const gestionnaire = await dejaTransmis('https://push.example/vieux')
        const neuf = abonnement('https://push.example/neuf')
        gestionnaire.subscribe.mockResolvedValue(neuf)
        const push = monter({ dejaAbonne: true })
        await flushPromises()

        await push.enablePush()
        gestionnaire.getSubscription.mockResolvedValue(neuf)
        await chargementSuivant()
        reseau.post.mockClear()
        await rapprocherLAbonnementPush(UTILISATEUR)

        // L'activation a déjà enregistré le nouveau et fait oublier l'ancien ;
        // sans mémo à jour, l'ouverture suivante referait les deux écritures.
        expect(reseau.post).not.toHaveBeenCalled()
    })

    it('n’a plus rien à faire oublier à l’ouverture suivante après une activation manquée', async () => {
        const gestionnaire = await dejaTransmis('https://push.example/vieux')
        const push = monter({ dejaAbonne: true })
        await flushPromises()
        reseau.post.mockImplementation((url) =>
            url.endsWith('push-subscriptions.update')
                ? Promise.reject({ response: { status: 500, data: {} } })
                : Promise.resolve({}),
        )

        await push.enablePush()
        gestionnaire.getSubscription.mockResolvedValue(null)
        await chargementSuivant()
        reseau.post.mockReset()
        await rapprocherLAbonnementPush(UTILISATEUR)

        // L'activation a déjà fait oublier l'ancienne adresse au serveur ; le
        // navigateur n'en tient plus aucune, et il n'y a rien à refaire.
        expect(reseau.post).not.toHaveBeenCalled()
    })
})

describe('l’état de l’abonnement au montage', () => {
    it('dit quand le navigateur a répondu sur l’état de l’appareil, même muet', async () => {
        navigateur({ existant: abonnement() })
        const repondu = monter()

        // Avant la réponse, `pushRegistered` n'est que la valeur du serveur.
        expect(repondu.appareilVerifie.value).toBe(false)
        await flushPromises()
        expect(repondu.appareilVerifie.value).toBe(true)

        navigateur({ workerMuet: true })
        const muet = monter()
        await flushPromises()

        expect(muet.appareilVerifie.value).toBe(true)
    })

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
        await dejaTransmis('https://push.example/orphelin')
        const push = monter({ dejaAbonne: false })
        await flushPromises()

        // Le serveur dit ne rien avoir pour ce compte : le mémo de l'appareil
        // est démenti (base restaurée, ligne supprimée à la main), et c'est le
        // serveur qui a raison. Sans cela, l’abonnement existe dans le
        // navigateur, la bannière ne s’affiche pas puisque l’appareil est bien
        // abonné, et aucun envoi ne part jamais vers lui.
        expect(ecritures()).toEqual([['push-subscriptions.update', 'https://push.example/orphelin']])
        expect(push.pushRegistered.value).toBe(true)
    })

    it('n’écrit rien quand le serveur et le navigateur sont déjà d’accord', async () => {
        await dejaTransmis('https://push.example/abc')
        monter({ dejaAbonne: true })
        await flushPromises()

        // Chaque écriture coûte de 350 ms à 1,7 s en production : la page de profil
        // n’a pas à en produire une à chaque ouverture.
        expect(reseau.post).not.toHaveBeenCalled()
    })

    it('n’écrit qu’une fois quand le profil et le layout rapprochent ensemble', async () => {
        navigateur({ existant: abonnement('https://push.example/abc') })

        // Le profil monte avant le layout qui l'entoure ; les deux rapprochent.
        monter({ dejaAbonne: false })
        await rapprocherLAbonnementPush(UTILISATEUR)
        await flushPromises()

        expect(ecritures()).toEqual([['push-subscriptions.update', 'https://push.example/abc']])
    })

    it('rend au serveur l’abonnement que le layout a jugé à jour, quand le serveur dit n’en tenir aucun', async () => {
        await dejaTransmis('https://push.example/abc')

        // L'ordre d'un chargement complet du profil : le layout rapproche le
        // premier, dès son montage, et sur la foi du mémo il n'écrit rien ; le
        // formulaire ne rapproche qu'après avoir interrogé le navigateur, et
        // c'est lui qui sait que le serveur a perdu la ligne.
        const layout = rapprocherLAbonnementPush(UTILISATEUR)
        const push = monter({ dejaAbonne: false })
        await layout
        await flushPromises()

        expect(ecritures()).toEqual([['push-subscriptions.update', 'https://push.example/abc']])
        expect(push.pushRegistered.value).toBe(true)
    })

    it('rouvre la bannière quand le serveur refuse l’abonnement que le navigateur tient', async () => {
        navigateur({ existant: abonnement('https://push.example/orphelin') })
        reseau.post.mockRejectedValue({ response: { status: 422, data: {} } })
        const push = monter({ dejaAbonne: false })
        await flushPromises()

        // Adresse refusée, serveur en panne, réseau coupé : le serveur ne tient
        // rien pour cet appareil. Sans bandeau, les cases d'envoi en push
        // s'afficheraient pour des envois que personne ne reçoit.
        expect(push.pushRegistered.value).toBe(false)
    })

    it('propose l’activation sur un appareil qui reçoit pour un autre compte, sans le lui prendre', async () => {
        await dejaTransmis('https://push.example/partage', 7)
        const push = monter({ dejaAbonne: false })
        await flushPromises()

        // L'appareil ne reçoit pas pour ce compte : le bandeau reste, et c'est
        // par lui, d'un geste, que l'appareil change de compte.
        expect(reseau.post).not.toHaveBeenCalled()
        expect(push.pushRegistered.value).toBe(false)
    })
})

describe('le rapprochement à l’ouverture de l’application', () => {
    it('transmet un abonnement que cet appareil n’a jamais transmis, même si le compte en a un', async () => {
        // `dejaAbonne` répond pour le compte : un autre appareil, ou l'ancienne
        // adresse de celui-ci, suffisait à taire l'envoi. Un abonnement remplacé
        // n'était alors jamais transmis (#1847).
        navigateur({ existant: abonnement('https://push.example/remplacant') })

        await rapprocherLAbonnementPush(UTILISATEUR)

        expect(ecritures()).toEqual([['push-subscriptions.update', 'https://push.example/remplacant']])
    })

    it('n’écrit rien quand l’abonnement est celui déjà transmis', async () => {
        const gestionnaire = await dejaTransmis('https://push.example/abc')

        await rapprocherLAbonnementPush(UTILISATEUR)

        expect(gestionnaire.getSubscription).toHaveBeenCalled()
        expect(reseau.post).not.toHaveBeenCalled()
    })

    it('enregistre le nouvel abonnement puis fait oublier l’ancien quand le navigateur en a changé', async () => {
        const gestionnaire = await dejaTransmis('https://push.example/ancien')
        gestionnaire.getSubscription.mockResolvedValue(abonnement('https://push.example/nouveau'))

        await rapprocherLAbonnementPush(UTILISATEUR)

        // Le cas d'iPhone, où le worker ne reçoit pas `pushsubscriptionchange` :
        // la réparation se fait ici, à l'ouverture suivante.
        expect(ecritures()).toEqual([
            ['push-subscriptions.update', 'https://push.example/nouveau'],
            ['push-subscriptions.destroy', 'https://push.example/ancien'],
        ])
    })

    it('fait oublier au serveur un abonnement que le navigateur a perdu, une seule fois', async () => {
        const gestionnaire = await dejaTransmis('https://push.example/revoque')
        gestionnaire.getSubscription.mockResolvedValue(null)

        await rapprocherLAbonnementPush(UTILISATEUR)
        await chargementSuivant()
        await rapprocherLAbonnementPush(UTILISATEUR)

        // WebKit révoque sans prévenir : sans cela, le serveur écrit à chaque
        // notification vers une adresse que plus personne ne lit.
        expect(ecritures()).toEqual([['push-subscriptions.destroy', 'https://push.example/revoque']])
    })

    it('laisse à son compte l’abonnement que cet appareil a transmis pour un autre', async () => {
        // Deux comptes sur le même téléphone. Le serveur réattribue une adresse
        // au dernier compte qui l'enregistre : la transmettre pour celui qui se
        // connecte enverrait SES notifications sur l'écran verrouillé de
        // l'autre, même après sa déconnexion, et l'autre cesserait de recevoir.
        await dejaTransmis('https://push.example/partage', 7)

        await rapprocherLAbonnementPush(UTILISATEUR)

        expect(reseau.post).not.toHaveBeenCalled()

        // Celui qui revient retrouve l'appareil tel qu'il l'avait laissé.
        await chargementSuivant()
        await rapprocherLAbonnementPush(7)

        expect(reseau.post).not.toHaveBeenCalled()
    })

    it('garde à son compte un abonnement renouvelé pendant qu’un autre est connecté', async () => {
        const gestionnaire = await dejaTransmis('https://push.example/ancien', 7)
        gestionnaire.getSubscription.mockResolvedValue(abonnement('https://push.example/nouveau'))

        await rapprocherLAbonnementPush(UTILISATEUR)

        expect(reseau.post).not.toHaveBeenCalled()

        // La réparation attend le compte à qui l'appareil a transmis.
        await chargementSuivant()
        await rapprocherLAbonnementPush(7)

        expect(ecritures()).toEqual([
            ['push-subscriptions.update', 'https://push.example/nouveau'],
            ['push-subscriptions.destroy', 'https://push.example/ancien'],
        ])
    })

    it('ne fait changer l’appareil de compte que par l’activation', async () => {
        const gestionnaire = await dejaTransmis('https://push.example/de-7', 7)
        const neuf = abonnement('https://push.example/de-42')
        gestionnaire.subscribe.mockResolvedValue(neuf)
        const push = monter({ dejaAbonne: false })
        await flushPromises()

        await push.enablePush()

        expect(push.pushRegistered.value).toBe(true)

        gestionnaire.getSubscription.mockResolvedValue(neuf)
        await chargementSuivant()
        reseau.post.mockClear()
        await rapprocherLAbonnementPush(7)
        await rapprocherLAbonnementPush(UTILISATEUR)

        // L'appareil est désormais au compte qui l'a activé : celui d'avant ne
        // le reprend pas en revenant, et le nouveau n'a rien à refaire.
        expect(reseau.post).not.toHaveBeenCalled()
    })

    it('ne fait rien oublier pour le compte d’un autre', async () => {
        const gestionnaire = await dejaTransmis('https://push.example/de-l-autre', 7)
        gestionnaire.getSubscription.mockResolvedValue(null)

        await rapprocherLAbonnementPush(UTILISATEUR)

        expect(reseau.post).not.toHaveBeenCalled()
    })

    it('ne rapproche qu’une fois par chargement, le layout se remontant à chaque page', async () => {
        const gestionnaire = navigateur({ existant: abonnement() })

        await rapprocherLAbonnementPush(UTILISATEUR)
        await rapprocherLAbonnementPush(UTILISATEUR)

        expect(gestionnaire.getSubscription).toHaveBeenCalledTimes(1)
        expect(reseau.post).toHaveBeenCalledTimes(1)
    })

    it('réessaie à l’ouverture suivante quand le serveur a refusé', async () => {
        navigateur({ existant: abonnement('https://push.example/abc') })
        reseau.post.mockRejectedValueOnce({ response: { status: 500, data: {} } })

        // Rien n'est rejeté : le refus se dit par un `false`, qui rouvre le bandeau du profil.
        await expect(rapprocherLAbonnementPush(UTILISATEUR)).resolves.toBe(false)
        await chargementSuivant()
        await rapprocherLAbonnementPush(UTILISATEUR)

        expect(ecritures()).toEqual([
            ['push-subscriptions.update', 'https://push.example/abc'],
            ['push-subscriptions.update', 'https://push.example/abc'],
        ])
    })

    it('garde l’adresse à oublier tant que le serveur ne l’a pas oubliée', async () => {
        const gestionnaire = await dejaTransmis('https://push.example/revoque')
        gestionnaire.getSubscription.mockResolvedValue(null)
        reseau.post.mockRejectedValueOnce(new Error('réseau'))

        await rapprocherLAbonnementPush(UTILISATEUR)
        await chargementSuivant()
        await rapprocherLAbonnementPush(UTILISATEUR)

        expect(ecritures()).toEqual([
            ['push-subscriptions.destroy', 'https://push.example/revoque'],
            ['push-subscriptions.destroy', 'https://push.example/revoque'],
        ])
    })

    it('ne casse rien quand le stockage est bloqué', async () => {
        // Navigation privée de Safari, stockage refusé : on transmet à chaque
        // ouverture, une écriture de trop plutôt qu'un abonnement perdu.
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new DOMException('refusé', 'SecurityError')
        })
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new DOMException('refusé', 'SecurityError')
        })
        navigateur({ existant: abonnement('https://push.example/abc') })

        await expect(rapprocherLAbonnementPush(UTILISATEUR)).resolves.toBe(true)

        expect(ecritures()).toEqual([['push-subscriptions.update', 'https://push.example/abc']])
    })

    it('lit un mémo illisible comme une absence de mémo', async () => {
        window.localStorage.setItem('gym-tracker:abonnement-push-transmis', '{pas du json')
        navigateur({ existant: abonnement('https://push.example/abc') })

        await rapprocherLAbonnementPush(UTILISATEUR)

        expect(ecritures()).toEqual([['push-subscriptions.update', 'https://push.example/abc']])
    })

    it('se tait quand le service worker ne répond pas', async () => {
        navigateur({ workerMuet: true })

        await expect(rapprocherLAbonnementPush(UTILISATEUR)).resolves.toBe(false)

        expect(reseau.post).not.toHaveBeenCalled()
    })

    it('ne fait rien sans compte connu', async () => {
        const gestionnaire = navigateur({ existant: abonnement() })

        await rapprocherLAbonnementPush(undefined)

        expect(gestionnaire.getSubscription).not.toHaveBeenCalled()
        expect(reseau.post).not.toHaveBeenCalled()
    })

    it('ne fait rien là où le navigateur ne connaît pas le push', async () => {
        navigateur({ existant: abonnement() })
        delete window.Notification

        await rapprocherLAbonnementPush(UTILISATEUR)

        expect(reseau.post).not.toHaveBeenCalled()
    })
})

/** Une promesse que le test règle quand il le décide. */
const differee = () => {
    let regler
    const promesse = new Promise((resolve) => {
        regler = resolve
    })

    return { promesse, regler }
}

describe('après un changement de mot de passe', () => {
    /*
     * Le serveur retire tous les abonnements du compte quand le mot de passe
     * change (DetacheSesAppareilsPush), celui de l'appareil qui l'a changé
     * compris : il ne sait pas lequel est le sien. Le mémo de chaque appareil
     * disait pourtant « déjà transmis », et aucun rapprochement ne réécrivait
     * plus.
     */
    it('rend au serveur l’abonnement de l’appareil qui a changé le mot de passe, puis n’écrit plus rien', async () => {
        const gestionnaire = await dejaTransmis('https://push.example/telephone')

        await expect(retransmettreLAbonnementPush(UTILISATEUR)).resolves.toBe(true)

        expect(ecritures()).toEqual([['push-subscriptions.update', 'https://push.example/telephone']])

        // Le mémo est de nouveau à jour : l'ouverture suivante n'écrit rien.
        await chargementSuivant()
        reseau.post.mockClear()
        await rapprocherLAbonnementPush(UTILISATEUR)

        expect(gestionnaire.getSubscription).toHaveBeenCalled()
        expect(reseau.post).not.toHaveBeenCalled()
    })

    it('retransmet à l’ouverture suivante quand la retransmission a échoué', async () => {
        await dejaTransmis('https://push.example/telephone')
        reseau.post.mockRejectedValueOnce(new Error('réseau'))

        await expect(retransmettreLAbonnementPush(UTILISATEUR)).resolves.toBe(false)
        await chargementSuivant()
        await rapprocherLAbonnementPush(UTILISATEUR)

        expect(ecritures()).toEqual([
            ['push-subscriptions.update', 'https://push.example/telephone'],
            ['push-subscriptions.update', 'https://push.example/telephone'],
        ])
    })

    it('attend le rapprochement en vol, qui noterait sinon une transmission que le serveur vient d’effacer', async () => {
        const gestionnaire = navigateur()
        const ecritureDuChargement = differee()
        const lectureDeLaRetransmission = differee()
        gestionnaire.getSubscription
            .mockResolvedValueOnce(abonnement('https://push.example/telephone'))
            .mockReturnValueOnce(lectureDeLaRetransmission.promesse)
        reseau.post.mockReturnValueOnce(ecritureDuChargement.promesse)

        // Le layout a transmis à l'ouverture ; le mot de passe change avant
        // que sa réponse arrive, et le serveur efface la ligne.
        const duChargement = rapprocherLAbonnementPush(UTILISATEUR)
        await flushPromises()
        const retransmission = retransmettreLAbonnementPush(UTILISATEUR)
        await flushPromises()
        ecritureDuChargement.regler({})
        await flushPromises()
        lectureDeLaRetransmission.regler(abonnement('https://push.example/telephone'))
        await duChargement
        await retransmission

        expect(ecritures()).toEqual([
            ['push-subscriptions.update', 'https://push.example/telephone'],
            ['push-subscriptions.update', 'https://push.example/telephone'],
        ])
    })

    it('laisse à son compte un appareil donné à un autre, sans rien écrire', async () => {
        await dejaTransmis('https://push.example/partage', 7)

        await expect(retransmettreLAbonnementPush(UTILISATEUR)).resolves.toBe(false)

        expect(reseau.post).not.toHaveBeenCalled()
        expect(appareilDonneAUnAutreCompte(UTILISATEUR)).toBe(true)
    })

    it('ne fait rien là où le navigateur ne connaît pas le push', async () => {
        navigateur({ existant: abonnement() })
        delete window.Notification

        await expect(retransmettreLAbonnementPush(UTILISATEUR)).resolves.toBe(false)

        expect(reseau.post).not.toHaveBeenCalled()
    })
})

describe('la connexion qui suit une session fermée', () => {
    /*
     * Une page d'invité marque l'abonnement : la session a pu être fermée par
     * un changement de mot de passe fait ailleurs, qui a retiré la ligne de
     * cet appareil. Sans la marque, l'appareil qui se reconnecte ne
     * transmettait plus rien, et le profil montrait les cases d'envoi en push
     * pour des envois qui ne lui parvenaient plus.
     */
    it('retransmet une fois l’abonnement que le mémo disait déjà transmis', async () => {
        await dejaTransmis('https://push.example/tablette')

        marquerLAbonnementARetransmettre()
        await rapprocherLAbonnementPush(UTILISATEUR)
        await chargementSuivant()
        await rapprocherLAbonnementPush(UTILISATEUR)

        expect(ecritures()).toEqual([['push-subscriptions.update', 'https://push.example/tablette']])
    })

    it('oublie ce que ce chargement avait déjà rapproché, la connexion se faisant sans recharger la page', async () => {
        navigateur({ existant: abonnement('https://push.example/tablette') })
        await rapprocherLAbonnementPush(UTILISATEUR)

        marquerLAbonnementARetransmettre()
        await rapprocherLAbonnementPush(UTILISATEUR)

        expect(ecritures()).toEqual([
            ['push-subscriptions.update', 'https://push.example/tablette'],
            ['push-subscriptions.update', 'https://push.example/tablette'],
        ])
    })

    it('garde l’appareil au compte à qui il a été donné', async () => {
        await dejaTransmis('https://push.example/partage', 7)

        marquerLAbonnementARetransmettre()
        await rapprocherLAbonnementPush(UTILISATEUR)

        expect(reseau.post).not.toHaveBeenCalled()
        expect(appareilDonneAUnAutreCompte(UTILISATEUR)).toBe(true)
    })

    it('ne fait rien sans mémo', async () => {
        marquerLAbonnementARetransmettre()

        expect(window.localStorage.getItem('gym-tracker:abonnement-push-transmis')).toBeNull()
    })
})
