/**
 * Un réseau simulé pour le vrai client d'Inertia, qui passe par `XMLHttpRequest` (#2048).
 *
 * jsdom ne joint aucun serveur : `RequeteSimulee` remplace `XMLHttpRequest`,
 * et `reseau.mode` dit ce que devient la prochaine requête :
 * - `coupe` : aucune réponse, l'échec arrive aussitôt, comme sans réseau ;
 * - `muet` : aucune réponse, jamais, comme un réseau qui ne répond plus. Le
 *   test répond lui-même par `repondre`, ou la requête attend son abandon ;
 * - `repond` : `reseau.reponse` arrive.
 * `reseau.prochains` règle les requêtes suivantes une à une, dans l'ordre de
 * leur départ, avant que `reseau.mode` reprenne : une visite au premier plan
 * muette, et un rechargement en arrière-plan coupé, par exemple.
 *
 * Les rejets que personne n'attend sont relayés à la fenêtre sous la forme
 * qu'un navigateur leur donne, l'évènement `unhandledrejection` que le
 * rapporteur d'erreurs écoute : jsdom ne le fait pas lui-même.
 */

/** @type {{ mode: 'coupe'|'muet'|'repond', prochains: Array<'coupe'|'muet'|'repond'>, reponse: { statut: number, entetes: Record<string, string>, corps: string } | null }} */
export const reseau = { mode: 'coupe', prochains: [], reponse: null }

/** Les requêtes parties, dans l'ordre. */
export const requetes = []

export class RequeteSimulee {
    upload = {}
    status = 0
    responseText = ''
    entetesDeReponse = {}
    entetes = {}
    annulee = false

    open(methode, url) {
        this.methode = methode
        this.url = url
    }

    setRequestHeader(nom, valeur) {
        this.entetes[nom] = valeur
    }

    getAllResponseHeaders() {
        return Object.entries(this.entetesDeReponse)
            .map(([nom, valeur]) => `${nom}: ${valeur}`)
            .join('\r\n')
    }

    abort() {
        this.annulee = true
        this.onabort?.()
    }

    /** Répond à la requête, comme le serveur. */
    repondre({ statut, entetes = {}, corps = '' }) {
        this.status = statut
        this.entetesDeReponse = entetes
        this.responseText = corps
        this.onload?.()
    }

    send(corps) {
        requetes.push(this)
        this.corps = corps

        const mode = reseau.prochains.shift() ?? reseau.mode

        if (mode === 'muet') {
            return
        }

        queueMicrotask(() => {
            if (mode === 'coupe') {
                this.onerror?.()

                return
            }

            this.repondre(reseau.reponse)
        })
    }
}

/** La réponse d'Inertia qui porte `page`. */
export const reponseInertia = (page) => ({
    statut: 200,
    entetes: { 'x-inertia': 'true', 'content-type': 'application/json' },
    corps: JSON.stringify({ errors: {}, ...page, props: { errors: {}, ...page.props } }),
})

/**
 * Relaie à la fenêtre chaque rejet que personne n'attend, et les garde.
 *
 * @returns {{ rejets: unknown[], retirer: () => void }}
 */
export const relayerLesRejetsNonGeres = () => {
    const rejets = []
    const relais = (raison) => {
        rejets.push(raison)

        const evenement = new Event('unhandledrejection')
        evenement.reason = raison
        window.dispatchEvent(evenement)
    }

    process.on('unhandledRejection', relais)

    return { rejets, retirer: () => process.off('unhandledRejection', relais) }
}

/** Laisse passer une tâche : un rejet non géré n'est signalé qu'après les microtâches. */
export const uneTache = () => new Promise((resoudre) => setTimeout(resoudre, 0))
