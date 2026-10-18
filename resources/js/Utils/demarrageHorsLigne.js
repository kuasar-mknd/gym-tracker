import { ATTRIBUT_DE_LA_COQUILLE, ATTRIBUT_D_ATTENTE, ATTRIBUT_DU_MOTIF, MOTIF_DU_DELAI } from '@/sw/pageHorsLigne'
import { attendreLaReponse } from '@/Utils/messagesDuWorker'

/**
 * Le démarrage de l'application dans la coquille hors ligne (#2048, partie F).
 *
 * Sans réseau, ou sans réponse à temps pour une page de la salle, le worker
 * sert la page « hors ligne » précachée, marquée `data-coquille` et
 * `data-raison` sur `<html>` (`resources/js/sw/pageHorsLigne.js`,
 * `coquilleServie.js`). Elle charge le module de l'entrée, qui importe ce
 * module statiquement : un import à la demande ne se chargerait qu'en mode
 * coquille, donc sans réseau, et ne serait jamais au precache. `main.js` voit
 * la marque (`estLaCoquille`) et attend ce démarrage avant tout le reste,
 * sauf l'écoute des messages du worker, posée d'emblée.
 *
 * **Le contenu statique.** Sans page à démarrer, la coquille reste telle que
 * le worker l'a servie : « Pas de réseau », ce qu'il faut faire avant la
 * salle, « Réessayer ». Elle se recharge au retour du réseau (`online`), ce
 * que faisait son script en ligne, que la politique de la page bloquerait
 * désormais. Servie au délai (`data-raison="delai"`), elle dit que le réseau
 * ne répond pas, et montre « Attendre la réponse », qui demande au worker de
 * lever le délai de l'adresse, puis recharge (`attendreLaReponse`).
 */

/**
 * Ce que dit `data-raison` d'une coquille servie au délai : le réseau n'a pas
 * répondu à temps. Toute autre valeur vaut `reseau`, comme pour le worker
 * (`RAISONS_DE_LA_COQUILLE`, `coquilleServie.js`).
 */
const RAISON_DU_DELAI = 'delai'

/**
 * Si le document est la coquille hors ligne servie par le worker.
 *
 * @param {Document | undefined} document
 * @returns {boolean}
 */
export const estLaCoquille = (document = globalThis.document) => {
    try {
        return document?.documentElement?.hasAttribute(ATTRIBUT_DE_LA_COQUILLE) === true
    } catch {
        return false
    }
}

/**
 * Pourquoi le worker a servi la coquille : `delai` ou `reseau`.
 *
 * @param {Document} document
 * @returns {'delai' | 'reseau'}
 */
export const raisonDeLaCoquille = (document) =>
    document?.documentElement?.getAttribute('data-raison') === RAISON_DU_DELAI ? RAISON_DU_DELAI : 'reseau'

/**
 * Laisse le contenu statique de la coquille tel quel, et lui donne ce que son
 * script en ligne lui donnait : le rechargement au retour du réseau. Servie au
 * délai, elle dit que le réseau ne répond pas et propose d'attendre la
 * réponse.
 *
 * @param {{
 *   fenetre?: Window,
 *   attendre?: () => Promise<void>,
 *   recharger?: () => void,
 * }} [options]
 */
export const laisserLaCoquille = ({
    fenetre = window,
    attendre = () => attendreLaReponse(),
    recharger = () => fenetre.location.reload(),
} = {}) => {
    const document = fenetre.document

    fenetre.addEventListener('online', () => recharger(), { once: true })

    if (raisonDeLaCoquille(document) !== RAISON_DU_DELAI) {
        return
    }

    const motif = document.querySelector(`[${ATTRIBUT_DU_MOTIF}]`)
    const bouton = document.querySelector(`[${ATTRIBUT_D_ATTENTE}]`)

    if (motif !== null) {
        motif.textContent = MOTIF_DU_DELAI
    }

    if (bouton !== null) {
        bouton.hidden = false
        bouton.addEventListener('click', () => {
            bouton.disabled = true
            attendre()
        })
    }
}

/**
 * Démarre la coquille : sans copie à monter, son contenu statique reste.
 *
 * @param {{ fenetre?: Window, attendre?: () => Promise<void>, recharger?: () => void }} [options]
 * @returns {Promise<{ montee: boolean, raison: string }>}
 */
export const demarrerHorsLigne = async (options = {}) => {
    laisserLaCoquille(options)

    return { montee: false, raison: 'sans-copie' }
}
