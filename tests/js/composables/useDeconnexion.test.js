import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

/**
 * La déconnexion détache l'appareil du compte qui part (#1926) : sur un
 * appareil partagé, la personne suivante voyait sinon les records et les
 * rappels de ce compte arriver sur l'écran verrouillé.
 *
 * Ce qui est vérifié ici tient en deux exigences. Le détachement a lieu, dans
 * l'ordre — l'adresse oubliée du serveur, puis l'abonnement retiré du
 * navigateur, puis le mémo effacé — et AVANT la requête de déconnexion. Et il
 * ne la retient jamais : ni une erreur, ni une lenteur, ni un navigateur sans
 * push ne doivent empêcher quelqu'un de quitter son compte.
 */

const reseau = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@/Utils/http', () => ({ http: { post: (...args) => reseau.post(...args) } }))

const routeur = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({ router: { post: (...args) => routeur.post(...args) } }))

const CLEF_DU_MEMO = 'gym-tracker:abonnement-push-transmis'
const UTILISATEUR = 42
const ADRESSE = 'https://push.example/appareil-partage'

/** Chaque test part d'un chargement neuf : le module garde ce qu'il a fait. */
let seDeconnecter
let rapprocherLAbonnementPush
let DELAI_DE_DETACHEMENT_MS

const chargementNeuf = async () => {
    vi.resetModules()
    ;({ seDeconnecter } = await import('@/composables/useDeconnexion'))
    ;({ rapprocherLAbonnementPush, DELAI_DE_DETACHEMENT_MS } = await import('@/composables/useAbonnementPush'))
}

/** Ce qui s'est passé, dans l'ordre, et ce que le mémo disait à ce moment. */
let journal

const memo = () => window.localStorage.getItem(CLEF_DU_MEMO)

const poserLeMemo = (utilisateur = UTILISATEUR, endpoint = ADRESSE) =>
    window.localStorage.setItem(CLEF_DU_MEMO, JSON.stringify({ utilisateur: String(utilisateur), endpoint }))

const abonnementDe = (endpoint = ADRESSE) => ({
    endpoint,
    unsubscribe: vi.fn(async () => {
        journal.push(['désabonnement', memo() !== null])

        return true
    }),
    toJSON: () => ({ endpoint, keys: { p256dh: 'p', auth: 'a' } }),
})

/** Une promesse que le test règle lui-même, ou jamais. */
const enSuspens = () => {
    let regler
    const promesse = new Promise((resolve) => {
        regler = resolve
    })

    return { promesse, regler }
}

/**
 * @param {{abonnement?: object|null, enregistrement?: Promise<unknown>|null}} options
 *   `enregistrement` remplace la réponse de `getRegistration()`, pour un worker
 *   qui tarde ou ne répond pas.
 */
const navigateur = ({ abonnement = abonnementDe(), enregistrement = null } = {}) => {
    const pushManager = { getSubscription: vi.fn().mockResolvedValue(abonnement) }
    const serviceWorker = {
        ready: Promise.resolve({ pushManager }),
        getRegistration: vi.fn(() => enregistrement ?? Promise.resolve({ pushManager })),
    }

    Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: serviceWorker })
    Object.defineProperty(window, 'Notification', { configurable: true, writable: true, value: {} })

    return { pushManager, serviceWorker }
}

const sansPush = () => {
    delete navigator.serviceWorker
    delete window.Notification
}

/** Les oublis partis vers le serveur : `[[route, adresse], …]`. */
const oublis = () =>
    reseau.post.mock.calls
        .filter(([url]) => url === '/push-subscriptions.destroy')
        .map(([url, corps]) => [url.slice(1), corps.endpoint])

beforeEach(async () => {
    vi.clearAllMocks()
    window.localStorage.clear()
    journal = []
    globalThis.route = (nom) => `/${nom}`
    await chargementNeuf()

    reseau.post.mockImplementation(async (url, corps) => {
        journal.push([url === '/push-subscriptions.destroy' ? 'oubli' : 'enregistrement', corps.endpoint])

        return {}
    })
    routeur.post.mockImplementation((url) => journal.push(['déconnexion', url, memo() !== null]))
})

afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
    sansPush()
})

