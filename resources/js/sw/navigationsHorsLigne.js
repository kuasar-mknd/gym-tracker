import { cleDeLaCopie, cleGardee } from '../Utils/pagesGardees.js'

/**
 * Les navigations : le réseau d'abord, la page « hors ligne » sans lui (#1966),
 * et au bout de huit secondes pour les pages de la salle (#2048, partie F).
 *
 * Le worker ne répondait qu'aux actifs construits. Une navigation, vers
 * l'accueil, une séance ou n'importe quelle page, passait au réseau, et sans
 * réseau le navigateur montrait sa propre page d'erreur : l'application
 * installée, que le système avait arrêtée pendant que le téléphone dormait, ne
 * se rouvrait pas dans une salle sans réseau.
 *
 * Chaque navigation passe désormais par le worker, qui la laisse au réseau tant
 * qu'il répond, et ne la sert lui-même que quand il ne répond pas : par la page
 * « hors ligne », précachée à l'installation, que la partie F fait coquille.
 * Le worker ne garde, ne lit ni ne sert rien d'un compte : ce sont la page et
 * ses copies chiffrées qui le font (`.ai/rules/js.md`). Une réponse du serveur,
 * quel que soit son statut, passe telle quelle.
 *
 * Trois sortes de navigations (`sorteDeNavigation`) :
 * - **les pages de la salle**, `/` et les chemins de la liste blanche
 *   (`cleGardee`, lue dans `pagesGardees.js`, de pures données) : la réponse
 *   préchargée, ou `fetch`, a huit secondes (décision 8 du propriétaire). Au
 *   délai, ou sans réseau, la coquille ;
 * - **les navigations qui ne reçoivent jamais la coquille**
 *   (`PREFIXES_SANS_COQUILLE`) : le rappel de la connexion sociale, le
 *   panneau, les tableaux de bord, les liens des courriels, les routes de
 *   Dusk. Le réseau, sans délai ; sans lui, l'erreur du navigateur ;
 * - **les autres** : le réseau, sans délai ; sans lui, la coquille.
 *
 * Ni délai ni coquille ne touchent donc le rappel d'un fournisseur de
 * connexion : coupé à huit secondes, il aurait posé la session sur une réponse
 * que personne ne lisait, derrière une page qui disait le contraire.
 */

/** La page « hors ligne », telle que la construction l'écrit et que le precache la range. */
export const URL_DE_LA_PAGE_HORS_LIGNE = '/build/hors-ligne.html'

/** Le délai d'une page de la salle avant la coquille (décision 8 du propriétaire). */
export const DELAI_DE_LA_NAVIGATION_MS = 8_000

/** La durée pendant laquelle « Attendre la réponse » lève le délai d'une adresse. */
export const DUREE_SANS_DELAI_MS = 60_000

/** Les adresses dont le délai est levé en même temps, au plus. */
const ADRESSES_SANS_DELAI_AU_PLUS = 16

/**
 * Le message de la coquille au worker : « Attendre la réponse ». Il porte
 * l'adresse (`adresse`), et le worker répond sur le port joint, s'il y en a
 * un, une fois le délai levé.
 */
export const MESSAGE_ATTENDRE_LA_REPONSE = 'gym-tracker:attendre-la-reponse'

/**
 * Le message du worker à la page qu'une navigation a ouverte sur la coquille,
 * au délai : la réponse arrivée ensuite est une redirection ou un 401. La page
 * juge, d'après le témoin des copies, si la session a pris fin
 * (`resources/js/Utils/messagesDuWorker.js`).
 */
export const MESSAGE_REPONSE_TARDIVE_RENVOYEE = 'gym-tracker:reponse-tardive-renvoyee'

/**
 * Les débuts de chemin des navigations qui ne reçoivent jamais la coquille ni
 * le délai : les rappels et redirections de la connexion sociale, le panneau
 * (et Pulse et les journaux, sous lui), Horizon, Telescope, les liens de
 * vérification et de réinitialisation qu'un courriel ouvre, les routes de
 * Dusk. Écrits en dur, comme l'adresse de la sonde : le worker ne lit pas la
 * configuration, et `ServiceWorkerAssetsTest` compare chacun aux routes et
 * aux chemins configurés. Un chemin changé par l'environnement
 * (`HORIZON_PATH`, `TELESCOPE_PATH`) se change ici aussi.
 *
 * Une entrée par ligne, que le test lit ainsi.
 */
