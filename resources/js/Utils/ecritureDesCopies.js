import { auRepos } from '@/Utils/auRepos'
import { magasinDesCopies } from '@/Utils/copiesHorsLigne'
import { pageInitialeDuDocument } from '@/Utils/historiqueDuCompte'
import { cleDeLaCopie, cleDeLaSeanceEnCours, pageGardee } from '@/Utils/pagesGardees'
import { estUneAdresseDEcritureDeLaSeance } from '@/Utils/seanceEtFile'

/**
 * L'écriture des copies hors ligne (#2048, partie D, décisions du propriétaire
 * du 2026-10-12) : la page garde, dans le magasin chiffré
 * (`copiesHorsLigne.js`), la copie de chaque page gardée qu'elle reçoit du
 * réseau. Elle ne les sert pas encore (parties E et F).
 *
 * **Deux moments.** Sur `success`, qu'Inertia n'émet que pour une réponse du
 * réseau portant `x-inertia`, sans erreur de validation ; et sur la page
 * initiale du document (`script[data-page]`), pour laquelle Inertia n'émet
 * pas `success` : sans elle, ouvrir l'application ne garderait rien. La page
 * initiale ne compte que venue du réseau : ni un document rouvert par les
 * boutons Retour ou Avance (`performance.getEntriesByType('navigation')`,
 * `back_forward`), dont Inertia montre l'entrée d'historique, ni la coquille
 * hors ligne (partie F, `data-coquille`), ni une page qui porte la marque
 * d'une copie (`props.horsLigne`, partie E).
 *
 * **Les conditions** sont celles du magasin, revérifiées au moment d'écrire :
 * un composant de la liste blanche à son chemin, `encryptHistory` vrai (le
 * serveur ne le pose que dans un contexte sûr : rien n'est gardé en http), la
 * clé du témoin présente et non fermée, le titulaire de l'appareil égal au
 * compte de la page. Une autre clé ou un autre compte vident d'abord le
 * magasin, dans la transaction même de l'écriture.
 *
 * **Les réponses partielles** (un rechargement partiel, des props différées)
 * ne versent que leurs props dans une copie existante, sans jamais en créer
 * une ni remplacer `auth`. Une visite est partielle par ses `only`, `except`
 * ou `reset`, que l'évènement `before` donne ; elle ne l'est plus quand la
 * réponse rend un autre composant que la page qu'elle quittait : le serveur
 * a alors ignoré la demande et tout rendu, et Inertia n'a rien fusionné.
 *
 * **Au repos.** Une copie se décide sur l'évènement (la génération du
 * magasin et l'instantané de la page sont pris à ce moment) et s'écrit plus
 * tard, par `auRepos`, une à la fois : les props différées d'une page
 * arrivent souvent avant que sa copie soit écrite, et doivent la trouver.
 * Une purge survenue entre-temps la refuse.
 *
 * **Les purges** qui ne passent pas par un geste : la fin de la session que
 * la file hors ligne constate (`sync:auth-required`, sur un 401 ou un 419
 * du rejeu), la clé du témoin fermée avec elle ; et l'hygiène du magasin à
 * chaque chargement (sept jours, un autre contrat). La déconnexion, la
 * suppression du compte et le changement de titulaire ont les leurs
 * (`useDeconnexion`, `DeleteUserForm`, la garde de `historiqueDuCompte`).
 *
 * **La séance en cours.** Chaque page complète dit la séance que le compte a
 * en cours (`auth.user.active_workout`). À la première de l'onglet, puis
 * chaque fois qu'elle change, les copies des autres séances qui les montrent
 * encore en cours se retirent (`retirerLesSeancesQuiNeSontPlusEnCours`), et
 * la page même, si c'est l'une d'elles (le compte rendu d'une séance close),
 * se réécrit juste après depuis cette réponse : terminer une séance mène à
 * l'accueil, et sa copie aurait fait croire hors ligne qu'elle attendait
 * encore des séries. La
 * première page compte aussi, puisqu'une séance a pu se clore ailleurs, sur
 * un autre appareil ou application fermée. Un rejeu d'écriture de séance par la file
 * hors ligne (`sync:replayed`) date le dernier rejeu dans l'état du magasin :
 * la copie de la séance, écrite avant, ne l'a pas, et le préchauffage la
 * redemande (`prechauffage.js`). Sur la page de séance, l'instantané qui suit
 * le rechargement d'après le vidage la rafraîchit avant lui.
 *
 * **Pour les autres.** L'écriture installée se lit par
 * `ecritureDesCopiesInstallee()` : la page de séance y verse son instantané
 * (`useCopieDeLaSeance`), et le préchauffage les pages qu'il demande, dans la
 * même file, dans l'ordre des décisions. `presser()` fait passer tout de
 * suite ce qui attend le repos, quand la page se cache ; `origineDeLaPage()`
 * dit d'où vient la page affichée : du réseau, de l'historique de l'onglet,
 * ou d'une visite côté client ; `lireLaCopie()` relit une copie, que la page
 * de séance compare à une séance rendue par l'historique.
 *
 * Rien n'est gardé d'un compte à l'autre dans ce module : il ne retient que
 * la forme des visites en vol et la dernière page reçue, celle qu'Inertia
 * tient aussi, jamais la charge d'une visite.
 */