describe('se déconnecter détache l’appareil', () => {
    it('oublie l’adresse, se désabonne puis efface le mémo, et seulement ensuite se déconnecte', async () => {
        navigateur()
        poserLeMemo()

        await seDeconnecter()

        expect(journal).toEqual([
            ['oubli', ADRESSE],
            // Le mémo est encore là quand le navigateur se désabonne…
            ['désabonnement', true],
            // … et parti quand la déconnexion est envoyée.
            ['déconnexion', '/logout', false],
        ])
    })

    it('fait oublier l’adresse par le client HTTP de l’application, qui porte le jeton CSRF', async () => {
        navigateur()

        await seDeconnecter()

        expect(oublis()).toEqual([['push-subscriptions.destroy', ADRESSE]])
        expect(reseau.post).toHaveBeenCalledWith(
            '/push-subscriptions.destroy',
            { endpoint: ADRESSE },
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        )
    })

    it('se désabonne même quand le mémo nomme un autre compte, que l’appareil ne doit plus servir', async () => {
        const abonnement = abonnementDe()
        navigateur({ abonnement })
        poserLeMemo(7)

        await seDeconnecter()

        // Le serveur n'oublie que les lignes du compte connecté : l'autre
        // compte perd cet appareil par le désabonnement, pas par la requête.
        expect(abonnement.unsubscribe).toHaveBeenCalledTimes(1)
        expect(memo()).toBeNull()
        expect(routeur.post).toHaveBeenCalledWith('/logout', expect.anything(), expect.anything())
    })

    it('ne déconnecte qu’une fois quand on clique deux fois', async () => {
        navigateur()

        await Promise.all([seDeconnecter(), seDeconnecter()])

        expect(oublis()).toHaveLength(1)
        expect(routeur.post).toHaveBeenCalledTimes(1)
    })

    it('permet de réessayer quand la déconnexion précédente est terminée', async () => {
        navigateur()

        await seDeconnecter()
        routeur.post.mock.calls[0][2].onFinish()
        await seDeconnecter()

        expect(routeur.post).toHaveBeenCalledTimes(2)
    })
})

describe('rien ne retient la déconnexion', () => {
    it('part quand le serveur refuse d’oublier, et se désabonne quand même', async () => {
        const abonnement = abonnementDe()
        navigateur({ abonnement })
        poserLeMemo()
        reseau.post.mockRejectedValue(Object.assign(new Error('refusé'), { response: { status: 500 } }))

        await seDeconnecter()

        expect(abonnement.unsubscribe).toHaveBeenCalledTimes(1)
        expect(memo()).toBeNull()
        expect(routeur.post).toHaveBeenCalledTimes(1)
    })

    it('part quand le navigateur refuse de se désabonner, et efface quand même le mémo', async () => {
        const abonnement = abonnementDe()
        abonnement.unsubscribe.mockRejectedValue(new Error('refusé'))
        navigateur({ abonnement })
        poserLeMemo()

        await seDeconnecter()

        expect(memo()).toBeNull()
        expect(routeur.post).toHaveBeenCalledTimes(1)
    })

    it('part quand le stockage est bloqué', async () => {
        navigateur()
        vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
            throw new Error('stockage bloqué')
        })

        await seDeconnecter()

        expect(routeur.post).toHaveBeenCalledTimes(1)
    })

    it('part quand le worker est introuvable', async () => {
        const { serviceWorker } = navigateur()
        serviceWorker.getRegistration.mockRejectedValue(new Error('origine non sûre'))

        await seDeconnecter()

        expect(reseau.post).not.toHaveBeenCalled()
        expect(routeur.post).toHaveBeenCalledTimes(1)
    })

    it('n’attend pas plus que le délai un serveur qui tarde à oublier, et abandonne sa requête', async () => {
        vi.useFakeTimers()
        const abonnement = abonnementDe()
        navigateur({ abonnement })
        poserLeMemo()
        reseau.post.mockImplementation(
            (url, corps, { signal }) =>
                new Promise((_, reject) => signal.addEventListener('abort', () => reject(new Error('abandonnée')))),
        )
        routeur.post.mockImplementation(() =>
            journal.push(['déconnexion', reseau.post.mock.calls[0][2].signal.aborted]),
        )

        seDeconnecter()
        await vi.advanceTimersByTimeAsync(DELAI_DE_DETACHEMENT_MS - 1)

        expect(routeur.post).not.toHaveBeenCalled()

        await vi.advanceTimersByTimeAsync(1)

        // Abandonnée AVANT que la déconnexion parte : sa réponse, arrivée
        // après, rendrait au navigateur le cookie de la session close.
        expect(journal.filter(([etape]) => etape === 'déconnexion')).toEqual([['déconnexion', true]])

        // L'appareil se détache quand même, après coup.
        await flushPromises()
        expect(abonnement.unsubscribe).toHaveBeenCalledTimes(1)
        expect(memo()).toBeNull()
    })

    it('n’attend pas plus que le délai un navigateur qui tarde à se désabonner', async () => {
        vi.useFakeTimers()
        const abonnement = abonnementDe()
        abonnement.unsubscribe.mockReturnValue(new Promise(() => {}))
        navigateur({ abonnement })

        seDeconnecter()
        await vi.advanceTimersByTimeAsync(DELAI_DE_DETACHEMENT_MS)

        expect(routeur.post).toHaveBeenCalledTimes(1)
    })

    it('n’envoie plus l’oubli quand le worker répond après le départ, mais se désabonne', async () => {
        vi.useFakeTimers()
        const abonnement = abonnementDe()
        const worker = enSuspens()
        navigateur({ abonnement, enregistrement: worker.promesse })
        poserLeMemo()

        seDeconnecter()
        await vi.advanceTimersByTimeAsync(DELAI_DE_DETACHEMENT_MS)

        expect(routeur.post).toHaveBeenCalledTimes(1)

        worker.regler({ pushManager: { getSubscription: vi.fn().mockResolvedValue(abonnement) } })
        await flushPromises()

        // La session est close ou sur le point de l'être : une écriture
        // maintenant croiserait la déconnexion.
        expect(oublis()).toEqual([])
        expect(abonnement.unsubscribe).toHaveBeenCalledTimes(1)
        expect(memo()).toBeNull()
    })
})

