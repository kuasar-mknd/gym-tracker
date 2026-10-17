import { auRepos } from '@/Utils/auRepos'
import { magasinDesCopies } from '@/Utils/copiesHorsLigne'
import { pageInitialeDuDocument } from '@/Utils/historiqueDuCompte'
import { cleDeLaCopie, pageGardee } from '@/Utils/pagesGardees'

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
 * Rien n'est gardé d'un compte à l'autre dans ce module : il ne retient que
 * la forme des visites en vol et la dernière page reçue, celle qu'Inertia
 * tient aussi, jamais la charge d'une visite.
 */

/** Les visites dont la forme est retenue, au plus : celles d'une réponse qui ne viendra pas sortent. */
const VISITES_RETENUES = 32

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
 * @returns {{ desinstaller: () => void, ecrituresFinies: () => Promise<void> }}
 *   `ecrituresFinies` se règle quand tout ce qui a été décidé est écrit ou
 *   refusé.
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

    /**
     * Fait passer une écriture au repos, après celles décidées avant elle : la
     * génération du magasin se note ici, au moment de décider, et l'ordre
     * des décisions est celui des écritures.
     *
     * @param {(generation: number) => Promise<unknown>} ecrire
     */
    const decider = (ecrire) => {
        const generation = magasin.generationCourante()
        const auReposAtteint = new Promise((resoudre) => planifier(resoudre))

        file = file
            .then(() => auReposAtteint)
            .then(() => (branchee ? ecrire(generation) : undefined))
            .catch(() => {
                // Le magasin rend ses refus sans lever : rien à faire d'autre.
            })
    }

    /**
     * La copie d'une page reçue en entier.
     *
     * @param {object} page
     */
    const garder = (page) => {
        if (!meriteUneCopie(page) || page.props?.horsLigne !== undefined) {
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
    }

    /** @param {CustomEvent} evenement */
    const suivreLaPage = (evenement) => {
        pageCourante = evenement.detail?.page ?? pageCourante
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

    const arrets = [
        routeur.on('before', surUneVisite),
        routeur.on('beforeUpdate', avantLaMiseAJour),
        routeur.on('navigate', suivreLaPage),
        routeur.on('clientVisit', suivreLaPage),
        routeur.on('success', surUneReponse),
    ]

    if (pageInitiale !== null && pageInitiale !== undefined && documentVenuDuReseau(fenetre)) {
        garder(pageInitiale)
    }

    return {
        desinstaller: () => {
            branchee = false
            arrets.forEach((arreter) => (typeof arreter === 'function' ? arreter() : undefined))
        },
        ecrituresFinies: async () => {
            let attendue

            do {
                attendue = file
                await attendue
            } while (attendue !== file)
        },
    }
}
