/**
 * Le renouvellement d'un abonnement push, côté worker (#1847).
 *
 * Le navigateur émet `pushsubscriptionchange` quand il remplace ou retire un
 * abonnement : clefs renouvelées, permission retirée, ou révocation par WebKit
 * après des push reçus sans rien afficher. Sans réponse, l'appareil cesse de
 * recevoir et le serveur continue d'écrire vers une adresse morte. Ce module
 * prévient le serveur ; il vit à part parce qu'un fichier de worker ne se teste
 * pas.
 *
 * Il écrit sans jeton CSRF. Un worker n'a pas de document où lire le
 * `<meta name="csrf-token">`, et garder le jeton dans un cache serait fragile,
 * puisqu'il tourne. Il n'en a pas besoin : PreventRequestForgery accepte toute
 * écriture qui porte `Sec-Fetch-Site: same-origin`, en-tête que le navigateur
 * pose lui-même et qu'une page tierce ne peut pas imiter. Ce n'est pas une
 * exemption, c'est la vérification qui couvre déjà toutes les routes web ; un
 * worker n'existe que dans un contexte sécurisé, celui où le navigateur envoie
 * ces en-têtes. RequestForgeryProtectionTest tient cette voie.
 *
 * Ce qui reste hors de portée, et que rattrape le rapprochement fait à
 * l'ouverture de l'application (`rapprocherLAbonnementPush`) : une session
 * expirée (401), un proxy qui retirerait l'en-tête (419), et iOS, qui selon
 * MDN n'émet pas l'évènement.
 */

/** `route('push-subscriptions.update', absolute: false)` : Ziggy n'existe pas dans le worker. */
export const URL_D_ENREGISTREMENT = '/push-subscriptions'

/** `route('push-subscriptions.destroy', absolute: false)`. */
export const URL_D_OUBLI = '/push-subscriptions/delete'

/**
 * Un POST vers notre origine, avec la session et sans jeton.
 *
 * Du JSON est demandé pour qu'une session expirée réponde 401 : une redirection
 * vers la page de connexion serait suivie par `fetch` et finirait en 200.
 *
 * @param {string} url
 * @param {object} corps
 * @returns {Promise<Response>} Rejetée quand le serveur refuse.
 */
export const envoyerAuServeur = async (url, corps) => {
    const reponse = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(corps),
    })

    if (!reponse.ok) {
        throw new Error(`${url} a répondu ${reponse.status}.`)
    }

    return reponse
}

/** Un envoi qui échoue ne doit pas empêcher le suivant, ni rejeter `waitUntil`. */
const tenter = async (envoi) => {
    try {
        await envoi()
    } catch {
        // Rien de mieux à faire ici : l'ouverture suivante de l'application rapprochera.
    }
}

/**
 * L'abonnement qui remplace l'ancien, d'où qu'il vienne.
 *
 * Le navigateur le fournit d'ordinaire. Quand il n'a pas pu se réabonner
 * lui-même, il n'envoie que l'ancien : on se réabonne alors avec ses options,
 * qui portent la clef VAPID qu'un worker ne connaît pas autrement (elle n'est
 * connue qu'à l'exécution). Sans l'un ni l'autre, l'abonnement courant fait
 * foi : c'est le cas de Firefox avant la 137, qui émet l'évènement sans
 * `oldSubscription` ni `newSubscription` selon MDN, et dont l'abonnement courant
 * est alors le plus souvent absent. L'adresse morte reste alors au serveur,
 * jusqu'au rapprochement fait à l'ouverture de l'application.
 *
 * @param {{oldSubscription?: PushSubscription|null, newSubscription?: PushSubscription|null}} evenement
 * @param {PushManager} pushManager
 * @returns {Promise<PushSubscription|null>}
 */
const abonnementDeRemplacement = async (evenement, pushManager) => {
    if (evenement.newSubscription) {
        return evenement.newSubscription
    }

    const options = evenement.oldSubscription?.options

    if (options) {
        const reabonnement = await pushManager.subscribe(options).catch(() => null)

        if (reabonnement) {
            return reabonnement
        }
    }

    return pushManager.getSubscription()
}

/**
 * Enregistre le nouvel abonnement, puis fait oublier l'ancien.
 *
 * Dans cet ordre : oublier d'abord laisserait l'appareil sans abonnement côté
 * serveur si l'enregistrement échouait ensuite. L'ancien n'est oublié que s'il
 * a changé d'adresse, puisque le serveur range les abonnements par adresse.
 *
 * @param {{oldSubscription?: PushSubscription|null, newSubscription?: PushSubscription|null}} evenement
 * @param {{pushManager: PushManager, envoyer?: (url: string, corps: object) => Promise<unknown>}} dependances
 * @returns {Promise<void>} Ne rejette jamais : la promesse part dans `waitUntil`.
 */
export const renouvelerLAbonnement = async (evenement, { pushManager, envoyer = envoyerAuServeur }) => {
    const ancien = evenement.oldSubscription ?? null
    const nouveau = await abonnementDeRemplacement(evenement, pushManager).catch(() => null)

    if (nouveau) {
        await tenter(() => envoyer(URL_D_ENREGISTREMENT, nouveau.toJSON()))
    }

    if (ancien && ancien.endpoint !== nouveau?.endpoint) {
        await tenter(() => envoyer(URL_D_OUBLI, { endpoint: ancien.endpoint }))
    }
}
