import { magasinDesCopies } from '@/Utils/copiesHorsLigne'
import { lireLeDernierPrechauffage, noterLeDernierPrechauffage } from '@/Utils/dernierPrechauffage'
import { documentVenuDuReseau, ecritureDesCopiesInstallee } from '@/Utils/ecritureDesCopies'
import { pageInitialeDuDocument } from '@/Utils/historiqueDuCompte'
import {
    COMPOSANT_DE_LA_SEANCE,
    PAGES_GARDEES,
    cleDeLaCopie,
    cleDeLaSeanceEnCours,
    pageGardee,
} from '@/Utils/pagesGardees'

/**
 * Le préchauffage des copies hors ligne (#2048, partie D, décision 7 du
 * propriétaire du 2026-10-12).
 *
 * La page ne garde que ce qu'elle reçoit (`ecritureDesCopies.js`) : une page
 * du noyau de salle jamais ouverte depuis le début de la session n'aurait
 * aucune copie. Le préchauffage les demande au serveur, comme Inertia les
 * demanderait, et les passe à l'écriture des copies, dans sa file.
 *
 * **Quand il part.** Après une vraie réponse du serveur, `success` d'une
 * visite ou page initiale venue du réseau, la page visible : il ne prolonge
 * donc aucune session par lui-même, et ne part jamais d'une page rendue par
 * l'historique, d'une visite côté client ou d'une copie. Seulement s'il
 * manque une copie du noyau, si l'une a plus de deux heures, ou si la copie
 * de la séance en cours est plus ancienne que le dernier rejeu d'une de ses
 * écritures par la file (`dernierRejeu`). Au plus une fois par trente
 * minutes sous une même clé de session (`gym-tracker:dernier-prechauffage`,
 * `Utils/dernierPrechauffage.js`, pour tous les onglets), une note que toute
 * purge du magasin efface : des copies oubliées sous une clé restée ouverte
 * se refont à la réponse suivante.
 *
 * **Jamais sous les parcours navigateur** (`is_testing`, que le serveur ne
 * pose vrai que sous Dusk ou en test, jamais en production). Ses GET partent
 * en arrière-plan et croisaient la connexion du parcours suivant
 * (`loginAs`, qui remplace la session) : la page suivante tombait une fois
 * sur deux sur la page de connexion (`WorkoutSyncRaceTest`). Le préchauffage
 * se joue avec le vrai client d'Inertia sous Vitest
 * (`prechauffageAvecInertia.test.js`).
 *
 * **Comment.** Au plus huit GET (`GET_AU_PLUS`), l'un après l'autre, par
 * `fetch`, avec `X-Inertia`, la `X-Inertia-Version` de la page,
 * `X-Requested-With` et `redirect: 'manual'`, chacun sous un délai de quinze
 * secondes. Un 409 (une nouvelle version) arrête tout. Une redirection
 * (`opaqueredirect`) ou un 401 ne disent la fin de la session que si le
 * témoin a disparu ou changé, ce que le serveur fait sur toute réponse qui la
 * dit (`CleDesCopiesHorsLigne`) : les copies s'oublient alors, la clé de
 * l'inventaire fermée, et il s'arrête. Un témoin inchangé dit une session
 * vivante, que la page demandée renvoie ailleurs (l'accueil exige une adresse
 * vérifiée, et renvoie un compte qui ne l'a pas encore fait vers la page qui
 * le lui demande) : cette page ne se garde pas, et il continue. Sans réseau,
 * ou au délai, il s'arrête aussi. Une réponse qui n'est pas la page demandée
 * ne se garde pas.
 *
 * **Les props différées.** Celle de l'accueil se demande en partiel, et se
 * verse dans sa copie ; celle de la liste des séances, jamais. Celles qu'une
 * copie avait déjà restent, avec leur date (`ecrireUneCopie`).
 *
 * **L'arrêt.** Une purge, de cet onglet ou d'un autre, l'interrompt
 * (`surUnePurge`), et `seDeconnecter` l'interrompt avant toute autre chose
 * (`interrompreLePrechauffage`), par un `AbortController`. Ce qui revient
 * après une purge ne s'écrit pas : l'écriture a noté la génération du magasin
 * à sa décision.
 *
 * Il ne lit jamais une copie : l'inventaire du magasin ne donne que les
 * chemins et les dates, en clair. Le worker n'y prend aucune part.
 */

