import { http } from '@/Utils/http'
import {
    DUREE_EN_FILE_AU_PLUS_MS,
    noterLesEcrituresAbandonnees,
    oublierLesAvisPerimes,
    oublierLesEcrituresAbandonnees,
} from '@/Utils/ecrituresAbandonnees'
import { noterLesEcrituresEffacees } from '@/Utils/ecrituresEffacees'
import { noterUneEcritureAcceptee } from '@/Utils/ecrituresAcceptees'
import { estUneEcritureDeLaSeance } from '@/Utils/seanceEtFile'
import { classifySyncError, estPourUnAutreCompte, SYNC_AUTH, SYNC_OFFLINE, SYNC_TRANSIENT } from '@/Utils/syncErrors'

const QUEUE_KEY = 'offline_sync_queue'
const FAILED_KEY = 'offline_sync_failed'

/**
 * La clé par laquelle un onglet dit aux autres qu'un compte a quitté
 * l'appareil (#2050) : déconnecté après avoir accepté de perdre ses écritures,
 * ou supprimé. Écrite puis retirée aussitôt, elle ne sert qu'à l'évènement
 * `storage` que les autres onglets reçoivent ; elle ne reste jamais.
 */
const CLEF_DU_COMPTE_OUBLIE = 'gym-tracker:compte-oublie-de-la-file'

/**
 * Le rejet d'une écriture que le réseau a refusée et que la file ne peut pas
 * garder : son compte a quitté l'appareil depuis son départ (`oublis`), ou
 * aucun compte n'est connecté (#2050).
 *
 * Ni `isOffline`, ni `code`, ni `request`. L'échec réseau, rejeté tel quel, se
 * lisait « en file » (`classifySyncError` le classe `offline`) : la saisie
 * d'une série effaçait son brouillon et gardait à l'écran une valeur que plus
 * rien ne gardait, dans l'onglet resté sur la séance quand la personne se
 * déconnecte dans un autre. L'appelant la lit comme un échec : il rétablit la
 * valeur, et le dit.
 *
 * @param {string} message
 * @returns {{nonGardee: true, message: string}}
 */
const nonGardee = (message) => ({ nonGardee: true, message })

/** How many refused mutations are worth keeping. See recordFailure. */
const MAX_FAILED = 50

/**
 * Combien de portes fermées (401, 419) une même écriture peut rencontrer avant
 * d'être classée refusée plutôt que de bloquer la file pour toujours.
 */
const MAX_AUTH_ATTEMPTS = 3

/**
 * Combien d'échecs passagers (5xx, 429) une même écriture peut rencontrer,
 * espacés, avant d'être classée refusée (#1963). Avec les attentes ci-dessous,
 * la sixième tentative tombe deux minutes et demie après la première : de quoi
 * couvrir le redémarrage du serveur pendant une mise en production, sans
 * bloquer la file pour toujours derrière une écriture qu'il refusera toujours.
 */
const MAX_TRANSIENT_ATTEMPTS = 6

/** L'attente après le premier échec passager ; elle double à chaque échec suivant. */
const PREMIERE_ATTENTE_MS = 5000

/** Le plafond de cette attente, `Retry-After` compris. */
const ATTENTE_MAX_MS = 5 * 60 * 1000

/**
 * Ce qu'une écriture directe attend, au plus, avant son unique nouvel essai sur
 * un 429. L'écran attend sa réponse : un `Retry-After` d'une minute ne doit pas
 * le figer une minute. Au-delà, l'échec revient à l'appelant, comme avant que
 * l'en-tête soit lisible (#1963).
 */
const ATTENTE_EN_LIGNE_MAX_MS = 5000

/**
 * Ce qu'une requête du vidage attend sa réponse, au plus, quand l'écriture n'a
 * pas donné son propre délai. `fetch` n'en a aucun : une seule requête restée
 * sans réponse figeait le vidage, et avec lui toute écriture directe, qui
 * attend la file avant de partir. Passé ce délai, l'écriture reste en tête,
 * comme sans réseau, et repart au déclencheur suivant.
 */
export const DELAI_DE_REJEU_MS = 15000

const MUTATIONS = ['post', 'patch', 'put', 'delete']

/**
 * L'en-tête qui dit au serveur pour quel compte une écriture rejouée a été
 * faite. Le serveur refuse, sans l'exécuter, celle dont le compte n'est pas
 * celui de la session (#1964, `VerifieLeCompteDeLEcriture`).
 */
const ENTETE_DU_COMPTE = 'X-Compte-De-L-Ecriture'

/**
 * Ce que l'adresse d'annulation d'une création porte à la place de
 * l'identifiant qu'elle produira, et que le vidage remplace (#1960).
 */
const ID_A_VENIR = '__produit__'

/** Une création, la seule écriture que rejouer peut doubler et qu'il faut savoir annuler. */
const estUneCreation = (config) => String(config?.method ?? '').toLowerCase() === 'post'

/** Une valeur de charge qui se compare telle quelle : ni objet, ni liste. */
const estScalaire = (valeur) => valeur === null || typeof valeur !== 'object'

/**
 * Deux valeurs d'un champ qui disent la même chose : `80`, `80.0` et `'80'`
 * sont un même poids, et le serveur rend des nombres là où une saisie peut
 * tenir du texte.
 */
const memeValeur = (a, b) =>
    a === b ||
    (a !== null && b !== null && a !== undefined && b !== undefined && a !== '' && b !== '' && Number(a) === Number(b))

/**
 * Ce que le serveur garde autrement qu'on le lui a envoyé : les champs de la
 * charge qu'il rend avec une autre valeur, et la valeur envoyée.
 *
 * @param {Object|null|undefined} envoye la charge partie
 * @param {Object|null|undefined} garde la ressource que le serveur rend
 * @returns {Object}
 */
const ecartAvec = (envoye, garde) =>
    garde === null || typeof garde !== 'object'
        ? {}
        : Object.fromEntries(
              Object.entries(envoye ?? {}).filter(
                  ([champ, valeur]) =>
                      estScalaire(valeur) && Object.hasOwn(garde, champ) && !memeValeur(valeur, garde[champ]),
              ),
          )

/**
 * Un stockage corrompu — une écriture coupée par une suspension iOS, un quota
 * atteint à mi-chemin — faisait lever JSON.parse au chargement du module, et
 * c'est toute la page qui ne démarrait plus. Une liste illisible vaut une
 * liste vide : on perd au pire ce qui était déjà perdu.
 */
const lireLaListe = (cle) => {
    try {
        const valeur = JSON.parse(localStorage.getItem(cle) || '[]')

        return Array.isArray(valeur) ? valeur : []
    } catch {
        return []
    }
}

/**
 * Les identifiants des entrées que le stockage garde encore, file et refus
 * ensemble, ou null quand il ne se lit pas.
 *
 * @returns {Set<string>|null}
 */
const identifiantsStockes = () => {
    try {
        const listes = [QUEUE_KEY, FAILED_KEY].map((cle) => JSON.parse(localStorage.getItem(cle) || '[]'))

        return new Set(listes.flatMap((liste) => (Array.isArray(liste) ? liste.map((entree) => entree?.id) : [])))
    } catch {
        return null
    }
}

/**
 * L'identifiant d'un compte tel que la file le note : une chaîne, ou null quand
 * personne n'est connecté. `42` et `'42'` désignent le même compte.
 */
const normaliserLeCompte = (id) => (id === undefined || id === null || id === '' ? null : String(id))

/**
 * Réécrit une liste du stockage sans les entrées d'un compte. La liste du
 * stockage, et non la copie en mémoire : un autre onglet a pu y mettre des
 * écritures de ce compte que cet onglet n'a jamais vues (#2050).
 *
 * @param {string} cle
 * @param {string} compte
 */
const oublierDansLeStockage = (cle, compte) => {
    const restantes = lireLaListe(cle).filter((entree) => normaliserLeCompte(entree?.compte) !== compte)

    try {
        if (restantes.length === 0) {
            localStorage.removeItem(cle)

            return
        }

        localStorage.setItem(cle, JSON.stringify(restantes))
    } catch {
        // Stockage refusé : la mémoire est déjà oubliée, la prochaine écriture de la file réessaie.
    }
}