export const PREFIXES_SANS_COQUILLE = Object.freeze([
    '/auth/',
    '/backoffice',
    '/horizon',
    '/telescope',
    '/verify-email/',
    '/reset-password/',
    '/_dusk/',
])

/** Une navigation que le worker prend en charge : un document demandé en GET. */
export const estUneNavigation = (requete) => requete.mode === 'navigate' && requete.method === 'GET'

/**
 * L'adresse d'une navigation, sans son fragment, ou `null` hors de l'origine.
 *
 * @param {unknown} adresse
 * @param {string} origine
 * @returns {URL | null}
 */
const adresseDeLOrigine = (adresse, origine) => {
    if (typeof adresse !== 'string') {
        return null
    }

    try {
        const url = new URL(adresse, origine)

        if (url.origin !== new URL(origine).origin) {
            return null
        }

        url.hash = ''

        return url
    } catch {
        return null
    }
}

/**
 * Le chemin décodé, comme le routeur de Laravel le lit : `/%61uth/…` est
 * encore `/auth/…`. Un chemin qui ne se décode pas reste tel quel.
 *
 * @param {URL} url
 */
const cheminDecode = (url) => {
    try {
        return decodeURIComponent(url.pathname)
    } catch {
        return url.pathname
    }
}

/**
 * La sorte d'une navigation : `salle` (le délai, puis la coquille),
 * `sans-coquille` (le réseau seul) ou `ordinaire` (le réseau, puis la
 * coquille).
 *
 * @param {string} adresse l'adresse de la requête
 * @param {string} origine l'origine du worker
 * @returns {'salle' | 'sans-coquille' | 'ordinaire'}
 */
export const sorteDeNavigation = (adresse, origine) => {
    const url = adresseDeLOrigine(adresse, origine)

    if (url === null) {
        return 'sans-coquille'
    }

    const chemin = cheminDecode(url)

    if (PREFIXES_SANS_COQUILLE.some((prefixe) => chemin.startsWith(prefixe))) {
        return 'sans-coquille'
    }

    return cleGardee(cleDeLaCopie(`${url.pathname}${url.search}`)) ? 'salle' : 'ordinaire'
}

/**
 * Le préchargement des navigations, là où le navigateur l'offre.
 *
 * Le navigateur lance alors la requête du document pendant que le worker
 * démarre, au lieu d'attendre qu'il la refasse : passer par le worker ne coûte
 * pas son démarrage à chaque ouverture, et la requête reste celle d'une
 * navigation, avec ses cookies et ses en-têtes. C'est aussi pourquoi le worker
 * répond à chaque navigation, même celles qu'il laisse au réseau : sans
 * `respondWith`, le navigateur referait la requête, et un rappel de connexion
 * sociale partirait deux fois.
 *
 * @param {ServiceWorkerRegistration} inscription
 */
export const activerLePrechargementDesNavigations = async (inscription) => {
    await inscription.navigationPreload?.enable()
}

/**
 * Garde le worker en vie jusqu'à ce que la réponse préchargée soit réglée, et
 * rattrape son échec (#2048).
 *
 * Sans réseau, cette promesse est rejetée. Les navigations l'attendent, mais
 * seul `waitUntil` garantit au navigateur qu'elle sera attendue : un worker
 * arrêté avant son règlement la fait annuler (« The service worker navigation
 * preload request was cancelled before 'preloadResponse' settled », dans la
 * console de Chrome). Le `catch` garde son rejet d'être la raison de
 * l'attente. Un navigateur sans préchargement n'a pas de promesse.
 *
 * @param {{ preloadResponse?: Promise<Response|undefined>, waitUntil: (promesse: Promise<unknown>) => void }} event
 */
export const garderLePrechargement = (event) => {
    if (event.preloadResponse) {
        event.waitUntil(event.preloadResponse.catch(() => {}))
    }
}

/**
 * La réponse du réseau à une navigation : celle que le navigateur a
 * préchargée, ou une requête du worker là où rien ne l'a été. Rejetée sans
 * réseau.
 *
 * @param {{request: Request, preloadResponse?: Promise<Response|undefined>}} event
 * @param {(requete: Request) => Promise<Response>} allerChercher
 * @returns {Promise<Response>}
 */
const reponseDuReseau = async (event, allerChercher) => (await event.preloadResponse) ?? allerChercher(event.request)