/** Les visites dont la forme est retenue, au plus : celles d'une réponse qui ne viendra pas sortent. */
const VISITES_RETENUES = 32

/**
 * @typedef {'reseau' | 'historique' | 'client' | 'aucune'} OrigineDeLaPage
 *   `reseau` : une réponse du serveur, ou la page initiale d'un document venu
 *   du réseau ; `historique` : une entrée de l'historique de l'onglet,
 *   boutons Retour et Avance, ou un document rouvert par eux ; `client` : une
 *   visite côté client (`router.push`, `router.replace`) ; `aucune` : pas de
 *   page encore.
 */

/**
 * Les écritures installées par `installerLEcritureDesCopies` et pas encore
 * désinstallées : une par document, plusieurs dans un test qui rejoue un
 * autre chargement.
 *
 * @type {Array<ReturnType<typeof installerLEcritureDesCopies>>}
 */
const ecrituresInstallees = []

/**
 * L'écriture installée en dernier, ou `null` avant `installerLEcritureDesCopies`
 * (et dans un test qui ne l'installe pas) : la page de séance et le
 * préchauffage y passent leurs copies.
 */
export const ecritureDesCopiesInstallee = () => ecrituresInstallees.at(-1) ?? null

/**
 * La forme d'une visite, telle que l'évènement `before` la donne : ce qu'elle
 * a demandé, sans rien de sa charge.
 *
 * @param {{ only?: string[], except?: string[], reset?: string[] }} visite
 * @returns {{ only: string[], except: string[], reset: string[] }}
 */
const formeDe = (visite) => ({
    only: [...(visite.only ?? [])].map(String),
    except: [...(visite.except ?? [])].map(String),
    reset: [...(visite.reset ?? [])].map(String),
})

/**
 * Si la visite demandait une partie des props.
 *
 * @param {{ only: string[], except: string[], reset: string[] }} forme
 */
const estPartielle = (forme) => forme.only.length > 0 || forme.except.length > 0 || forme.reset.length > 0

/**
 * Les props de premier niveau qu'une réponse partielle a reçues. `only` et
 * `reset` les nomment (`a.b` est reçue dans `a`, qu'Inertia fusionne en
 * profondeur) ; `except` les écarte, et une prop dont seule une partie est
 * écartée reste reçue.
 *
 * @param {{ only: string[], except: string[], reset: string[] }} forme
 * @param {{ props?: object }} page la page après la réponse
 * @returns {string[]}
 */
export const propsRecues = (forme, page) => {
    const nommees = [...forme.only, ...forme.reset]

    if (nommees.length > 0) {
        return [...new Set(nommees.map((nom) => nom.split('.')[0]))]
    }

    const ecartees = new Set(forme.except.filter((nom) => !nom.includes('.')))

    return Object.keys(page.props ?? {}).filter((nom) => !ecartees.has(nom))
}