/** Les GET d'un préchauffage, au plus, props différées comprises (décision 7). */
export const GET_AU_PLUS = 8

/** L'intervalle au-dessous duquel un préchauffage ne repart pas, sous la même clé de session. */
export const INTERVALLE_DU_PRECHAUFFAGE_MS = 30 * 60 * 1000

/** L'âge au-delà duquel une copie du noyau se redemande. */
export const AGE_D_UNE_COPIE_A_REFAIRE_MS = 2 * 60 * 60 * 1000

/** Le délai d'une demande du préchauffage : au-delà, il s'arrête. */
export const DELAI_D_UNE_DEMANDE_MS = 15_000

export { CLEF_DU_DERNIER_PRECHAUFFAGE } from '@/Utils/dernierPrechauffage'

/** Les composants dont le préchauffage demande les props différées, en partiel : l'accueil seul. */
const PROPS_DIFFEREES_DEMANDEES = new Set(['Dashboard'])

/**
 * Le noyau que le préchauffage tient à jour, dans l'ordre où il le demande :
 * la séance en cours d'abord, puis les pages gardées à adresse fixe, dans
 * l'ordre de la liste blanche. Les séances closes ne se gardent que visitées.
 *
 * @param {object} page la page qui dit la séance en cours
 * @returns {string[]} les clés des copies
 */
export const noyauDuPrechauffage = (page) => {
    const fixes = PAGES_GARDEES.filter((gardee) => !gardee.chemin.includes('{')).map((gardee) => gardee.chemin)
    const seance = cleDeLaSeanceEnCours(page)

    return seance === null ? fixes : [seance, ...fixes]
}

/**
 * Les props différées qu'une page annonce et que le préchauffage demande.
 *
 * @param {object} page
 * @returns {string[]}
 */
const propsDiffereesDemandees = (page) =>
    PROPS_DIFFEREES_DEMANDEES.has(page?.component)
        ? [...new Set(Object.values(page.deferredProps ?? {}).flat())].filter((nom) => typeof nom === 'string')
        : []

/** Les préchauffages installés et pas encore désinstallés : un par document. */
const prechauffagesInstalles = []

/**
 * Interrompt le préchauffage en cours, s'il y en a un : ce que `seDeconnecter`
 * fait avant toute autre chose.
 */
export const interrompreLePrechauffage = () => {
    prechauffagesInstalles.forEach((prechauffage) => prechauffage.interrompre())
}

/**
 * Branche le préchauffage, une fois par document, après l'écriture des
 * copies. Rend de quoi le débrancher, l'interrompre et attendre la fin du
 * préchauffage en cours (les tests).
 *
 * @param {{
 *   routeur: { on: (evenement: string, rappel: (evenement: CustomEvent) => void) => () => void },
 *   ecriture?: ReturnType<typeof ecritureDesCopiesInstallee>,
 *   magasin?: ReturnType<typeof magasinDesCopies>,
 *   fenetre?: Window,
 *   pageInitiale?: object | null,
 *   demander?: typeof fetch,
 *   horloge?: () => number,
 * }} options
 * @returns {{ desinstaller: () => void, interrompre: () => void, fini: () => Promise<void> }}
 */
