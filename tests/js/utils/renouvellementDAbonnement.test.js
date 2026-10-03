import { describe, it, expect, vi, afterEach } from 'vitest'
import {
    URL_D_ENREGISTREMENT,
    URL_D_OUBLI,
    envoyerAuServeur,
    renouvelerLAbonnement,
} from '@/sw/renouvellementDAbonnement'

/**
 * Un abonnement tel que le navigateur en rend : `toJSON()` donne ce que la
 * requête de mise à jour valide (adresse et deux clefs).
 */
const abonnementDuNavigateur = (endpoint, options = { userVisibleOnly: true, applicationServerKey: 'cle-vapid' }) => ({
    endpoint,
    options,
    toJSON: () => ({
        endpoint,
        expirationTime: null,
        keys: { p256dh: `p256dh-${endpoint}`, auth: `auth-${endpoint}` },
    }),
})

const gestionnaireDePush = ({
    existant = null,
    reabonnement = abonnementDuNavigateur('https://push.example/reabonne'),
} = {}) => ({
    getSubscription: vi.fn().mockResolvedValue(existant),
    subscribe: vi.fn().mockResolvedValue(reabonnement),
})

/** Les envois, dans l'ordre : `[url, corps]`. */
const envoyeur = () => vi.fn().mockResolvedValue(undefined)

afterEach(() => {
    vi.unstubAllGlobals()
})

describe('le renouvellement d’un abonnement par le worker', () => {
    it('enregistre le nouvel abonnement, puis fait oublier l’ancien', async () => {
        const ancien = abonnementDuNavigateur('https://push.example/ancien')
        const nouveau = abonnementDuNavigateur('https://push.example/nouveau')
        const pushManager = gestionnaireDePush()
        const envoyer = envoyeur()

        await renouvelerLAbonnement({ oldSubscription: ancien, newSubscription: nouveau }, { pushManager, envoyer })

        // L'ordre compte : oublier d'abord laisserait l'appareil sans aucun
        // abonnement côté serveur si l'enregistrement échouait ensuite.
        expect(envoyer.mock.calls).toEqual([
            [URL_D_ENREGISTREMENT, nouveau.toJSON()],
            [URL_D_OUBLI, { endpoint: 'https://push.example/ancien' }],
        ])
        // Le navigateur a déjà fourni le remplaçant : se réabonner en
        // créerait un troisième.
        expect(pushManager.subscribe).not.toHaveBeenCalled()
        expect(pushManager.getSubscription).not.toHaveBeenCalled()
    })

    it('ne fait rien oublier quand le point de terminaison n’a pas changé', async () => {
        // Des clefs renouvelées sur la même adresse : supprimer « l'ancien »
        // supprimerait le nouveau, puisque le serveur range par adresse.
        const ancien = abonnementDuNavigateur('https://push.example/meme')
        const nouveau = abonnementDuNavigateur('https://push.example/meme')
        const envoyer = envoyeur()

        await renouvelerLAbonnement(
            { oldSubscription: ancien, newSubscription: nouveau },
            { pushManager: gestionnaireDePush(), envoyer },
        )

        expect(envoyer.mock.calls).toEqual([[URL_D_ENREGISTREMENT, nouveau.toJSON()]])
    })

    it('se réabonne avec les options de l’ancien quand le navigateur ne fournit que lui', async () => {
        // Un navigateur qui n'a pas pu se réabonner lui-même n'envoie que
        // l'ancien abonnement, `newSubscription` restant nul.
        const ancien = abonnementDuNavigateur('https://push.example/revoque')
        const reabonnement = abonnementDuNavigateur('https://push.example/reabonne')
        const pushManager = gestionnaireDePush({ reabonnement })
        const envoyer = envoyeur()

        await renouvelerLAbonnement({ oldSubscription: ancien, newSubscription: null }, { pushManager, envoyer })

        expect(pushManager.subscribe).toHaveBeenCalledWith(ancien.options)
        expect(envoyer.mock.calls).toEqual([
            [URL_D_ENREGISTREMENT, reabonnement.toJSON()],
            [URL_D_OUBLI, { endpoint: 'https://push.example/revoque' }],
        ])
    })

    it('fait au moins oublier l’ancien quand le réabonnement est refusé', async () => {
        // Permission retirée : `subscribe()` rejette, et l'adresse morte ne
        // doit pas rester au serveur, qui y écrirait à chaque notification.
        const ancien = abonnementDuNavigateur('https://push.example/revoque')
        const pushManager = gestionnaireDePush()
        pushManager.subscribe.mockRejectedValue(new DOMException('refusé', 'NotAllowedError'))
        const envoyer = envoyeur()

        await renouvelerLAbonnement({ oldSubscription: ancien }, { pushManager, envoyer })

        expect(pushManager.getSubscription).toHaveBeenCalled()
        expect(envoyer.mock.calls).toEqual([[URL_D_OUBLI, { endpoint: 'https://push.example/revoque' }]])
    })

    it('se rabat sur l’abonnement courant quand l’évènement n’en porte aucun', async () => {
        const courant = abonnementDuNavigateur('https://push.example/courant')
        const pushManager = gestionnaireDePush({ existant: courant })
        const envoyer = envoyeur()

        await renouvelerLAbonnement({}, { pushManager, envoyer })

        expect(pushManager.subscribe).not.toHaveBeenCalled()
        expect(envoyer.mock.calls).toEqual([[URL_D_ENREGISTREMENT, courant.toJSON()]])
    })

    it('n’envoie rien quand il n’y a plus rien à enregistrer ni à oublier', async () => {
        const envoyer = envoyeur()

        await expect(renouvelerLAbonnement({}, { pushManager: gestionnaireDePush(), envoyer })).resolves.toBeUndefined()

        expect(envoyer).not.toHaveBeenCalled()
    })

    it('se règle même quand le navigateur ne rend pas l’abonnement courant', async () => {
        const pushManager = gestionnaireDePush()
        pushManager.getSubscription.mockRejectedValue(new Error('worker arrêté'))
        const envoyer = envoyeur()

        await expect(renouvelerLAbonnement({}, { pushManager, envoyer })).resolves.toBeUndefined()

        expect(envoyer).not.toHaveBeenCalled()
    })

    it.each([
        ['le réseau manque', () => Promise.reject(new TypeError('Failed to fetch'))],
        ['la session a expiré', () => Promise.reject(new Error('/push-subscriptions a répondu 401.'))],
        [
            'l’envoi lève avant de partir',
            () => {
                throw new Error('lève tout de suite')
            },
        ],
    ])('ne rejette jamais la promesse confiée à waitUntil quand %s', async (_cas, echec) => {
        // Une promesse rejetée dans `waitUntil` ne répare rien, et la seconde
        // requête — l'oubli de l'ancienne adresse — ne partirait plus.
        const envoyer = vi.fn(echec)

        await expect(
            renouvelerLAbonnement(
                {
                    oldSubscription: abonnementDuNavigateur('https://push.example/ancien'),
                    newSubscription: abonnementDuNavigateur('https://push.example/nouveau'),
                },
                { pushManager: gestionnaireDePush(), envoyer },
            ),
        ).resolves.toBeUndefined()

        expect(envoyer).toHaveBeenCalledTimes(2)
    })
})