/**
 * Si le document vient du réseau : ni rouvert par les boutons Retour ou
 * Avance, ni la coquille hors ligne.
 *
 * @param {Window} fenetre
 */
export const documentVenuDuReseau = (fenetre) => {
    try {
        if (fenetre.performance?.getEntriesByType?.('navigation')?.[0]?.type === 'back_forward') {
            return false
        }

        return !fenetre.document?.querySelector?.('[data-coquille]')
    } catch {
        return false
    }
}

/** La table des routes que `@routes` déclare dans le document (`const Ziggy`), ou `null`. */
const tableDesRoutesDuDocument = () => (typeof Ziggy === 'undefined' ? (globalThis.Ziggy ?? null) : Ziggy)

/**
 * Une copie profonde, prise quand la copie se décide : un composant qui
 * modifierait ensuite les props ne change pas ce que le réseau a rendu.
 *
 * @template T
 * @param {T} valeur
 * @returns {T | null}
 */
const instantaneDe = (valeur) => {
    try {
        return structuredClone(valeur)
    } catch {
        return null
    }
}

/**
 * Si la page est d'une adresse et d'un composant gardés, l'historique
 * chiffré : celles-là seules méritent une copie.
 *
 * @param {object | null | undefined} page
 */
const meriteUneCopie = (page) =>
    page !== null &&
    typeof page === 'object' &&
    page.encryptHistory === true &&
    pageGardee(cleDeLaCopie(page.url), page.component) !== null

/**
 * Branche l'écriture des copies, une fois par document, avant
 * `createInertiaApp` : la page initiale y est lue, et les évènements du
 * routeur suivis dès la première visite. Rend de quoi la débrancher (les
 * tests).
 *
 * @param {{
 *   routeur: { on: (evenement: string, rappel: (evenement: CustomEvent) => void) => () => void },
 *   magasin?: ReturnType<typeof magasinDesCopies>,
 *   fenetre?: Window,
 *   pageInitiale?: object | null,
 *   tableDesRoutes?: () => object | null,
 *   planifier?: (tache: () => void) => void,
 * }} options
 * @returns {{
 *   desinstaller: () => void,
 *   ecrituresFinies: () => Promise<void>,
 *   garder: (page: object) => void,
 *   completer: (page: object, noms: string[]) => void,
 *   presser: () => void,
 *   origineDeLaPage: () => OrigineDeLaPage,
 *   lireLaCopie: (adresse: string) => Promise<object | null>,
 * }} `ecrituresFinies` se règle quand tout ce qui a été décidé est écrit ou
 *   refusé ; `garder` et `completer` décident la copie d'une page complète
 *   ou partielle reçue d'ailleurs que d'une visite (le préchauffage, la page
 *   de séance) ; `presser` fait passer sans attendre le repos ce qui est
 *   décidé ; `origineDeLaPage` dit d'où vient la page affichée ;
 *   `lireLaCopie` déchiffre une copie pour la comparer, jamais pour la
 *   servir (partie E).
 */