export const installerLePrechauffage = ({
    routeur,
    ecriture = ecritureDesCopiesInstallee(),
    magasin = magasinDesCopies(),
    fenetre = window,
    pageInitiale = pageInitialeDuDocument(fenetre.document),
    demander = (...arguments_) => fenetre.fetch(...arguments_),
    horloge = () => Date.now(),
}) => {
    /** @type {{ controleur: AbortController } | null} */
    let enCours = null
    /** La fin du dernier préchauffage parti, interrompu ou non. */
    let derniereFin = Promise.resolve()
    let branche = true

    /** @returns {Storage | null} */
    const stockage = () => {
        try {
            return fenetre.localStorage ?? null
        } catch {
            return null
        }
    }

    /** @param {string} empreinte */
    const dernierPrechauffage = (empreinte) => lireLeDernierPrechauffage(stockage(), empreinte)

    /**
     * @param {string} empreinte
     * @param {number} instant
     */
    const noterLePrechauffage = (empreinte, instant) => noterLeDernierPrechauffage(stockage(), empreinte, instant)

    /**
     * Si une page peut faire partir un préchauffage : une page d'un compte,
     * dans un historique chiffré (rien ne se garde ailleurs), qui n'est pas
     * une copie, hors des parcours navigateur, la page visible.
     *
     * @param {object | null | undefined} page
     */
    const peutPartir = (page) =>
        branche &&
        page !== null &&
        typeof page === 'object' &&
        page.encryptHistory === true &&
        page.props?.horsLigne === undefined &&
        page.props?.is_testing !== true &&
        (page.props?.auth?.user?.id ?? null) !== null &&
        fenetre.document?.visibilityState === 'visible'

    /**
     * Les copies du noyau à demander : celles qui manquent, celles de plus de
     * deux heures, et une copie de séance plus ancienne que le dernier rejeu.
     * Jamais la page qui vient d'arriver.
     *
     * @param {object} page
     * @param {Awaited<ReturnType<ReturnType<typeof magasinDesCopies>['inventaire']>>} inventaire
     * @param {number} maintenant
     */
    const aDemander = (page, inventaire, maintenant) => {
        const copies = new Map(inventaire.copies.map((copie) => [copie.chemin, copie]))
        const ici = cleDeLaCopie(page.url)

        return noyauDuPrechauffage(page).filter((chemin) => {
            const copie = copies.get(chemin)

            if (chemin === ici) {
                return false
            }

            if (copie === undefined || maintenant - copie.copieDu > AGE_D_UNE_COPIE_A_REFAIRE_MS) {
                return true
            }

            return (
                copie.composant === COMPOSANT_DE_LA_SEANCE &&
                inventaire.dernierRejeu !== null &&
                copie.ecriteLe < inventaire.dernierRejeu
            )
        })
    }

    /**
     * Une demande, comme Inertia la ferait, sous son délai et sous le signal
     * du préchauffage.
     *
     * @param {string} chemin
     * @param {{ version: unknown, signal: AbortSignal, partielle?: { composant: string, props: string[] } }} demande
     * @returns {Promise<{ issue: 'page', page: object } | { issue: 'ignoree' | 'arret' | 'renvoyee' }>}
     */
    const demanderLaPage = async (chemin, { version, signal, partielle }) => {
        const controleur = new AbortController()
        const arreter = () => controleur.abort()
        const delai = setTimeout(arreter, DELAI_D_UNE_DEMANDE_MS)

        signal.addEventListener('abort', arreter, { once: true })

        try {
            const reponse = await demander(chemin, {
                method: 'GET',
                headers: {
                    Accept: 'text/html, application/xhtml+xml',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Inertia': 'true',
                    ...(typeof version === 'string' && version !== '' ? { 'X-Inertia-Version': version } : {}),
                    ...(partielle === undefined
                        ? {}
                        : {
                              'X-Inertia-Partial-Component': partielle.composant,
                              'X-Inertia-Partial-Data': partielle.props.join(','),
                          }),
                },
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'manual',
                signal: controleur.signal,
            })

            if (reponse.type === 'opaqueredirect' || reponse.status === 401) {
                return { issue: 'renvoyee' }
            }

            if (reponse.status === 409) {
                return { issue: 'arret' }
            }

            if (!reponse.ok || reponse.headers?.get?.('x-inertia') !== 'true') {
                return { issue: 'ignoree' }
            }

            const page = await reponse.json()
            const composant = partielle?.composant ?? page?.component

            if (
                page === null ||
                typeof page !== 'object' ||
                page.component !== composant ||
                cleDeLaCopie(page.url) !== chemin ||
                pageGardee(chemin, page.component) === null
            ) {
                return { issue: 'ignoree' }
            }

            return { issue: 'page', page }
        } catch {
            return { issue: 'arret' }
        } finally {
            clearTimeout(delai)
            signal.removeEventListener('abort', arreter)
        }
    }

    /**
     * Un préchauffage, d'après la page qui l'a fait partir.
     *
     * @param {object} page
     * @param {AbortSignal} signal
     */
    const prechauffer = async (page, signal) => {
        // La copie de la page qui arrive d'abord : elle ouvre le magasin, et ne se redemande pas.
        await ecriture.ecrituresFinies()

        const inventaire = signal.aborted ? null : await magasin.inventaire(page)

        if (inventaire === null || !inventaire.possible || signal.aborted) {
            return
        }

        const maintenant = horloge()
        const dernier = dernierPrechauffage(inventaire.empreinte)

        if (dernier !== null && maintenant - dernier < INTERVALLE_DU_PRECHAUFFAGE_MS) {
            return
        }

        const chemins = aDemander(page, inventaire, maintenant)

        if (chemins.length === 0) {
            return
        }

        noterLePrechauffage(inventaire.empreinte, maintenant)

        let restantes = GET_AU_PLUS

        /**
         * Une redirection ou un 401 : la fin de la session, si le témoin a
         * disparu ou porte une autre clé que celle de l'inventaire. Les copies
         * s'oublient alors, cette clé-là fermée, et jamais celle d'un nouveau
         * témoin, qu'une connexion dans un autre onglet a pu poser.
         *
         * @returns {Promise<boolean>} vrai quand la session a pris fin
         */
        const laSessionAPrisFin = async () => {
            if ((await magasin.empreinteDuTemoin()) === inventaire.empreinte) {
                return false
            }

            magasin.oublierLesCopies({ fermerLEtat: false, fermerAussi: inventaire.empreinte })

            return true
        }

        /**
         * @param {Awaited<ReturnType<typeof demanderLaPage>>} reponse
         * @returns {Promise<boolean>} vrai quand le préchauffage doit s'arrêter
         */
        const doitSArreter = async (reponse) =>
            reponse.issue === 'arret' || signal.aborted || (reponse.issue === 'renvoyee' && (await laSessionAPrisFin()))

        for (const chemin of chemins) {
            if (restantes === 0 || signal.aborted) {
                return
            }

            restantes -= 1

            const complete = await demanderLaPage(chemin, { version: page.version, signal })

            if (await doitSArreter(complete)) {
                return
            }

            if (complete.issue !== 'page') {
                continue
            }

            ecriture.garder(complete.page)

            const differees = propsDiffereesDemandees(complete.page)

            if (differees.length === 0 || restantes === 0) {
                continue
            }

            restantes -= 1

            const partielle = await demanderLaPage(chemin, {
                version: page.version,
                signal,
                partielle: { composant: complete.page.component, props: differees },
            })

            if (await doitSArreter(partielle)) {
                return
            }

            if (partielle.issue === 'page') {
                ecriture.completer(
                    { ...complete.page, props: { ...partielle.page.props, auth: complete.page.props?.auth } },
                    differees,
                )
            }
        }
    }

    /**
     * Fait partir un préchauffage d'après une page, sauf s'il y en a déjà un.
     *
     * @param {object | null | undefined} page
     */
    const partir = (page) => {
        if (enCours !== null || ecriture === null || !peutPartir(page)) {
            return
        }

        const controleur = new AbortController()

        enCours = { controleur }
        derniereFin = prechauffer(page, controleur.signal)
            .catch(() => {
                // Le préchauffage ne lève jamais vers la page : il recommencera après une autre réponse.
            })
            .finally(() => {
                if (enCours?.controleur === controleur) {
                    enCours = null
                }
            })
    }

    const interrompre = () => {
        enCours?.controleur.abort()
        enCours = null
    }

    /** @param {CustomEvent} evenement */
    const surUneReponse = (evenement) => partir(evenement.detail?.page)

    const arrets = [routeur.on('success', surUneReponse), magasin.surUnePurge(interrompre)]

    if (pageInitiale !== null && pageInitiale !== undefined && documentVenuDuReseau(fenetre)) {
        partir(pageInitiale)
    }

    const installe = {
        desinstaller: () => {
            branche = false
            interrompre()
            arrets.forEach((arreter) => (typeof arreter === 'function' ? arreter() : undefined))

            if (prechauffagesInstalles.includes(installe)) {
                prechauffagesInstalles.splice(prechauffagesInstalles.indexOf(installe), 1)
            }
        },
        interrompre,
        fini: () => derniereFin,
    }

    prechauffagesInstalles.push(installe)

    return installe
}