/**
 * Le réseau, ou la page « hors ligne » quand il ne répond pas : une
 * navigation ordinaire, ou une page de la salle dont le délai est levé.
 *
 * @param {{request: Request, preloadResponse?: Promise<Response|undefined>}} event
 * @param {(raison: 'reseau') => Promise<Response|undefined>} trouverLaPageHorsLigne
 * @param {(requete: Request) => Promise<Response>} allerChercher
 * @returns {Promise<Response>}
 */
export const naviguerOuRetomber = async (event, trouverLaPageHorsLigne, allerChercher) => {
    try {
        return await reponseDuReseau(event, allerChercher)
    } catch {
        return (await trouverLaPageHorsLigne('reseau')) ?? Response.error()
    }
}

/**
 * Le réseau seul : une navigation qui ne reçoit jamais la coquille. Sans
 * réseau, l'erreur du navigateur.
 *
 * @param {{request: Request, preloadResponse?: Promise<Response|undefined>}} event
 * @param {(requete: Request) => Promise<Response>} allerChercher
 * @returns {Promise<Response>}
 */
export const naviguerSansCoquille = async (event, allerChercher) => {
    try {
        return await reponseDuReseau(event, allerChercher)
    } catch {
        return Response.error()
    }
}

/**
 * Si une réponse arrivée après le délai peut dire la fin de la session : une
 * redirection (`opaqueredirect` pour une navigation, qui ne suit pas les
 * redirections) ou un 401. La page en juge d'après le témoin : `/` renvoie
 * toujours vers l'accueil, session vivante.
 *
 * @param {Response | undefined} reponse
 */
export const peutDireLaFinDeLaSession = (reponse) =>
    reponse !== undefined &&
    reponse !== null &&
    (reponse.type === 'opaqueredirect' || reponse.status === 401 || (reponse.status >= 300 && reponse.status < 400))

/** Ce que rend le délai, quand il gagne la course. */
const AU_DELAI = Symbol('au délai')

/**
 * Une page de la salle : le réseau, huit secondes au plus, puis la coquille.
 *
 * Au délai, la réponse n'est pas abandonnée : le worker l'attend
 * (`waitUntil`, posé dès l'appel, tant que l'évènement est en cours). Une
 * redirection ou un 401 arrivés ensuite sont annoncés à la page que la
 * navigation a ouverte (`resultingClientId`), seule à les attendre ; la page
 * de la coquille ne s'en sert que si le témoin a changé. Le corps d'une
 * réponse tardive est abandonné.
 *
 * @param {{request: Request, preloadResponse?: Promise<Response|undefined>, resultingClientId?: string, waitUntil: (promesse: Promise<unknown>) => void}} event
 * @param {{
 *   trouverLaPageHorsLigne: (raison: 'delai' | 'reseau') => Promise<Response|undefined>,
 *   allerChercher: (requete: Request) => Promise<Response>,
 *   prevenirLaPage: (identifiant: string | undefined) => Promise<void> | void,
 *   delai?: number,
 * }} outils
 * @returns {Promise<Response>}
 */
export const naviguerAvecUnDelai = async (
    event,
    { trouverLaPageHorsLigne, allerChercher, prevenirLaPage, delai = DELAI_DE_LA_NAVIGATION_MS },
) => {
    const reponse = reponseDuReseau(event, allerChercher)
    /*
     * Marqué par la minuterie elle-même, avant que la course ne se règle : une
     * réponse qui arrive juste après le délai est bien une réponse tardive.
     */
    const suivi = { enRetard: false }

    event.waitUntil(
        reponse
            .then(async (tardive) => {
                if (!suivi.enRetard) {
                    return
                }

                tardive?.body?.cancel?.().catch(() => {})

                if (peutDireLaFinDeLaSession(tardive)) {
                    await prevenirLaPage(event.resultingClientId)
                }
            })
            .catch(() => {}),
    )

    /** @type {ReturnType<typeof setTimeout> | undefined} */
    let minuterie

    try {
        const premiere = await Promise.race([
            reponse,
            new Promise((resoudre) => {
                minuterie = setTimeout(() => {
                    suivi.enRetard = true
                    resoudre(AU_DELAI)
                }, delai)
            }),
        ])

        if (premiere !== AU_DELAI) {
            return premiere
        }
    } catch {
        return (await trouverLaPageHorsLigne('reseau')) ?? Response.error()
    } finally {
        clearTimeout(minuterie)
    }

    return (await trouverLaPageHorsLigne('delai')) ?? Response.error()
}