/**
 * Depuis quand une entrée attend, d'après le moment où elle a été faite.
 *
 * @returns {number} en millisecondes, NaN quand elle ne le dit pas
 */
const ageDe = (entree, maintenant) => maintenant - Date.parse(entree?.timestamp)

/**
 * Une entrée qui attend sur l'appareil depuis plus de trente jours (#2050) :
 * aucun envoi ne l'a fait aboutir, et elle est abandonnée.
 */
const estPerimee = (entree, maintenant) => ageDe(entree, maintenant) > DUREE_EN_FILE_AU_PLUS_MS

/**
 * Le compte connecté d'après la page que le serveur a rendue, lue dans le même
 * élément qu'Inertia au démarrage. Le module se charge avant que la première
 * navigation ne soit annoncée : sans cette lecture, le vidage du chargement ne
 * saurait pas pour qui il travaille.
 */
const lireLeCompteDeLaPage = () => {
    try {
        const page = JSON.parse(document.querySelector('script[data-page][type="application/json"]')?.textContent ?? '')

        return normaliserLeCompte(page?.props?.auth?.user?.id)
    } catch {
        return null
    }
}

/**
 * Une entrée écrite avant que la file ne note son compte. On ne sait pas à qui
 * elle appartient, donc pour qui l'envoyer : elle n'est jamais rejouée (#1964).
 */
const aUnCompte = (entree) => normaliserLeCompte(entree?.compte) !== null

/**
 * La file d'une écriture dont dépend cette valeur de charge, quand c'en est une.
 *
 * `{ enAttenteDe: queueId }` désigne ce que produira une autre écriture encore
 * en file : la série d'un exercice dont la création attend le réseau (#1962).
 *
 * @returns {string|null}
 */
const fileReferencee = (valeur) =>
    valeur !== null && typeof valeur === 'object' && typeof valeur.enAttenteDe === 'string' ? valeur.enAttenteDe : null

/** Les écritures dont dépend une charge, d'après ses champs de premier niveau. */
const filesReferencees = (data) =>
    data !== null && typeof data === 'object'
        ? Object.values(data)
              .map(fileReferencee)
              .filter((id) => id !== null)
        : []

/**
 * Ce que le serveur demande d'attendre, d'après `Retry-After` : un nombre de
 * secondes ou une date HTTP. Null quand il ne dit rien d'exploitable.
 *
 * @returns {number|null} en millisecondes
 */
const attenteDemandee = (error) => {
    const valeur = error?.response?.headers?.['retry-after']

    if (valeur === undefined || valeur === null || String(valeur).trim() === '') {
        return null
    }

    const secondes = Number(valeur)

    if (Number.isFinite(secondes)) {
        return Math.max(0, secondes * 1000)
    }

    const date = Date.parse(valeur)

    return Number.isNaN(date) ? null : Math.max(0, date - Date.now())
}

/**
 * L'attente avant le prochain essai d'une écriture qui vient d'échouer pour la
 * n-ième fois sur une erreur passagère : celle que le serveur demande pour un
 * 429, sinon une attente qui double à chaque échec.
 */
const attenteAvantLeProchainEssai = (error, tentatives) => {
    const demandee = error?.response?.status === 429 ? attenteDemandee(error) : null
    const attente = demandee ?? PREMIERE_ATTENTE_MS * 2 ** (tentatives - 1)

    return Math.min(attente, ATTENTE_MAX_MS)
}

/**
 * Names one create attempt, for as long as that attempt exists.
 *
 * The dangerous failure is not the one that obviously fails. It is the request
 * the server accepted and wrote, whose response never came home — a tunnel, a
 * lock screen, a suspended PWA. The client sees a network error, queues the
 * write, and replays it later against a row that already exists. Nothing in the
 * queue could tell the difference, so the replay made a second one.
 */
const newIdempotencyKey = () =>
    globalThis.crypto?.randomUUID?.() ??
    `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}-${Math.random().toString(36).slice(2, 12)}`

