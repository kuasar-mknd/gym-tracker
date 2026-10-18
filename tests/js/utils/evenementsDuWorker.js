/**
 * Les évènements qu'un worker reçoit, pour charger le vrai worker et le vrai
 * Workbox sous Vitest (#2048, parties E et F).
 *
 * jsdom n'a ni `ExtendableEvent` ni `FetchEvent`, que les assertions de
 * Workbox exigent hors production (`assert.isInstance(event, ExtendableEvent)`) :
 * le test les pose par `vi.stubGlobal`. Ils tiennent le contrat dont le worker
 * dépend : `waitUntil` garde chaque promesse, qu'un envoi attend, et un second
 * `respondWith` lève, comme dans un navigateur.
 */

/**
 * Un évènement prolongeable : `waitUntil` garde chaque promesse.
 */
export class EvenementProlongeable extends Event {
    constructor(type) {
        super(type)
        /** @type {Promise<unknown>[]} */
        this.attentes = []
    }

    waitUntil(promesse) {
        this.attentes.push(Promise.resolve(promesse))
    }
}

/**
 * Une requête que le worker reçoit : un second `respondWith` lève, comme dans
 * un navigateur. Sans préchargement par défaut.
 */
export class EvenementDeRequete extends EvenementProlongeable {
    /**
     * @param {Request} requete
     * @param {{ preloadResponse?: Promise<Response | undefined>, resultingClientId?: string }} [options]
     */
    constructor(requete, { preloadResponse = Promise.resolve(undefined), resultingClientId = '' } = {}) {
        super('fetch')
        this.request = requete
        this.preloadResponse = preloadResponse
        this.resultingClientId = resultingClientId
        /** @type {Promise<Response>[]} */
        this.reponses = []
    }

    respondWith(reponse) {
        if (this.reponses.length > 0) {
            throw new DOMException('The event has already been responded to.', 'InvalidStateError')
        }

        this.reponses.push(Promise.resolve(reponse))
    }
}

/**
 * Une navigation, telle que le worker la reçoit : `new Request` refuse le
 * mode `navigate`, que cette requête donne, en restant une `Request` pour les
 * assertions de Workbox.
 */
export class RequeteDeNavigation extends Request {
    get mode() {
        return 'navigate'
    }
}