describe('l’envoi depuis le worker', () => {
    const reponse = (status) => ({ ok: status >= 200 && status < 300, status })

    it('poste du JSON à notre origine, avec la session et sans jeton CSRF', async () => {
        const fetch = vi.fn().mockResolvedValue(reponse(200))
        vi.stubGlobal('fetch', fetch)

        await envoyerAuServeur(URL_D_ENREGISTREMENT, { endpoint: 'https://push.example/nouveau' })

        const [url, options] = fetch.mock.calls[0]

        expect(url).toBe('/push-subscriptions')
        expect(options).toMatchObject({ method: 'POST', credentials: 'same-origin' })
        // Un worker n'a pas de document où lire le jeton : c'est l'en-tête
        // `Sec-Fetch-Site: same-origin`, posé par le navigateur, que
        // PreventRequestForgery accepte à la place.
        expect(Object.keys(options.headers).map((nom) => nom.toLowerCase())).not.toContain('x-csrf-token')
        // JSON demandé : une session expirée répond 401 au lieu de rediriger
        // vers la page de connexion, que le worker suivrait pour rien.
        expect(options.headers.Accept).toBe('application/json')
        expect(options.headers['Content-Type']).toBe('application/json')
        expect(JSON.parse(options.body)).toEqual({ endpoint: 'https://push.example/nouveau' })
    })

    it.each([401, 419, 422])('rejette quand le serveur répond %i', async (status) => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(reponse(status)))

        await expect(envoyerAuServeur(URL_D_OUBLI, { endpoint: 'x' })).rejects.toThrow(String(status))
    })

    it('vise les routes du serveur sans passer par Ziggy, absent du worker', () => {
        // Le pendant PHP compare ces deux valeurs à `route(..., absolute: false)`.
        expect(URL_D_ENREGISTREMENT).toBe('/push-subscriptions')
        expect(URL_D_OUBLI).toBe('/push-subscriptions/delete')
    })
})