export const installerLEcritureDesCopies = ({
    routeur,
    magasin = magasinDesCopies(),
    fenetre = window,
    pageInitiale = pageInitialeDuDocument(fenetre.document),
    tableDesRoutes = tableDesRoutesDuDocument,
    planifier = auRepos,
}) => {
    /** @type {Map<unknown, { only: string[], except: string[], reset: string[] }>} */
    const visites = new Map()
    let pageCourante = pageInitiale ?? null
    /** @type {{ page: object, composantAvant: string | null } | null} */
    let derniereReponse = null
    let file = Promise.resolve()
    let branchee = true
    /** @type {OrigineDeLaPage} */
    let origine = pageInitiale === null || pageInitiale === undefined ? 'aucune' : 'historique'
    /** @type {Set<() => void>} */
    const enAttenteDuRepos = new Set()

    /**
     * La séance en cours que la dernière page complète du compte a dite :
     * `undefined` tant qu'aucune page ne l'a dite, `null` sans séance.
     *
     * @type {string | null | undefined}
     */
    let seanceEnCours

    /**
     * Fait passer une écriture au repos, après celles décidées avant elle : la
     * génération du magasin se note ici, au moment de décider, et l'ordre
     * des décisions est celui des écritures. `presser` lève l'attente du repos
     * de tout ce qui est décidé. Ce qui ne fait que relire et retirer des
     * copies, sans en écrire (`sansAttendreLeRepos`), passe dès que son tour
     * vient.
     *
     * @param {(generation: number) => Promise<unknown>} ecrire
     * @param {{ sansAttendreLeRepos?: boolean }} [options]
     */
    const decider = (ecrire, { sansAttendreLeRepos = false } = {}) => {
        const generation = magasin.generationCourante()
        let liberer
        const auReposAtteint = new Promise((resoudre) => {
            liberer = resoudre
        })

        if (sansAttendreLeRepos) {
            liberer()
        } else {
            enAttenteDuRepos.add(liberer)
            auReposAtteint.then(() => enAttenteDuRepos.delete(liberer))
            planifier(() => liberer())
        }

        file = file
            .then(() => auReposAtteint)
            .then(() => (branchee ? ecrire(generation) : undefined))
            .catch(() => {
                // Le magasin rend ses refus sans lever : rien à faire d'autre.
            })
    }

    /**
     * Suit la séance en cours que dit une page complète du compte. À la
     * première, puis quand elle change, les copies des autres séances qui les
     * montrent encore en cours se retirent ; celle de la page même, si elle
     * en est, est réécrite juste après par cette réponse, qui la montre telle
     * que le serveur la rend maintenant.
     *
     * @param {object} page
     */
    const suivreLaSeanceEnCours = (page) => {
        const utilisateur = page?.props?.auth?.user

        if (utilisateur === null || typeof utilisateur !== 'object' || !('active_workout' in utilisateur)) {
            return
        }

        const nouvelle = cleDeLaSeanceEnCours(page)

        if (nouvelle === seanceEnCours) {
            return
        }

        seanceEnCours = nouvelle
        decider((generation) => magasin.retirerLesSeancesQuiNeSontPlusEnCours(nouvelle, { generation }), {
            sansAttendreLeRepos: true,
        })
    }

    /**
     * La copie d'une page reçue en entier.
     *
     * @param {object} page
     */
    const garder = (page) => {
        if (page?.props?.horsLigne !== undefined) {
            return
        }

        suivreLaSeanceEnCours(page)

        if (!meriteUneCopie(page)) {
            return
        }

        const instantane = instantaneDe(page)
        const ziggy = instantaneDe(tableDesRoutes())

        if (instantane !== null) {
            decider((generation) => magasin.ecrireUneCopie(instantane, { ziggy, generation }))
        }
    }

    /**
     * Les props qu'une réponse partielle a reçues, versées dans la copie de
     * sa page. `auth` reste celui de la dernière réponse complète : il dit le
     * compte, et la copie garde le sien.
     *
     * @param {object} page
     * @param {string[]} noms
     */
    const completer = (page, noms) => {
        if (!meriteUneCopie(page)) {
            return
        }

        const recues = noms.filter((nom) => nom !== 'auth' && Object.hasOwn(page.props ?? {}, nom))

        if (recues.length === 0) {
            return
        }

        const instantane = instantaneDe({
            ...page,
            props: Object.fromEntries([['auth', page.props.auth], ...recues.map((nom) => [nom, page.props[nom]])]),
        })

        if (instantane !== null) {
            decider((generation) => magasin.completerUneCopie(instantane, recues, { generation }))
        }
    }

    /** @param {CustomEvent} evenement */
    const surUneVisite = (evenement) => {
        const visite = evenement.detail?.visit

        if (visite?.id === undefined) {
            return
        }

        visites.set(visite.id, formeDe(visite))

        if (visites.size > VISITES_RETENUES) {
            visites.delete(visites.keys().next().value)
        }
    }

    /** @param {CustomEvent} evenement */
    const avantLaMiseAJour = (evenement) => {
        const page = evenement.detail?.page ?? null

        derniereReponse = page === null ? null : { page, composantAvant: pageCourante?.component ?? null }
        pageCourante = page ?? pageCourante
        origine = 'reseau'
    }

    /** @param {CustomEvent} evenement */
    const suivreLaPage = (evenement) => {
        pageCourante = evenement.detail?.page ?? pageCourante
    }

    /** @param {CustomEvent} evenement */
    const suivreUneVisiteCoteClient = (evenement) => {
        suivreLaPage(evenement)
        origine = 'client'
    }

    /**
     * Les boutons Retour et Avance : Inertia rend l'entrée d'historique, sans
     * le serveur. Écouté avant Inertia, installé après.
     *
     * @param {PopStateEvent} evenement
     */
    const auRetourOuALAvance = (evenement) => {
        if (evenement.state?.page !== undefined) {
            origine = 'historique'
        }
    }

    /**
     * Une écriture de séance rejouée par la file : la copie de la séance,
     * écrite avant, ne l'a pas.
     *
     * @param {CustomEvent} evenement
     */
    const surUnRejeu = (evenement) => {
        if (estUneAdresseDEcritureDeLaSeance(evenement.detail?.url)) {
            magasin.noterUnRejeu()
        }
    }

    /** @param {CustomEvent} evenement */
    const surUneReponse = (evenement) => {
        const page = evenement.detail?.page
        const forme = visites.get(evenement.detail?.visitId)
        const reponse = derniereReponse

        visites.delete(evenement.detail?.visitId)
        derniereReponse = null

        // Une réponse qu'Inertia a posée, d'une visite dont on connaît la forme.
        if (page === undefined || reponse?.page !== page || forme === undefined) {
            return
        }

        if (estPartielle(forme) && (reponse.composantAvant === null || reponse.composantAvant === page.component)) {
            completer(page, propsRecues(forme, page))

            return
        }

        garder(page)
    }

    /** La file hors ligne voit la porte fermée (401, 419) : la session est finie. */
    const surLaFinDeLaSession = () => {
        magasin.oublierLesCopies({ fermerLeTemoin: true })
    }

    const arrets = [
        routeur.on('before', surUneVisite),
        routeur.on('beforeUpdate', avantLaMiseAJour),
        routeur.on('navigate', suivreLaPage),
        routeur.on('clientVisit', suivreUneVisiteCoteClient),
        routeur.on('success', surUneReponse),
    ]

    fenetre.addEventListener('sync:auth-required', surLaFinDeLaSession)
    fenetre.addEventListener('sync:replayed', surUnRejeu)
    fenetre.addEventListener('popstate', auRetourOuALAvance)

    // L'hygiène d'abord : une copie de plus de sept jours, ou d'un autre contrat, ne sert plus.
    decider(() => magasin.entretenir())

    if (pageInitiale !== null && pageInitiale !== undefined && documentVenuDuReseau(fenetre)) {
        origine = 'reseau'
        garder(pageInitiale)
    }

    const installee = {
        desinstaller: () => {
            branchee = false
            arrets.forEach((arreter) => (typeof arreter === 'function' ? arreter() : undefined))
            fenetre.removeEventListener('sync:auth-required', surLaFinDeLaSession)
            fenetre.removeEventListener('sync:replayed', surUnRejeu)
            fenetre.removeEventListener('popstate', auRetourOuALAvance)

            if (ecrituresInstallees.includes(installee)) {
                ecrituresInstallees.splice(ecrituresInstallees.indexOf(installee), 1)
            }
        },
        ecrituresFinies: async () => {
            let attendue

            do {
                attendue = file
                await attendue
            } while (attendue !== file)
        },
        garder: (page) => {
            if (branchee) {
                garder(page)
            }
        },
        completer: (page, noms) => {
            if (branchee) {
                completer(page, noms)
            }
        },
        presser: () => {
            for (const liberer of enAttenteDuRepos) {
                liberer()
            }
        },
        origineDeLaPage: () => origine,
        lireLaCopie: (adresse) => magasin.lireUneCopie(adresse),
    }

    ecrituresInstallees.push(installee)

    return installee
}
