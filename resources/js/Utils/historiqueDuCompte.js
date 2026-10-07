/**
 * Une page de compte ne se relit plus dans l'historique d'un onglet après le
 * départ du compte (#1965).
 *
 * Le serveur pose `encryptHistory` sur chaque page d'un compte : Inertia la
 * chiffre dans l'historique du navigateur, avec une clé rangée dans le
 * `sessionStorage` de l'onglet. Jeter cette clé (`clearHistory`) rend
 * illisibles les entrées déjà rangées : au bouton Retour, Inertia redemande la
 * page au serveur, qui dit ce qu'il y a à voir.
 *
 * Le serveur pose `clearHistory` sur la première page qu'une session sert à un
 * autre titulaire que la précédente. Mais la session vaut pour tous les onglets
 * du navigateur, quand chaque onglet a sa propre clé : la consigne part avec la
 * première page rendue, dans l'onglet qui la demande, et les autres onglets
 * gardaient la leur, avec les pages du compte parti. Chaque onglet décide donc
 * aussi pour lui-même, d'après le titulaire que chaque page déclare
 * (`auth.user`, ou personne pour un invité) :
 *
 * - l'onglet retient le titulaire de la dernière page qu'il a reçue. Une page
 *   d'un autre titulaire jette d'abord la clé de l'onglet : la page initiale
 *   d'un document, avant qu'Inertia ne lise l'historique, et la réponse d'une
 *   visite, avant qu'Inertia ne la range (`beforeUpdate`). Un compte ne reprend
 *   donc jamais, dans aucun onglet, la clé de celui qui s'en servait avant lui ;
 * - l'appareil retient, dans le `localStorage` commun aux onglets, le titulaire
 *   de la dernière page reçue par l'un d'eux. Quand il change, les autres
 *   onglets jettent leur clé aussitôt (évènement `storage`), et au plus tard au
 *   bouton Retour (`popstate`, `pageshow`), avant qu'Inertia ne déchiffre : un
 *   onglet resté sur une page du compte parti ne montre que cette page, et rien
 *   de son historique.
 *
 * Reste la mémoire de retour arrière (bfcache) : un document quitté par une
 * navigation complète peut y dormir tel qu'il était affiché, journal compris,
 * et revenir au bouton Retour sans rien demander à personne. Le serveur répond
 * `no-store` pour l'en écarter, mais tous les navigateurs ne l'en écartent pas.
 * Inertia revérifie bien l'entrée au retour (`pageshow`), mais après coup : la
 * page reste visible le temps de la requête. Quand la page qui revient était
 * chiffrée et que la clé a disparu, le compte est parti de cet onglet : la page
 * est masquée sur-le-champ et rechargée. Le serveur dit ce qu'il y a à voir, la
 * page de connexion le plus souvent, et la page « hors ligne » du worker sans
 * réseau.
 *
 * Le module s'installe avant `createInertiaApp`, qui lit l'historique dès son
 * démarrage, et ses écouteurs passent avant ceux d'Inertia.
 */

/** La clé où Inertia range celle de l'historique (`historySessionStorageKeys`). */
export const CLEF_DE_L_HISTORIQUE = 'historyKey'

/** Le titulaire de la dernière page reçue par l'onglet, dans son `sessionStorage`. */
export const CLEF_DU_TITULAIRE_DE_L_ONGLET = 'gym-tracker:titulaire-de-l-onglet'

/** Le titulaire de la dernière page reçue par un onglet de l'appareil, dans le `localStorage`. */
export const CLEF_DU_TITULAIRE_DE_L_APPAREIL = 'gym-tracker:titulaire-de-l-appareil'

/** Le titulaire d'une page servie sans compte. */
export const INVITE = 'invite'

/**
 * Le titulaire qu'une page déclare : `compte:<id>`, un invité, ou `null` quand
 * la page ne porte pas `auth` et ne dit donc rien.
 *
 * @param {{ props?: { auth?: { user?: { id?: unknown } | null } | null } } | null | undefined} page
 * @returns {string | null}
 */
export const titulaireDeLaPage = (page) => {
    const auth = page?.props?.auth

    if (auth === null || typeof auth !== 'object') {
        return null
    }

    const identifiant = auth.user?.id

    return identifiant === undefined || identifiant === null ? INVITE : `compte:${identifiant}`
}

/**
 * La page qu'Inertia lira au démarrage, dans le document servi.
 *
 * @param {Document | undefined} document
 * @returns {object | null}
 */