/**
 * Les navigations du worker, et le délai que « Attendre la réponse » lève.
 *
 * Le délai levé vit en mémoire : la coquille envoie son message, puis
 * recharge aussitôt, et la navigation qui suit trouve le même worker.
 * Arrêté entre-temps, il l'aurait oublié, et la coquille revient au délai,
 * avec de nouveau le bouton. Seule une adresse de la salle de l'origine du
 * worker se lève, seize au plus à la fois.
 *
 * @param {{
 *   trouverLaPageHorsLigne: (raison: 'delai' | 'reseau') => Promise<Response|undefined>,
 *   allerChercher: (requete: Request) => Promise<Response>,
 *   trouverLeClient: (identifiant: string) => Promise<{ postMessage: (message: unknown) => void } | undefined | null>,
 *   origine: string,
 *   maintenant?: () => number,
 *   delai?: number,
 * }} options
 */
export const creerLesNavigations = ({
    trouverLaPageHorsLigne,
    allerChercher,
    trouverLeClient,
    origine,
    maintenant = () => Date.now(),
    delai = DELAI_DE_LA_NAVIGATION_MS,
}) => {
    /** @type {Map<string, number>} l'adresse, et la fin de la levée */
    const sansDelai = new Map()

    const oublierLesLeveesPassees = () => {
        const instant = maintenant()

        for (const [adresse, fin] of sansDelai) {
            if (fin <= instant) {
                sansDelai.delete(adresse)
            }
        }
    }

    /** @param {string} adresse */
    const leDelaiEstLeve = (adresse) => {
        oublierLesLeveesPassees()

        return sansDelai.has(adresse)
    }

    /** @param {string | undefined} identifiant */
    const prevenirLaPage = async (identifiant) => {
        if (typeof identifiant !== 'string' || identifiant === '') {
            return
        }

        const client = await trouverLeClient(identifiant)

        client?.postMessage({ type: MESSAGE_REPONSE_TARDIVE_RENVOYEE })
    }

    /**
     * La réponse à une navigation, d'après sa sorte.
     *
     * @param {{request: Request, preloadResponse?: Promise<Response|undefined>, resultingClientId?: string, waitUntil: (promesse: Promise<unknown>) => void}} event
     * @returns {Promise<Response>}
     */
    const repondre = (event) => {
        const sorte = sorteDeNavigation(event.request.url, origine)

        if (sorte === 'sans-coquille') {
            return naviguerSansCoquille(event, allerChercher)
        }

        const adresse = adresseDeLOrigine(event.request.url, origine)?.href

        if (sorte === 'ordinaire' || leDelaiEstLeve(adresse)) {
            return naviguerOuRetomber(event, trouverLaPageHorsLigne, allerChercher)
        }

        return naviguerAvecUnDelai(event, { trouverLaPageHorsLigne, allerChercher, prevenirLaPage, delai })
    }

    /**
     * Un message d'une page. « Attendre la réponse » lève le délai de
     * l'adresse pendant une minute, puis le dit sur le port joint ; tout
     * autre message est laissé. Rend vrai quand le message était le sien.
     *
     * @param {{ data?: unknown, ports?: ReadonlyArray<MessagePort> }} event
     * @returns {boolean}
     */
    const recevoirUnMessage = (event) => {
        const donnees = /** @type {{ type?: unknown, adresse?: unknown } | null | undefined} */ (event.data)

        if (donnees?.type !== MESSAGE_ATTENDRE_LA_REPONSE) {
            return false
        }

        const url = adresseDeLOrigine(donnees.adresse, origine)
        const levee = url !== null && sorteDeNavigation(url.href, origine) === 'salle'

        if (levee) {
            oublierLesLeveesPassees()
            sansDelai.set(url.href, maintenant() + DUREE_SANS_DELAI_MS)

            while (sansDelai.size > ADRESSES_SANS_DELAI_AU_PLUS) {
                sansDelai.delete(sansDelai.keys().next().value)
            }
        }

        event.ports?.[0]?.postMessage({ type: MESSAGE_ATTENDRE_LA_REPONSE, levee })

        return true
    }

    return { repondre, recevoirUnMessage }
}
