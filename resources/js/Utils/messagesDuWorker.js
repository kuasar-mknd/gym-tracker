import { MESSAGE_ATTENDRE_LA_REPONSE, MESSAGE_REPONSE_TARDIVE_RENVOYEE } from '@/sw/navigationsHorsLigne'
import { magasinDesCopies } from '@/Utils/copiesHorsLigne'

/**
 * Ce que la page et le worker se disent d'une navigation servie par la page
 * « hors ligne » au délai (#2048, partie F).
 *
 * **La réponse tardive.** Une page de la salle qui n'a pas répondu en huit
 * secondes reçoit la page « hors ligne » ; le worker attend encore la réponse,
 * et, si c'est une redirection ou un 401, prévient la page que cette
 * navigation a ouverte (`resources/js/sw/navigationsHorsLigne.js`). Une
 * redirection ne dit pas à elle seule la fin de la session : `/` renvoie
 * toujours vers l'accueil, et l'accueil renvoie un compte qui n'a pas vérifié
 * son adresse vers la page qui le lui demande. Seul le témoin des copies le
 * dit, que le serveur efface ou remplace sur toute réponse de fin de session
 * (`CleDesCopiesHorsLigne`), comme le préchauffage le lit
 * (`prechauffage.js`). La page relève donc l'empreinte du témoin à son
 * démarrage, avant toute réponse tardive ; si la réponse l'a effacé ou
 * changé, elle oublie les copies, cette clé-là fermée, puis se recharge. Un
 * témoin inchangé, ou une page démarrée sans témoin, ne fait rien : rien n'y
 * était lisible, et un rechargement suivi d'un nouveau délai tournerait en
 * boucle.
 *
 * L'écoute se pose avant la fin de l'analyse du document : le navigateur garde
 * les messages du worker pour la page jusqu'à `DOMContentLoaded` (ou
 * `startMessages()`), puis les délivre à qui écoute déjà. `main.js` l'installe
 * donc d'emblée, avant tout ce qu'il attend.
 *
 * **« Attendre la réponse ».** La page « hors ligne » servie au délai, sans
 * copie, propose d'attendre : elle demande au worker de lever le délai de son
 * adresse pendant une minute, puis se recharge (`attendreLaReponse`). Un
 * serveur lent mais vivant reste ainsi atteignable.
 */

/** Le temps que la page laisse au worker pour dire qu'il a levé le délai. */
export const ATTENTE_DE_LA_LEVEE_MS = 1_000

/**
 * Écoute les messages du worker. Rend de quoi ne plus écouter.
 *
 * @param {{
 *   conteneur?: { addEventListener: Function, removeEventListener: Function } | null,
 *   magasin?: () => { empreinteDuTemoin: () => Promise<string | null>, oublierLesCopies: (options?: object) => Promise<boolean> },
 *   recharger?: () => void,
 * }} [options]
 * @returns {() => void}
 */
export const installerLesMessagesDuWorker = ({
    conteneur = globalThis.navigator?.serviceWorker ?? null,
    magasin = magasinDesCopies,
    recharger = () => globalThis.location.reload(),
} = {}) => {
    if (conteneur === null || conteneur === undefined) {
        return () => {}
    }

    const empreinteAuDemarrage = Promise.resolve()
        .then(() => magasin().empreinteDuTemoin())
        .catch(() => null)

    const surUnMessage = async (event) => {
        if (event?.data?.type !== MESSAGE_REPONSE_TARDIVE_RENVOYEE) {
            return
        }

        try {
            const avant = await empreinteAuDemarrage
            const maintenant = await magasin().empreinteDuTemoin()

            if (avant === null || maintenant === avant) {
                return
            }

            await magasin().oublierLesCopies({ fermerLEtat: false, fermerAussi: avant })
        } catch {
            return
        }

        recharger()
    }

    conteneur.addEventListener('message', surUnMessage)

    return () => conteneur.removeEventListener('message', surUnMessage)
}

/**
 * « Attendre la réponse » : demande au worker de lever le délai de l'adresse
 * pendant une minute, puis recharge la page, même sans réponse du worker au
 * bout d'une seconde. Sans worker aux commandes, recharge seulement.
 *
 * @param {{
 *   conteneur?: { controller?: { postMessage: Function } | null } | null,
 *   adresse?: string,
 *   recharger?: () => void,
 *   attente?: number,
 * }} [options]
 * @returns {Promise<void>}
 */
export const attendreLaReponse = async ({
    conteneur = globalThis.navigator?.serviceWorker ?? null,
    adresse = globalThis.location?.href,
    recharger = () => globalThis.location.reload(),
    attente = ATTENTE_DE_LA_LEVEE_MS,
} = {}) => {
    const worker = conteneur?.controller

    if (worker) {
        await new Promise((resoudre) => {
            const canal = new MessageChannel()
            const minuterie = setTimeout(resoudre, attente)

            canal.port1.onmessage = () => {
                clearTimeout(minuterie)
                canal.port1.close()
                resoudre()
            }

            try {
                worker.postMessage({ type: MESSAGE_ATTENDRE_LA_REPONSE, adresse }, [canal.port2])
            } catch {
                clearTimeout(minuterie)
                resoudre()
            }
        })
    }

    recharger()
}