describe('rien à détacher', () => {
    it('n’appelle rien quand le navigateur n’a pas d’abonnement, et part sans attendre', async () => {
        vi.useFakeTimers()
        navigateur({ abonnement: null })
        poserLeMemo()

        seDeconnecter()
        await vi.advanceTimersByTimeAsync(0)

        expect(reseau.post).not.toHaveBeenCalled()
        expect(memo()).not.toBeNull()
        expect(routeur.post).toHaveBeenCalledTimes(1)
    })

    it('n’appelle rien quand le navigateur ne gère pas le push, et part sans attendre', async () => {
        vi.useFakeTimers()
        sansPush()
        poserLeMemo()

        seDeconnecter()
        await vi.advanceTimersByTimeAsync(0)

        expect(reseau.post).not.toHaveBeenCalled()
        expect(memo()).not.toBeNull()
        expect(routeur.post).toHaveBeenCalledTimes(1)
    })
})

describe('le rapprochement en cours', () => {
    it('est attendu, pour que son mémo ne revienne pas après la déconnexion', async () => {
        navigateur()
        const enregistrement = enSuspens()
        reseau.post.mockImplementationOnce(async (url, corps) => {
            await enregistrement.promesse
            journal.push(['enregistrement', corps.endpoint])

            return {}
        })

        rapprocherLAbonnementPush(UTILISATEUR)
        const deconnexion = seDeconnecter()
        await flushPromises()
        enregistrement.regler()
        await deconnexion

        expect(journal).toEqual([
            ['enregistrement', ADRESSE],
            ['oubli', ADRESSE],
            ['désabonnement', true],
            ['déconnexion', '/logout', false],
        ])
        expect(memo()).toBeNull()
    })

    it('ne vaut plus pour le compte qui se reconnecte dans le même chargement', async () => {
        const abonnement = abonnementDe()
        abonnement.unsubscribe.mockRejectedValue(new Error('refusé'))
        navigateur({ abonnement })
        await rapprocherLAbonnementPush(UTILISATEUR)

        await seDeconnecter()
        reseau.post.mockClear()

        // Le serveur a oublié l'adresse : la transmission faite avant la
        // déconnexion ne dispense plus d'écrire.
        await rapprocherLAbonnementPush(UTILISATEUR, { serveurSansAbonnement: true })

        expect(reseau.post).toHaveBeenCalledWith(
            '/push-subscriptions.update',
            expect.objectContaining({ endpoint: ADRESSE }),
            expect.any(Object),
        )
    })
})