export const pageInitialeDuDocument = (document) => {
    try {
        const script = document?.querySelector?.('script[data-page="app"][type="application/json"]')

        return script?.textContent ? JSON.parse(script.textContent) : null
    } catch {
        return null
    }
}

/**
 * Un stockage de la fenêtre, ou `null` quand le navigateur en refuse l'accès.
 *
 * @param {Window} fenetre
 * @param {'sessionStorage' | 'localStorage'} nom
 * @returns {Storage | null}
 */
const stockageDe = (fenetre, nom) => {
    try {
        return fenetre[nom] ?? null
    } catch {
        return null
    }
}

/**
 * @param {Storage | null} stockage
 * @param {string} clef
 * @returns {string | null}
 */
const lire = (stockage, clef) => {
    try {
        return stockage?.getItem(clef) ?? null
    } catch {
        return null
    }
}

/**
 * @param {Storage | null} stockage
 * @param {string} clef
 * @param {string} valeur
 */
const ecrire = (stockage, clef, valeur) => {
    try {
        stockage?.setItem(clef, valeur)
    } catch {
        // Un stockage plein ou refusé : la page suivante réessaiera.
    }
}

/**
 * Si la clé de l'historique est encore là, c'est-à-dire si le compte n'est pas
 * parti de cet onglet depuis que la page a été chiffrée.
 *
 * @param {Storage | null} stockage
 */
const laCleEstEncoreLa = (stockage) => {
    try {
        return stockage === null || stockage.getItem(CLEF_DE_L_HISTORIQUE) !== null
    } catch {
        // Un stockage illisible ne prouve pas que la clé est partie.
        return true
    }
}

/**
 * Branche la garde, une fois, avant le démarrage de l'application.
 *
 * @param {{
 *   routeur: {
 *     on: (evenement: string, rappel: (evenement: CustomEvent) => void) => unknown,
 *     clearHistory: () => void,
 *   },
 *   fenetre?: Window,
 *   pageInitiale?: object | null,
 * }} options
 */
export const installerLaGardeDeLHistorique = ({
    routeur,
    fenetre = window,
    pageInitiale = pageInitialeDuDocument(fenetre.document),
}) => {
    const onglet = stockageDe(fenetre, 'sessionStorage')
    const appareil = stockageDe(fenetre, 'localStorage')
    let pageChiffree = pageInitiale?.encryptHistory === true

    const jeterLaCle = () => {
        try {
            routeur.clearHistory()
        } catch {
            // Sans stockage, Inertia n'a pas pu ranger de clé non plus.
        }
    }

    /** Une page reçue du serveur : la clé de l'onglet part si elle sert un autre titulaire. */
    const recevoir = (page) => {
        const titulaire = titulaireDeLaPage(page)

        if (titulaire === null) {
            return
        }

        if (lire(onglet, CLEF_DU_TITULAIRE_DE_L_ONGLET) !== titulaire) {
            jeterLaCle()
            ecrire(onglet, CLEF_DU_TITULAIRE_DE_L_ONGLET, titulaire)
        }

        if (lire(appareil, CLEF_DU_TITULAIRE_DE_L_APPAREIL) !== titulaire) {
            ecrire(appareil, CLEF_DU_TITULAIRE_DE_L_APPAREIL, titulaire)
        }
    }

    /** Un autre onglet a reçu la page d'un autre titulaire : la clé de celui-ci part. */
    const suivreLAppareil = () => {
        const titulaireDeLAppareil = lire(appareil, CLEF_DU_TITULAIRE_DE_L_APPAREIL)

        if (titulaireDeLAppareil !== null && titulaireDeLAppareil !== lire(onglet, CLEF_DU_TITULAIRE_DE_L_ONGLET)) {
            jeterLaCle()
        }
    }

    recevoir(pageInitiale)

    routeur.on('beforeUpdate', (evenement) => {
        recevoir(evenement.detail?.page)
    })

    routeur.on('navigate', (evenement) => {
        pageChiffree = evenement.detail?.page?.encryptHistory === true
    })

    fenetre.addEventListener('storage', (evenement) => {
        if (evenement.key === CLEF_DU_TITULAIRE_DE_L_APPAREIL) {
            suivreLAppareil()
        }
    })

    fenetre.addEventListener('popstate', suivreLAppareil)

    fenetre.addEventListener('pageshow', (evenement) => {
        if (!evenement.persisted) {
            return
        }

        suivreLAppareil()

        if (!pageChiffree || laCleEstEncoreLa(onglet)) {
            return
        }

        fenetre.document.documentElement.style.visibility = 'hidden'
        fenetre.location.reload()
    })
}
