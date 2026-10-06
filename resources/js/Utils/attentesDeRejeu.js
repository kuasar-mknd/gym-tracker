/**
 * Ce que devient une écriture mise en file hors ligne, attendu par l'écran qui
 * l'a faite.
 *
 * `SyncService` rejette une écriture mise en file avec son `queueId`, puis
 * annonce son sort au vidage : `sync:replayed` quand le serveur l'a acceptée,
 * `sync:failed` quand il l'a refusée, `sync:retired` quand l'écran l'a retirée
 * avant son départ. La création d'une ligne écoutait seulement la première, et
 * celle d'une série rien du tout : une série créée hors ligne gardait pour
 * toujours son identifiant provisoire (#1960).
 *
 * Les écouteurs ne sont posés que tant qu'une attente existe, et `oublierTout`
 * les retire quand l'écran s'en va avant le vidage : l'écriture, elle, reste en
 * file et partira sans lui.
 *
 * @returns {{
 *   attendre: (queueId: string|undefined) => Promise<{data: object|null, envoye: object|null, ajustement: string|null}|{refusee: true}|{retiree: true}|null>,
 *   oublierTout: () => void,
 * }}
 */
export const creerLesAttentesDeRejeu = () => {
    /** @type {Map<string, (issue: object) => void>} */
    const attentes = new Map()

    const regler = (queueId, issue) => {
        const resoudre = attentes.get(queueId)

        if (resoudre === undefined) {
            return
        }

        attentes.delete(queueId)
        resoudre(issue)

        if (attentes.size === 0) {
            detacher()
        }
    }

    const surRejeu = (event) =>
        regler(event.detail?.queueId, {
            data: event.detail?.data ?? null,
            envoye: event.detail?.envoye ?? null,
            ajustement: event.detail?.ajustement ?? null,
        })

    const surRefus = (event) => regler(event.detail?.queueId, { refusee: true })

    const surRetrait = (event) =>
        (event.detail?.queueIds ?? []).forEach((queueId) => regler(queueId, { retiree: true }))

    // Poser deux fois le même écouteur n'en pose qu'un.
    const attacher = () => {
        window.addEventListener('sync:replayed', surRejeu)
        window.addEventListener('sync:failed', surRefus)
        window.addEventListener('sync:retired', surRetrait)
    }

    const detacher = () => {
        window.removeEventListener('sync:replayed', surRejeu)
        window.removeEventListener('sync:failed', surRefus)
        window.removeEventListener('sync:retired', surRetrait)
    }

    return {
        /**
         * @param {string|undefined} queueId l'entrée de file à attendre ; sans
         *   elle, l'écriture n'est pas en file et il n'y a rien à attendre.
         */
        attendre: (queueId) => {
            if (!queueId) {
                return Promise.resolve(null)
            }

            return new Promise((resoudre) => {
                attentes.set(queueId, resoudre)
                attacher()
            })
        },

        oublierTout: () => {
            attentes.clear()
            detacher()
        },
    }
}
