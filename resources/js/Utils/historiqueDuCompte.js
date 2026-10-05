/**
 * Une page de compte que le navigateur rend de mémoire après le départ du
 * compte ne se montre pas (#1965).
 *
 * Le serveur pose `encryptHistory` sur chaque page d'un compte, et
 * `clearHistory` sur la première page servie à un autre titulaire que la
 * précédente : après une déconnexion, une session fermée ailleurs ou expirée,
 * ou une connexion. Inertia jette alors la clé de l'historique, rangée dans le
 * `sessionStorage` de l'onglet, et une entrée de l'historique ne se déchiffre
 * plus. Reste la mémoire de retour
 * arrière (bfcache) : un document quitté par une navigation complète peut y
 * dormir tel qu'il était affiché, journal compris, et revenir au bouton Retour
 * sans rien demander à personne. Le serveur répond `no-store` pour l'en
 * écarter, mais tous les navigateurs ne l'en écartent pas. Inertia revérifie
 * bien l'entrée au retour (`pageshow`), mais après coup : la page reste visible
 * le temps de la requête.
 *
 * D'où cette garde : quand la page qui revient était chiffrée et que la clé a
 * disparu, le compte est parti de cet onglet. La page est masquée sur-le-champ
 * et rechargée : le serveur dit ce qu'il y a à voir, la page de connexion le
 * plus souvent, et la page « hors ligne » du worker sans réseau.
 */

/** La clé où Inertia range celle de l'historique (`historySessionStorageKeys`). */
export const CLEF_DE_L_HISTORIQUE = 'historyKey'

/**
 * Si la clé de l'historique est encore là, c'est-à-dire si personne ne s'est
 * déconnecté dans cet onglet depuis que la page a été chiffrée.
 *
 * @param {Window} fenetre
 */
const laCleEstEncoreLa = (fenetre) => {
    try {
        return fenetre.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE) !== null
    } catch {
        // Un stockage illisible ne prouve pas que la clé est partie.
        return true
    }
}

/**
 * Branche la garde, une fois, au démarrage de l'application.
 *
 * @param {{
 *   routeur: { on: (evenement: string, rappel: (evenement: CustomEvent) => void) => unknown },
 *   pageInitiale?: { encryptHistory?: boolean },
 *   fenetre?: Window,
 * }} options
 */
export const installerLaGardeDeLHistorique = ({ routeur, pageInitiale, fenetre = window }) => {
    let pageChiffree = pageInitiale?.encryptHistory === true

    routeur.on('navigate', (evenement) => {
        pageChiffree = evenement.detail?.page?.encryptHistory === true
    })

    fenetre.addEventListener('pageshow', (evenement) => {
        if (!evenement.persisted || !pageChiffree || laCleEstEncoreLa(fenetre)) {
            return
        }

        fenetre.document.documentElement.style.visibility = 'hidden'
        fenetre.location.reload()
    })
}