class SyncService {
    constructor() {
        /**
         * Le compte connecté : la file appartient au compte, pas à l'appareil.
         *
         * Une entrée ne disait pas qui l'avait écrite, et le vidage partait
         * sous la session connectée au moment où il avait lieu. Sur un appareil
         * partagé, les préférences d'un compte s'appliquaient au suivant, et ses
         * séries revenaient en 403 annoncées au mauvais compte (#1964). Chaque
         * entrée porte désormais son compte, et seules celles du compte
         * connecté partent.
         */
        this.compte = lireLeCompteDeLaPage()

        const file = lireLaListe(QUEUE_KEY)
        const refusees = lireLaListe(FAILED_KEY)

        this.queue = file.filter(aUnCompte)
        this.failed = refusees.filter(aUnCompte)

        /*
         * Une entrée qui ne dit pas quand elle a été faite (un stockage abîmé)
         * prend la date de ce chargement : la durée de trente jours compte
         * alors à partir d'aujourd'hui, au lieu de ne jamais la borner ou de
         * l'abandonner sans qu'elle ait attendu (#2050).
         */
        const fileDatee = this.daterLesEntreesSansDate(this.queue)
        const refusDates = this.daterLesEntreesSansDate(this.failed)

        if (this.queue.length !== file.length || fileDatee) {
            this.saveQueue()
        }

        if (this.failed.length !== refusees.length || refusDates) {
            this.saveFailed()
        }

        /*
         * Effacées sans être envoyées : l'écran le dit, une fois, et seulement
         * combien (`Utils/ecrituresEffacees`). Elles disparaissaient sans un
         * mot, alors que la version précédente les aurait rejouées.
         */
        noterLesEcrituresEffacees(file.length - this.queue.length + (refusees.length - this.failed.length))

        /** La relance programmée après un échec passager, s'il y en a une. */
        this.relance = null

        /** L'entrée que le vidage est en train d'envoyer, s'il y en a une. */
        this.enVol = null

        /**
         * Ce que chaque écriture rejouée a produit, pour la durée de la page :
         * une écriture qui en dépend et n'est mise en file qu'après son rejeu
         * y trouve l'identifiant réel.
         *
         * @type {Map<string, number|string>}
         */
        this.produits = new Map()

        /**
         * Les écritures directes parties sans avoir encore de réponse. Celles
         * que le vidage déclenche lui-même (l'adoption d'une série rejouée)
         * partent hors de la file : « Terminer » doit les attendre aussi, ou
         * la clôture les double et elles reviennent refusées (#1961).
         *
         * @type {Set<Promise>}
         */
        this.ecrituresDirectes = new Set()

        /**
         * Combien de fois chaque compte a quitté l'appareil depuis le
         * chargement de la page : déconnecté après avoir accepté de perdre ses
         * écritures, ou supprimé, dans cet onglet ou dans un autre (#2050).
         *
         * Une écriture partie avant ce départ et revenue après, sans réseau ou
         * refusée, était remise en file ou parmi les refus au nom du compte
         * parti, en clair, après que la déconnexion avait tout effacé. Elle
         * note ce nombre à son départ ; s'il a changé à sa réponse, rien d'elle
         * ne revient sur l'appareil. Un nombre, et non une marque sur le
         * compte : le même compte peut se reconnecter sur la même page, sans
         * rechargement, et ses écritures suivantes doivent pouvoir attendre en
         * file comme avant.
         *
         * @type {Map<string, number>}
         */
        this.oublis = new Map()

        /**
         * Combien d'écritures le vidage a fait aboutir depuis la dernière
         * réponse du serveur à une visite Inertia : ce que les props de la page
         * affichée ne montrent peut-être pas encore. Au chargement, la page est
         * rendue AVANT que le vidage parte ; ce qu'il crée n'y figure pas
         * (`useRechargementApresLeVidage`, #1960, #1962).
         */
        this.rejeuxDepuisLaDerniereVisite = 0

        /**
         * La séance que montre la page de séance, ou la dernière qu'elle a
         * montrée (`suivreLesEcrituresDeLaSeance`, #2048). Une écriture de la
         * séance la prend à son départ : celle qui part au démontage de la
         * page, une saisie vidée en partant, revient souvent après lui.
         *
         * @type {string|null}
         */
        this.seanceDesEcritures = null

        /*
         * Ce qui attend depuis plus de trente jours ne partira plus : abandonné
         * dès le chargement, avant que la page ne superpose la file à la
         * séance (#2050).
         */
        this.abandonnerLesEntreesPerimees()

        window.addEventListener('online', () => this.processQueue())

        /**
         * Un autre onglet a vu ce compte quitter l'appareil : déconnecté après
         * avoir accepté de perdre ses écritures, ou supprimé (#2050). Cet
         * onglet garde sa propre copie de la file en mémoire, qu'il réécrirait
         * au stockage à sa prochaine écriture : il l'oublie aussi. La session
         * du compte n'existe plus, les cookies étant communs aux onglets :
         * l'onglet ne le croit plus connecté, et rien n'entre plus en file à
         * son nom ; une écriture qu'il ne peut ni envoyer ni garder échoue
         * (`nonGardee`). Quand c'était le compte de cet onglet,
         * `sync:compte-parti` le dit à l'écran.
         */
        window.addEventListener('storage', (evenement) => {
            if (evenement.key !== CLEF_DU_COMPTE_OUBLIE || evenement.newValue === null) {
                return
            }

            let compte

            try {
                compte = normaliserLeCompte(JSON.parse(evenement.newValue)?.compte)
            } catch {
                // Un avis illisible ne vient pas de l'application.
                return
            }

            const etaitConnecte = compte !== null && this.compte === compte

            this.oublierEnMemoire(compte, { deconnecte: true })

            if (etaitConnecte) {
                window.dispatchEvent(new CustomEvent('sync:compte-parti', { detail: { compte } }))
            }
        })

        /**
         * Chaque réponse du serveur à une visite Inertia dit qui est connecté.
         * C'est aussi une preuve que le réseau répond : la file du compte part,
         * s'il en a une.
         *
         * Une réponse, pas une navigation. `inertia:navigate` est aussi émis
         * quand le bouton Retour restaure une page depuis l'historique, avec
         * les props qu'elle avait à son affichage : celles du compte qui s'est
         * déconnecté depuis. Le service le croyait alors revenu, et vidait sa
         * file sous la session du compte suivant (#1964). Le serveur refuse
         * désormais ces écritures de toute façon ; le service ne les tente plus.
         */
        document.addEventListener('inertia:success', (event) => {
            this.rejeuxDepuisLaDerniereVisite = 0
            this.definirLeCompte(event.detail?.page?.props?.auth?.user?.id)
        })

        /**
         * An installed PWA is suspended and resumed, not closed. A queue built
         * up while the connection was down would otherwise wait for an `online`
         * event that never fires, because the browser was never running to
         * notice the transition.
         */
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.processQueue()
            }
        })

        this.processQueue()
    }

    /**
     * Perform an API request, queuing it only if the attempt actually fails.
     *
     * This used to short-circuit on `navigator.onLine`, read ONCE in the
     * constructor of this module-level singleton. When that snapshot was false
     * — which an iOS PWA reports routinely at a cold launch, before the network
     * stack is attached — every mutation for the whole page session was pushed
     * onto the queue and rejected with `isOffline`, and not one byte was sent.
     * Callers treat `isOffline` as "keep the value on screen", so the user
     * watched their workout fill up normally and reloaded into an empty
     * session. Observed on a simulator: the FAB's Inertia POST created workout
     * 12, then adding an exercise and a set produced no server request at all.
     *
     * `navigator.onLine` cannot carry this decision. False negatives strand the
     * user, and a true value never did prove reachability. So we always attempt
     * the request; the catch below queues it when the network genuinely refuses.
     *
     * Une écriture est suivie jusqu'à sa réponse, dès son départ et de façon
     * synchrone : `attendreLesEcritures` la voit, même lancée par un écouteur
     * du vidage qu'il attend.
     *
     * @param {Object} config La requête, au format de `Utils/http`. Une
     *   création peut y joindre `ajusterPar` et `contexte`, voir `notesDeCreation`.
     * @returns {Promise}
     */
    request(config) {
        const depart = { compte: this.compte, seance: this.seanceDesEcritures, oublis: this.oublisDe(this.compte) }
        const ecriture = this.envoyer(config)

        if (this.isMutation(config)) {
            const oublier = () => this.ecrituresDirectes.delete(ecriture)

            this.ecrituresDirectes.add(ecriture)
            ecriture.then(oublier, oublier)
            ecriture.then(
                () => this.noterLAcceptation(config, depart),
                () => {},
            )
        }

        return ecriture
    }

    /**
     * La page de séance dit quelle séance elle montre (#2048) : ses écritures
     * acceptées se notent sous elle (`noterLAcceptation`). Rien ne l'efface au
     * départ de la page, puisque l'écriture qui part au démontage, une saisie
     * vidée en partant, revient souvent après lui ; la page de séance suivante
     * la remplace, et seules les sept écritures de la séance se notent.
     *
     * @param {number|string|null|undefined} seance
     */
    suivreLesEcrituresDeLaSeance(seance) {
        this.seanceDesEcritures = seance === null || seance === undefined || seance === '' ? null : String(seance)
    }

    /**
     * Note aussitôt, pour la copie hors ligne de la séance, qu'une écriture
     * directe de la séance a été acceptée (`Utils/ecrituresAcceptees.js`,
     * #2048) : la copie, prise plus tard quand tout est enregistré, peut ne
     * pas l'avoir. Le compte et la séance sont ceux du départ. Une écriture
     * mise en file, refusée ou abandonnée ne se note pas ; rejouée par le
     * vidage, elle s'annonce par `sync:replayed`. Une écriture dont le compte
     * a quitté l'appareil depuis son départ (`oublis`, #2050) ne se note pas
     * non plus : sa réponse tardive, après « Se déconnecter quand même » ou
     * la suppression du compte, recréait en clair la note que la purge et
     * l'écran de connexion venaient d'effacer.
     *
     * @param {Object} config la requête acceptée
     * @param {{compte: string|null, seance: string|null, oublis: number}} depart
     */
    noterLAcceptation(config, { compte, seance, oublis }) {
        if (
            compte === null ||
            seance === null ||
            this.oublisDe(compte) !== oublis ||
            !estUneEcritureDeLaSeance(config)
        ) {
            return
        }

        noterUneEcritureAcceptee({ compte, seance, instant: Date.now() })
    }

    /**
     * Envoie la requête, après la file s'il le faut, et la met en file si le
     * réseau la refuse. Voir `request`.
     *
     * @param {Object} config
     * @returns {Promise}
     */
    async envoyer(config) {
        const { ajusterPar, contexte, ...requete } = config
        const stamped = this.stampIdempotency(requete)
        const notes = this.notesDeCreation(stamped, { ajusterPar, contexte })

        /*
         * Le compte qui fait l'écriture, pris à son départ : une écriture
         * partie juste avant que la session se ferme, et tombée sur un réseau
         * absent juste après, reste à celui qui l'a faite.
         */
        const compte = this.compte
        const oublis = this.oublisDe(compte)

        /*
         * Sauf si ce compte a quitté l'appareil depuis son départ (#2050) :
         * la personne a accepté de perdre ce qui n'était pas encore
         * enregistré, et la déconnexion l'a effacé. L'écriture ne part plus et
         * n'entre pas en file ; elle échoue (`nonGardee`).
         */
        const compteParti = () => this.oublisDe(compte) !== oublis

        /*
         * Une écriture directe ne doit pas doubler celles qui attendent : la
         * file rejouait une modification plus ancienne APRÈS celle que
         * l'utilisateur venait de faire en ligne, et l'ancienne valeur écrasait
         * la nouvelle. On vide donc d'abord ; si la file ne se vide pas
         * (toujours hors ligne, ou session à renouveler), la nouvelle écriture
         * prend sa place derrière, dans l'ordre où elle a été faite.
         */
        if (this.isMutation(stamped) && this.enAttente() > 0) {
            await this.processQueue()

            if (compteParti()) {
                return Promise.reject({
                    ...nonGardee('Request dropped: its account left the device'),
                    compteParti: true,
                })
            }

            if (this.enAttente() > 0) {
                const queueId = this.addToQueue(stamped, compte, notes)

                return Promise.reject({ isOffline: true, queueId, message: 'Network error: Request queued' })
            }
        }

        try {
            return await http(stamped)
        } catch (error) {
            // Auto-retry once on 429 Too Many Requests (rate limiting)
            if (error.response?.status === 429) {
                const attente = Math.min(attenteDemandee(error) ?? 2000, ATTENTE_EN_LIGNE_MAX_MS)
                await new Promise((resolve) => setTimeout(resolve, attente))

                try {
                    /**
                     * The stamped config. Retrying with the original dropped the
                     * idempotency key on the one attempt most likely to need it:
                     * a 429 means the server was busy, not that it refused, and
                     * the first attempt may well have been written.
                     */
                    return await http(stamped)
                } catch (retryError) {
                    return this.queueOrThrow(retryError, stamped, compteParti() ? null : compte, notes)
                }
            }

            return this.queueOrThrow(error, stamped, compteParti() ? null : compte, notes)
        }
    }

    /**
     * Ce que la file retient d'une création pour la rattraper au vidage.
     *
     * Une création partie sans réponse a pu atteindre le serveur. Au rejeu, il
     * reconnaît sa clé d'idempotence, rend ce qu'il avait enregistré et ignore
     * la charge rejouée, saisies et coches fondues pendant l'attente comprises.
     * `ajusterPar` donne l'adresse qui modifie ce que la création aura produit,
     * d'après son identifiant : le vidage y renvoie ce que le serveur a ignoré
     * (#1960). La file la garde sous forme d'adresse, `__produit__` à la place
     * de l'identifiant, pour qu'elle survive à un rechargement.
     *
     * `contexte` est ce que la page note pour montrer la création sans le
     * serveur, et que la charge ne dit pas : pour une série, la séance et
     * l'exercice de sa ligne (`Utils/seanceEtFile`, #2048). La file le garde sur
     * l'entrée, jamais dans la charge : il ne part pas au serveur.
     *
     * @param {Object} config la requête
     * @param {{ajusterPar?: (id: string) => string, contexte?: Object}} notes
     * @returns {{ajusterPar?: string, contexte?: Object}}
     */
    notesDeCreation(config, { ajusterPar, contexte } = {}) {
        if (!estUneCreation(config)) {
            return {}
        }

        return {
            ...(typeof ajusterPar === 'function' ? { ajusterPar: ajusterPar(ID_A_VENIR) } : {}),
            ...(contexte !== null && typeof contexte === 'object' ? { contexte: { ...contexte } } : {}),
        }
    }

    /**
     * Decides what a failed attempt means, in the one place both attempts use.
     *
     * The retry used to sit outside this: a network failure on the second
     * attempt was neither sent, nor queued, nor recorded — it fell straight out
     * of the catch. classifySyncError then read the bare rejection as "offline",
     * which the draft replay acts on by deleting the local draft as a duplicate
     * of a queued write that never existed.
     */
    queueOrThrow(error, config, compte, notes = {}) {
        // A response means the server answered, so this is its verdict, not a
        // connectivity problem — queueing it would hide a real refusal.
        if (error.code === 'ERR_NETWORK' || (!error.response && error.request)) {
            /*
             * Tentée : sans réponse, rien ne dit qu'elle n'a pas atteint le
             * serveur. Une création retirée ensuite devra être annulée là-bas,
             * pas seulement oubliée ici (#1960).
             */
            const queueId = this.addToQueue(config, compte, { ...notes, tentee: true })

            // queueId lets a caller waiting on what this create produces pick its
            // own write out of the drain later — see the `sync:replayed` event.
            if (queueId !== null) {
                return Promise.reject({ isOffline: true, queueId, message: 'Network error: Request queued' })
            }

            // Une écriture que rien ne garde : l'échec réseau brut se lirait « en file ».
            if (this.isMutation(config)) {
                return Promise.reject(nonGardee('Network error: request not kept, no account to keep it for'))
            }
        }

        throw error
    }

    /**
     * Gives a create a name the server can recognise on a second telling.
     *
     * Only POSTs need it: PATCH and DELETE against a known id are already
     * idempotent by nature. The key is minted once and carried on the config,
     * so queueing and replaying the same attempt reuses it rather than minting
     * a second identity for the same intent.
     */
    stampIdempotency(config) {
        if (String(config.method).toLowerCase() !== 'post' || config.headers?.['Idempotency-Key']) {
            return config
        }

        return { ...config, headers: { ...config.headers, 'Idempotency-Key': newIdempotencyKey() } }
    }

    isMutation(config) {
        return MUTATIONS.includes(String(config.method ?? '').toLowerCase())
    }

    /**
     * Met une écriture en file, au nom du compte qui l'a faite.
     *
     * Sans compte connu, rien n'entre : une écriture que la file ne sait
     * attribuer à personne ne pourrait jamais repartir sans risquer de partir
     * sous un autre compte. `queueOrThrow` rejette alors l'écriture par
     * `nonGardee`, que nul appelant ne prend pour « en file » : il rétablit la
     * valeur et le dit, au lieu de la garder comme si elle allait partir.
     *
     * @param {Object} config
     * @param {string|null} compte le compte connecté quand l'écriture est partie,
     *   null quand il a quitté l'appareil depuis (voir `oublis`)
     * @param {{tentee?: boolean, ajusterPar?: string, contexte?: Object}} notes ce
     *   que la file sait déjà de l'écriture : `tentee` quand elle est partie une
     *   fois sans réponse, `ajusterPar` pour rattraper ce que le serveur en
     *   ignorera, `contexte` pour que la page la montre sans le serveur.
     * @returns {string|null} the queue entry's id, so a caller that depends on
     *   what this write eventually creates can recognise it when it goes out.
     */
    addToQueue(config, compte = this.compte, notes = {}) {
        // Only queue mutations (POST, PATCH, PUT, DELETE)
        if (!this.isMutation(config) || compte === null) {
            return null
        }

        const id = this.nouvelIdentifiantDEntree()

        this.queue.push({ ...config, ...notes, id, timestamp: new Date().toISOString(), compte })
        this.saveQueue()

        return id
    }

    /**
     * Met en file, sans la tenter, une écriture qui dépend d'une autre encore en
     * file.
     *
     * La création d'une série sous un exercice lui-même ajouté hors ligne
     * attendait en mémoire que le vidage annonce l'identifiant de l'exercice :
     * un rechargement, ou la PWA arrêtée pendant que le téléphone dormait, la
     * perdait, et le vidage créait un exercice vide (#1962). Elle entre
     * désormais en file tout de suite, derrière lui, et nomme ce dont elle
     * dépend par `{ enAttenteDe: queueId }` ; le vidage le remplace par
     * l'identifiant réel au moment où cette écriture-là aboutit.
     *
     * @param {Object} config la requête, au format de `Utils/http`, avec
     *   `ajusterPar` et `contexte` pour une création (voir `notesDeCreation`)
     * @returns {string|null} l'entrée créée, ou null quand rien n'entre : pas de
     *   compte connecté, ou une écriture dont elle dépend est sortie de la file
     *   sans rien produire (refusée, retirée).
     */
    mettreEnFile(config) {
        const { ajusterPar, contexte, ...requete } = config
        let data = requete.data

        for (const [champ, valeur] of Object.entries(data ?? {})) {
            const parent = fileReferencee(valeur)

            if (parent === null) {
                continue
            }

            if (this.produits.has(parent)) {
                data = { ...data, [champ]: this.produits.get(parent) }

                continue
            }

            // Retirée, ou à annuler : elle ne produira rien dont une autre puisse dépendre.
            if (
                !this.queue.some(
                    (entree) => entree.id === parent && entree.compte === this.compte && entree.aAnnuler === undefined,
                )
            ) {
                return null
            }
        }

        return this.addToQueue(
            this.stampIdempotency({ ...requete, data }),
            this.compte,
            this.notesDeCreation(requete, { ajusterPar, contexte }),
        )
    }

    /**
     * Retient ce qu'une écriture a produit, et le substitue dans les écritures
     * en file qui l'attendaient. La file est réécrite juste après, d'un seul
     * coup avec le retrait de l'écriture rejouée.
     */
    noterLeProduit(queueId, id) {
        this.produits.set(queueId, id)

        this.queue.forEach((entree) => {
            if (!filesReferencees(entree.data).includes(queueId)) {
                return
            }

            entree.data = Object.fromEntries(
                Object.entries(entree.data).map(([champ, valeur]) => [
                    champ,
                    fileReferencee(valeur) === queueId ? id : valeur,
                ]),
            )
        })
    }

    /**
     * Dit au service qui est connecté, et vide la file de ce compte s'il en a
     * une. Appelé à chaque navigation Inertia, déconnexion et connexion
     * comprises.
     *
     * @param {number|string|null|undefined} id
     */
    definirLeCompte(id) {
        this.compte = normaliserLeCompte(id)

        if (this.enAttente() > 0) {
            this.processQueue()
        }
    }

    /**
     * Oublie tout ce que la file garde pour ce compte, sur tout l'appareil :
     * ses écritures en attente, ses refus et l'avis de ce qui a été abandonné.
     *
     * Un compte supprimé ne se reconnectera jamais. Ce qu'il laissait en file
     * ne partirait donc jamais, et resterait sur l'appareil, lisible par qui
     * s'en sert ensuite, alors que la suppression promet d'effacer ses
     * données (#1964). Un compte qui se déconnecte après avoir accepté de
     * perdre ses écritures en attente les laisse de même (#2050). Les
     * écritures des autres comptes restent à eux.
     *
     * Le stockage est relu, et non seulement la copie de cet onglet : un autre
     * onglet a pu y mettre des écritures de ce compte. Les autres onglets
     * l'apprennent par l'évènement `storage`, et oublient leur propre copie.
     * Une écriture de ce compte encore en vol, ici ou là-bas, n'y revient pas à
     * sa réponse (`oublis`).
     *
     * @param {number|string|null|undefined} id
     */
    oublierLeCompte(id) {
        const compte = normaliserLeCompte(id)

        if (compte === null) {
            return
        }

        this.oublierEnMemoire(compte)
        oublierDansLeStockage(QUEUE_KEY, compte)
        oublierDansLeStockage(FAILED_KEY, compte)
        oublierLesEcrituresAbandonnees(compte)

        try {
            localStorage.setItem(CLEF_DU_COMPTE_OUBLIE, JSON.stringify({ compte }))
            localStorage.removeItem(CLEF_DU_COMPTE_OUBLIE)
        } catch {
            // Stockage refusé : les autres onglets n'ont pas pu écrire la file non plus.
        }
    }

    /**
     * Oublie ce que cette copie de la file garde pour ce compte.
     *
     * @param {string|null} compte
     * @param {{deconnecte?: boolean}} options `deconnecte` quand un autre
     *   onglet dit que ce compte n'a plus de session sur l'appareil
     */
    oublierEnMemoire(compte, { deconnecte = false } = {}) {
        if (compte === null) {
            return
        }

        this.oublis.set(compte, this.oublisDe(compte) + 1)
        this.queue = this.queue.filter((entree) => normaliserLeCompte(entree.compte) !== compte)
        this.failed = this.failed.filter((entree) => normaliserLeCompte(entree.compte) !== compte)

        if (deconnecte && this.compte === compte) {
            this.compte = null
        }
    }

    /**
     * Combien de fois ce compte a quitté l'appareil depuis le chargement de la
     * page (voir `oublis`).
     *
     * @param {string|null} compte
     * @returns {number}
     */
    oublisDe(compte) {
        return this.oublis.get(compte) ?? 0
    }

    /** Combien d'écritures du compte connecté attendent encore d'être envoyées. */
    enAttente() {
        return this.compte === null ? 0 : this.queue.filter((entree) => entree.compte === this.compte).length
    }

    /**
     * Combien d'écritures de ce compte attendent sur l'appareil, celles qu'un
     * autre onglet a mises en file comprises : la copie de cet onglet peut ne
     * pas les connaître, et la déconnexion doit les compter avant de les
     * effacer (#2050).
     *
     * @param {number|string|null|undefined} id le compte connecté par défaut
     * @returns {number}
     */
    enAttenteSurLAppareil(id = this.compte) {
        const compte = normaliserLeCompte(id)

        if (compte === null) {
            return 0
        }

        const identifiants = new Set(
            [...lireLaListe(QUEUE_KEY), ...this.queue]
                .filter((entree) => normaliserLeCompte(entree?.compte) === compte)
                .map((entree) => entree.id),
        )

        return identifiants.size
    }

    /**
     * Donne la date de maintenant aux entrées qui n'en ont pas de lisible.
     *
     * @param {Array<Object>} entrees
     * @returns {boolean} vrai quand une entrée a été datée
     */
    daterLesEntreesSansDate(entrees) {
        const maintenant = new Date().toISOString()
        let datee = false

        entrees.forEach((entree) => {
            if (Number.isNaN(Date.parse(entree.timestamp))) {
                entree.timestamp = maintenant
                datee = true
            }
        })

        return datee
    }

    /**
     * Abandonne et efface ce qui attend sur l'appareil depuis plus de trente
     * jours, de tout compte, refus compris, et le note pour chaque compte
     * (`Utils/ecrituresAbandonnees`), que son prochain écran le lui dise
     * (#2050).
     *
     * La file gardait pour toujours, en clair, les écritures d'un compte qui
     * ne revenait pas : supprimé depuis un autre appareil ou depuis le
     * panneau, ou dont la session avait expiré sans qu'il se reconnecte.
     * Aucune réponse du serveur ne dit qu'un compte n'existe plus (le serveur
     * se garde de le dire, `VerifieLeCompteDeLEcriture`) : la durée est la
     * seule borne. Une écriture qui dépend d'une création abandonnée part
     * avec elle, puisqu'elle ne pourrait plus aboutir. L'entrée qui vole n'est
     * pas touchée : sa réponse dira ce qu'elle est devenue.
     *
     * Seul ce que le stockage garde encore est compté : un autre onglet, qui
     * garde sa propre copie, a pu abandonner les mêmes avant, ou les envoyer.
     * Les entrées du compte connecté sortent aussi de l'écran
     * (`sync:retired`), et `sync:abandoned` prévient l'écran déjà monté. Les
     * avis qui ont attendu leur compte plus de trente jours s'effacent aussi.
     *
     * @param {number} maintenant
     */
    abandonnerLesEntreesPerimees(maintenant = Date.now()) {
        oublierLesAvisPerimes(maintenant)

        const abandonnees = new Set()

        this.queue.forEach((entree) => {
            if (entree.id === this.enVol) {
                return
            }

            if (
                estPerimee(entree, maintenant) ||
                filesReferencees(entree.data).some((parent) => abandonnees.has(parent))
            ) {
                abandonnees.add(entree.id)
            }
        })

        const refusPerimes = this.failed.filter((entree) => estPerimee(entree, maintenant))

        if (abandonnees.size === 0 && refusPerimes.length === 0) {
            return
        }

        const sorties = this.queue.filter((entree) => abandonnees.has(entree.id))
        const stockees = identifiantsStockes()
        const aDire = [...sorties, ...refusPerimes].filter((entree) => stockees === null || stockees.has(entree.id))

        if (abandonnees.size > 0) {
            this.queue = this.queue.filter((entree) => !abandonnees.has(entree.id))
            this.saveQueue()
        }

        if (refusPerimes.length > 0) {
            this.failed = this.failed.filter((entree) => !refusPerimes.includes(entree))
            this.saveFailed()
        }

        /** @type {Map<string, {nombre: number, depuis: number|null}>} */
        const parCompte = new Map()

        aDire.forEach((entree) => {
            const compte = normaliserLeCompte(entree.compte)
            const faite = Date.parse(entree.timestamp)
            const avis = parCompte.get(compte) ?? { nombre: 0, depuis: null }

            parCompte.set(compte, {
                nombre: avis.nombre + 1,
                depuis: Number.isFinite(faite) && (avis.depuis === null || faite < avis.depuis) ? faite : avis.depuis,
            })
        })

        noterLesEcrituresAbandonnees(parCompte, maintenant)

        const duCompte = sorties.filter((entree) => entree.compte === this.compte).map((entree) => entree.id)

        if (duCompte.length > 0) {
            window.dispatchEvent(new CustomEvent('sync:retired', { detail: { queueIds: duCompte } }))
        }

        if (parCompte.size > 0) {
            window.dispatchEvent(new CustomEvent('sync:abandoned', { detail: { comptes: [...parCompte.keys()] } }))
        }
    }

    /** Les entrées de file du compte connecté qui attendent encore, par leur identifiant. */
    identifiantsEnAttente() {
        return this.compte === null
            ? []
            : this.queue.filter((entree) => entree.compte === this.compte).map((entree) => entree.id)
    }

    /**
     * Les écritures du compte connecté qui attendent encore, dans l'ordre de la
     * file, celle qui vole comprise : une copie profonde, en lecture seule.
     *
     * La page de séance les superpose à la séance du serveur à son montage
     * (`Utils/seanceEtFile`) : ce qui attendait en file n'apparaissait qu'après
     * le vidage, et une série ajoutée sans réseau disparaissait de l'écran au
     * rechargement ou au bouton Retour (#2048). La copie ne laisse personne
     * modifier la file sans passer par `modifierEnFile` ou `retirerDeLaFile`,
     * qui écrivent le stockage durable.
     *
     * @returns {Array<Object>}
     */
    entreesDuCompte() {
        return this.compte === null
            ? []
            : JSON.parse(JSON.stringify(this.queue.filter((entree) => entree.compte === this.compte)))
    }

    /** Combien d'écritures directes attendent encore leur réponse. */
    ecrituresEnCours() {
        return this.ecrituresDirectes.size
    }

    /**
     * Se règle quand tout ce que la page a écrit est parti : la file vidée
     * (ou arrêtée faute de réseau, de session, ou devant une erreur
     * passagère), et les écritures directes en vol revenues, y compris celles
     * que le vidage a lui-même déclenchées. Une écriture directe qui finit en
     * file relance le vidage. Sans délai maximum : l'appelant borne l'attente,
     * puis lit `enAttente()` et `ecrituresEnCours()`.
     *
     * @returns {Promise<void>}
     */
    async attendreLesEcritures() {
        for (;;) {
            await this.processQueue()

            if (this.ecrituresDirectes.size > 0) {
                await Promise.allSettled([...this.ecrituresDirectes])

                continue
            }

            /*
             * Une tâche plus tard, et non tout de suite. Une écriture que le
             * vidage débloque sans la lancer lui-même, comme une coche faite
             * pendant que la création de sa série volait, franchit encore
             * quelques étapes de promesses avant de partir : on concluait que
             * tout était parti juste avant qu'elle parte.
             */
            await new Promise((resolve) => setTimeout(resolve, 0))

            if (this.ecrituresDirectes.size === 0) {
                return
            }
        }
    }

    /** La première écriture du compte connecté encore en file, ou undefined. */
    teteDeFile() {
        return this.compte === null ? undefined : this.queue.find((entree) => entree.compte === this.compte)
    }

    /** Si cette écriture attend encore dans la file, ou y vole. */
    estEnFile(queueId) {
        return this.queue.some((entree) => entree.id === queueId)
    }

    /** Retire une entrée réglée de la file, où qu'elle soit maintenant, et l'écrit. */
    retirerLEntree(config) {
        this.remplacerLEntree(config, [])
    }

    /**
     * Remplace une entrée réglée, à sa place, par les écritures qui doivent la
     * suivre, et écrit la file d'un seul coup : un rechargement entre les deux
     * ne perd ni ne double rien.
     *
     * @param {Object} config
     * @param {Array<Object>} remplacantes
     */
    remplacerLEntree(config, remplacantes) {
        this.queue = this.queue.flatMap((entree) => (entree === config ? remplacantes : [entree]))
        this.saveQueue()
    }

    /**
     * La modification qui porte au serveur ce qu'il a ignoré d'une création
     * rejouée, ou null quand il a tout gardé, ou que la création ne dit pas
     * comment se rattraper (`ajusterPar`).
     *
     * @param {Object} config l'entrée de la création
     * @param {Object|undefined} envoye la charge partie
     * @param {Object|null|undefined} produit ce que le serveur a rendu
     * @returns {Object|null} l'entrée de file de la modification
     */
    ajustementDe(config, envoye, produit) {
        if (typeof config.ajusterPar !== 'string' || produit?.id === undefined) {
            return null
        }

        const ecart = ecartAvec(envoye, produit)

        if (Object.keys(ecart).length === 0) {
            return null
        }

        return {
            method: 'patch',
            url: config.ajusterPar.replace(ID_A_VENIR, encodeURIComponent(String(produit.id))),
            data: ecart,
            id: this.nouvelIdentifiantDEntree(),
            timestamp: new Date().toISOString(),
            compte: config.compte,
        }
    }

    saveQueue() {
        try {
            localStorage.setItem(QUEUE_KEY, JSON.stringify(this.queue))
        } catch {
            /*
             * Quota atteint ou stockage indisponible. La file reste en mémoire
             * et continue de se vider ; on le dit, pour que la page puisse
             * prévenir que ce qui n'est pas encore parti ne survivra pas à un
             * rechargement.
             */
            window.dispatchEvent(new CustomEvent('sync:storage-full', { detail: { pending: this.enAttente() } }))
        }
    }

    /**
     * Flushes the queue, one drain at a time.
     *
     * Three things now ask for a flush — construction, `online`, and the app
     * becoming visible again — and they can easily overlap. Chaining them means
     * a second caller waits for the drain already in flight instead of reading
     * a half-emptied queue and sending the same mutation twice.
     */
    async processQueue() {
        this.pending = (this.pending ?? Promise.resolve()).catch(() => {}).then(() => this.drainQueue())

        return this.pending
    }

    /**
     * Drains from the front, and only lets an entry go once it is settled.
     *
     * This used to copy the queue into memory and immediately write an EMPTY
     * queue to localStorage, doing the sending afterwards and saving again only
     * at the very end. For the whole duration of a drain the durable record said
     * there was nothing pending, so a reload, a crash or an iOS suspension in
     * that window destroyed every mutation the user had made offline — the one
     * thing the queue exists to prevent.
     *
     * Stopping on a network failure rather than skipping past it also keeps the
     * order intact. Re-queuing a failed entry pushed it behind mutations added
     * later, so on the next drain an older value could land after a newer one
     * and overwrite it.
     */
    async drainQueue() {
        /*
         * Une page ouverte des semaines (la PWA suspendue, puis reprise) ne
         * se recharge pas : chaque vidage commence par abandonner ce qui a
         * dépassé trente jours (#2050).
         */
        this.abandonnerLesEntreesPerimees()

        for (let config = this.teteDeFile(); config !== undefined; config = this.teteDeFile()) {
            /*
             * Une erreur passagère a fixé l'heure du prochain essai. Un autre
             * déclencheur (une écriture directe, `online`, le retour au premier
             * plan) ne la devance pas : sans cela, une rafale de saisies
             * épuisait les essais en quelques secondes, contre un serveur qui
             * redémarrait.
             */
            if ((config.prochainEssai ?? 0) > Date.now()) {
                this.programmerLaRelance(config.prochainEssai - Date.now())

                return
            }

            /*
             * Ce dont elle dépend est sorti de la file sans rien produire :
             * refusé, ou retiré. Le serveur la refuserait faute de parent ; elle
             * est classée refusée sans partir, et annoncée.
             */
            if (filesReferencees(config.data).length > 0) {
                this.sortirRefusee(config, null)

                continue
            }

            /*
             * La requête seule, sans ce que la file note pour elle-même : son
             * identifiant, ses compteurs d'essais. Son compte, lui, part avec
             * elle : l'onglet qui vide la file peut croire connecté un compte
             * dont un autre onglet a remplacé la session, et seul le serveur
             * sait laquelle accompagne la requête (#1964). Son délai aussi :
             * celui que l'appelant lui avait donné, sinon `DELAI_DE_REJEU_MS`.
             */
            const requete = {
                method: config.method,
                url: config.url,
                data: config.data,
                headers: { ...config.headers, [ENTETE_DU_COMPTE]: config.compte },
                timeout: config.timeout ?? DELAI_DE_REJEU_MS,
            }

            /*
             * Notée avant le départ, et non après l'échec : une page qui meurt
             * pendant que la requête vole ne note plus rien, et la création a
             * pu aboutir quand même. Retirée ensuite, elle devra être annulée
             * sur le serveur, pas seulement oubliée ici (#1960).
             */
            if (estUneCreation(config) && config.tentee !== true) {
                config.tentee = true
                this.saveQueue()
            }

            let reponse

            /** Combien de fois son compte avait quitté l'appareil à son départ. */
            const oublis = this.oublisDe(normaliserLeCompte(config.compte))

            try {
                // Celle-ci est partie : plus rien ne se fond dans sa charge.
                this.enVol = config.id

                reponse = await http(requete)
            } catch (error) {
                /*
                 * Son compte a quitté l'appareil pendant qu'elle volait
                 * (#2050) : la déconnexion acceptée l'a retirée de la file avec
                 * le reste, et rien d'elle n'y revient, pas même parmi les
                 * refus, qu'elle a effacés aussi.
                 */
                if (this.oublisDe(normaliserLeCompte(config.compte)) !== oublis) {
                    continue
                }

                /*
                 * Le serveur a refusé de l'exécuter sous la session d'un autre
                 * compte. L'écriture reste en tête pour le sien, sans essai
                 * consommé, et rien de ce compte ne passe devant elle.
                 */
                if (estPourUnAutreCompte(error)) {
                    return
                }

                // Anything that was not a network failure used to fall off the end
                // of this block and be lost — a 500, an expired token, a validation
                // error — with a console.error as the only trace. These are edits
                // the user made while offline, so dropping them silently is data
                // loss, not error handling.
                const verdict = classifySyncError(error)

                if (verdict === SYNC_OFFLINE) {
                    // Still nothing out there. Leave this entry, and everything
                    // behind it, exactly where they are.
                    return
                }

                if (verdict === SYNC_AUTH) {
                    /*
                     * 401 ou 419 : la session ou le jeton a expiré pendant que
                     * la PWA dormait. Ce n'est pas un refus de l'écriture, c'est
                     * une porte fermée : tout reste en place, on prévient, et on
                     * réessaiera après la prochaine reconnexion. Cette écriture
                     * était classée refusée et la file vidée derrière elle.
                     */
                    config.authAttempts = (config.authAttempts ?? 0) + 1
                    this.saveQueue()

                    window.dispatchEvent(
                        new CustomEvent('sync:auth-required', {
                            detail: {
                                url: config.url,
                                status: error?.response?.status ?? null,
                                pending: this.enAttente(),
                            },
                        }),
                    )

                    if (config.authAttempts < MAX_AUTH_ATTEMPTS) {
                        return
                    }
                }

                if (verdict === SYNC_TRANSIENT) {
                    /*
                     * 5xx ou 429 : le serveur redémarre, ou demande de ralentir.
                     * L'écriture n'est pas refusée, elle est en avance. Elle
                     * garde sa place en tête, tout ce qui la suit attend avec
                     * elle, et la file repart seule après une attente qui
                     * double (ou celle que demande `Retry-After`). Elle passait
                     * aux refusées au premier 502, et toute la file d'une
                     * séance avec elle en un seul passage (#1963).
                     */
                    config.transientAttempts = (config.transientAttempts ?? 0) + 1

                    if (config.transientAttempts < MAX_TRANSIENT_ATTEMPTS) {
                        const attente = attenteAvantLeProchainEssai(error, config.transientAttempts)

                        config.prochainEssai = Date.now() + attente
                        this.saveQueue()
                        this.programmerLaRelance(attente)

                        return
                    }
                }

                this.sortirRefusee(config, error)

                continue
            } finally {
                this.enVol = null
            }

            this.rejeuxDepuisLaDerniereVisite += 1

            /*
             * Une création retirée de l'écran après avoir été tentée : la
             * rejouer rend ce qu'elle a produit, idempotence oblige, et c'est
             * cela qu'on supprime maintenant.
             */
            const produit = reponse?.data?.data

            if (config.aAnnuler !== undefined) {
                this.remplacerParSonAnnulation(config, produit?.id)

                continue
            }

            /*
             * Une création que le serveur avait déjà faite, sa réponse perdue
             * en route : il rend la ligne telle qu'il l'avait enregistrée et
             * ignore la charge rejouée, saisies et coches fondues comprises.
             * Ce qu'il a ignoré repart, à la place de la création, par une
             * modification de ce qu'elle a produit. C'était l'écran qui le
             * renvoyait en adoptant la série : sans lui, après un
             * rechargement, rien ne partait (#1960).
             */
            const ajustement = this.ajustementDe(config, requete.data, produit)

            if (produit?.id !== undefined) {
                this.noterLeProduit(config.id, produit.id)
            }

            // Settled. Only now may it leave, and the queue that survives a
            // reload is written before we move on — with what follows it.
            this.remplacerLEntree(config, ajustement === null ? [] : [ajustement])

            /**
             * Says what this write finally produced, and what it carried.
             *
             * A create queued offline leaves the caller holding a
             * placeholder id and no way to learn the real one — the row
             * exists on the server and the page never finds out. Anything
             * the user built on top of it, a set added to an exercise that
             * was still queued, was then stranded for good: the queue drained,
             * the exercise appeared, and the set was never sent by anyone.
             *
             * `envoye` est la charge partie, saisies fondues comprises : ce qui
             * a été tapé pendant que la requête volait n'y est pas, et
             * l'appelant le renvoie (#1960). `data` est ce que le serveur
             * gardera : sa réponse, et ce qu'il a ignoré, que la modification
             * `ajustement` (son entrée de file) lui porte maintenant.
             * L'annonce suit l'écriture de la file, pour que l'entrée n'y soit
             * plus quand l'appelant la lit. L'annulation d'une création retirée
             * n'a personne pour l'attendre : elle ne s'annonce pas.
             */
            if (config.annulation === true) {
                continue
            }

            window.dispatchEvent(
                new CustomEvent('sync:replayed', {
                    detail: {
                        queueId: config.id,
                        url: config.url,
                        data: ajustement === null ? (produit ?? null) : { ...produit, ...ajustement.data },
                        envoye: requete.data,
                        ajustement: ajustement?.id ?? null,
                    },
                }),
            )
        }
    }

    /**
     * Sort de la file une écriture refusée pour de bon, dans cet ordre : le
     * refus noté, l'entrée retirée, puis le refus annoncé.
     *
     * Noté avant le retrait, pour qu'elle soit toujours quelque part. Annoncé
     * après, comme un rejeu : l'écouteur lit la file telle qu'elle est
     * désormais. Annoncé avant, le refus de la dernière écriture trouvait la
     * file encore occupée par elle, et rien ne suivait son retrait : la séance
     * rechargée attendait une file vide qu'on ne lui annonçait jamais, et
     * l'exercice créé juste avant restait absent de l'écran (#1960, #1962).
     *
     * @param {Object} config l'entrée refusée, en tête de file
     * @param {Object|null} error l'erreur du serveur, ou null quand elle n'est pas partie
     */
    sortirRefusee(config, error) {
        const annoncer = this.refusMerite(config, error)

        if (annoncer) {
            this.recordFailure(config, error)
        }

        this.retirerLEntree(config)

        if (annoncer) {
            this.annoncerLeRefus(config, error)
        }
    }

    /**
     * Si l'échec définitif d'une écriture mérite d'être annoncé.
     *
     * Pas celui d'une création que l'écran a déjà retirée : l'utilisateur l'a
     * supprimée, et rien n'en reste à lui montrer. Ni le 404 de son
     * annulation : ce qu'elle devait supprimer n'existe déjà plus.
     */
    refusMerite(config, error) {
        if (config.aAnnuler !== undefined) {
            return false
        }

        return !(config.annulation === true && error?.response?.status === 404)
    }

    /**
     * Remplace, à sa place en tête de file, une création à annuler par la
     * suppression de ce qu'elle a produit. Une création qui n'a rien produit
     * sort simplement.
     *
     * @param {Object} config l'entrée de la création, marquée `aAnnuler`
     * @param {number|string|undefined} produit l'identifiant que le serveur a rendu
     */
    remplacerParSonAnnulation(config, produit) {
        const annulation =
            produit === undefined
                ? []
                : [
                      {
                          method: 'delete',
                          url: config.aAnnuler.replace(ID_A_VENIR, encodeURIComponent(String(produit))),
                          id: this.nouvelIdentifiantDEntree(),
                          timestamp: new Date().toISOString(),
                          compte: config.compte,
                          annulation: true,
                      },
                  ]

        this.remplacerLEntree(config, annulation)
    }

    /** Un identifiant d'entrée de file, unique sur l'appareil. */
    nouvelIdentifiantDEntree() {
        return Date.now() + Math.random().toString(36).substr(2, 9)
    }

    /**
     * Fond des valeurs dans la charge d'une écriture qui attend encore en file.
     *
     * Une série créée hors ligne n'a pas d'identifiant à donner à un PATCH : sa
     * saisie et sa coche ne partaient jamais, et la file ne rejouait que la
     * création, avec les valeurs recopiées de la série précédente (#1960). Elles
     * rejoignent désormais l'entrée de sa création, qui les porte au serveur et
     * survit à un rechargement.
     *
     * @param {string} queueId
     * @param {Object} valeurs
     * @returns {boolean} false quand l'entrée n'attend plus — partie, en vol ou
     *   retirée — : l'appelant passe alors par l'identifiant que le serveur a émis.
     */
    modifierEnFile(queueId, valeurs) {
        const entree = this.queue.find((candidate) => candidate.id === queueId && candidate.compte === this.compte)

        // Une création à annuler ne porte plus rien : la série a quitté l'écran.
        if (entree === undefined || this.enVol === queueId || entree.aAnnuler !== undefined) {
            return false
        }

        entree.data = { ...entree.data, ...valeurs }
        this.saveQueue()

        return true
    }

    /**
     * Retire de la file une écriture qui n'a plus lieu d'être : la création
     * d'une série supprimée avant d'avoir atteint le serveur. La file la
     * rejouait au retour du réseau, et la série supprimée à l'écran réapparaissait
     * en base (#1960). Les écritures qui en dépendaient la suivent : les séries
     * d'un exercice retiré avant d'avoir été créé (#1962).
     *
     * Une création déjà tentée a pu atteindre le serveur sans que la réponse
     * revienne : l'oublier ici laissait la ligne là-bas. Elle reste donc en
     * file, marquée à annuler. Le vidage la rejoue, et le serveur, qui
     * reconnaît sa clé d'idempotence, rend ce qu'elle a produit sans le doubler
     * ou le crée ; puis il le supprime par `annulerPar`. Pour l'écran, elle est
     * retirée comme les autres : `sync:retired` l'annonce aussi.
     *
     * @param {string} queueId
     * @param {{annulerPar?: (id: string) => string}} options l'adresse qui
     *   supprime ce que la création aura produit, d'après son identifiant
     * @returns {boolean} false quand l'entrée est déjà partie ou en vol.
     */
    retirerDeLaFile(queueId, { annulerPar } = {}) {
        const entree = this.queue.find((candidate) => candidate.id === queueId && candidate.compte === this.compte)

        if (entree === undefined || this.enVol === queueId) {
            return false
        }

        if (entree.aAnnuler !== undefined) {
            return true
        }

        const retirees = new Set([queueId])

        // La file est dans l'ordre : une dépendante vient toujours après ce dont elle dépend.
        this.queue.forEach((candidate) => {
            if (filesReferencees(candidate.data).some((parent) => retirees.has(parent))) {
                retirees.add(candidate.id)
            }
        })

        const aAnnuler = entree.tentee === true && typeof annulerPar === 'function'

        if (aAnnuler) {
            entree.aAnnuler = annulerPar(ID_A_VENIR)
        }

        this.queue = this.queue.filter((candidate) => (aAnnuler && candidate === entree) || !retirees.has(candidate.id))
        this.saveQueue()

        window.dispatchEvent(new CustomEvent('sync:retired', { detail: { queueIds: [...retirees] } }))

        return true
    }

    /**
     * Relance le vidage dans `attente` millisecondes. Une seule relance à la
     * fois : la plus récente remplace la précédente.
     */
    programmerLaRelance(attente) {
        clearTimeout(this.relance)

        this.relance = setTimeout(() => {
            this.relance = null
            this.processQueue()
        }, attente)
    }

    /**
     * Keeps a mutation the server refused, so it can be shown or replayed rather
     * than vanishing. `annoncerLeRefus` tells the listeners, once the entry has
     * left the queue.
     */
    recordFailure(config, error) {
        this.failed.push({
            ...config,
            failedAt: new Date().toISOString(),
            status: error?.response?.status ?? null,
        })

        /**
         * Bounded, and nothing in the app has ever emptied it —
         * clearFailedRequests() has no caller. Each entry holds a whole request
         * config, so an unbounded list eventually fills localStorage and the
         * write below throws QuotaExceededError in the middle of a drain,
         * taking the queue down with it. The newest failures are the ones worth
         * showing; the oldest are long past being actionable.
         */
        if (this.failed.length > MAX_FAILED) {
            this.failed = this.failed.slice(-MAX_FAILED)
        }

        this.saveFailed()
    }

    /**
     * Dit aux écouteurs qu'une écriture a été refusée, et laquelle.
     *
     * @param {Object} config l'entrée refusée
     * @param {Object|null} error l'erreur du serveur, ou null quand elle n'est pas partie
     */
    annoncerLeRefus(config, error) {
        window.dispatchEvent(
            new CustomEvent('sync:failed', {
                // The payload travels with the event so a listener can say WHAT
                // was refused. A URL alone can only ever produce "an item of the
                // session" — true, and of no use to someone who now has to work
                // out which of their sets is missing.
                detail: {
                    queueId: config.id,
                    url: config.url,
                    status: error?.response?.status ?? null,
                    data: config.data,
                },
            }),
        )
    }

    saveFailed() {
        try {
            if (this.failed.length === 0) {
                localStorage.removeItem(FAILED_KEY)

                return
            }

            localStorage.setItem(FAILED_KEY, JSON.stringify(this.failed))
        } catch {
            // Storage is full or unavailable. Losing the record of a refusal is
            // bad; letting it abort the drain and strand the rest of the queue
            // is worse.
            this.failed = this.failed.slice(-1)
        }
    }

    /** Mutations the server refused, still on disk — those of the account signed in. */
    failedRequests() {
        return this.failed.filter((entree) => entree.compte === this.compte)
    }

    /**
     * Oublie les refus d'un compte, le connecté par défaut ; ceux d'un autre
     * compte l'attendent.
     *
     * @param {number|string|null} compte
     */
    clearFailedRequests(compte = this.compte) {
        const oublie = normaliserLeCompte(compte)

        this.failed = this.failed.filter((entree) => entree.compte !== oublie)
        this.saveFailed()
    }

    /** Helper for GET requests */
    get(url, config = {}) {
        return this.request({ ...config, method: 'get', url })
    }

    /** Helper for POST requests */
    post(url, data = {}, config = {}) {
        return this.request({ ...config, method: 'post', url, data })
    }

    /** Helper for PATCH requests */
    patch(url, data = {}, config = {}) {
        return this.request({ ...config, method: 'patch', url, data })
    }

    /** Helper for DELETE requests */
    delete(url, config = {}) {
        return this.request({ ...config, method: 'delete', url })
    }
}

export default new SyncService()
