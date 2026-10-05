import { http } from '@/Utils/http'
import { classifySyncError, SYNC_AUTH, SYNC_OFFLINE, SYNC_TRANSIENT } from '@/Utils/syncErrors'

const QUEUE_KEY = 'offline_sync_queue'
const FAILED_KEY = 'offline_sync_failed'

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

const MUTATIONS = ['post', 'patch', 'put', 'delete']

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
 * L'identifiant d'un compte tel que la file le note : une chaîne, ou null quand
 * personne n'est connecté. `42` et `'42'` désignent le même compte.
 */
const normaliserLeCompte = (id) => (id === undefined || id === null || id === '' ? null : String(id))

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

        if (this.queue.length !== file.length) {
            this.saveQueue()
        }

        if (this.failed.length !== refusees.length) {
            this.saveFailed()
        }

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

        window.addEventListener('online', () => this.processQueue())

        /**
         * Chaque visite Inertia, le premier affichage compris, dit qui est
         * connecté. C'est aussi une preuve que le réseau répond : la file du
         * compte part, s'il en a une.
         */
        document.addEventListener('inertia:navigate', (event) =>
            this.definirLeCompte(event.detail?.page?.props?.auth?.user?.id),
        )

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
     * @param {Object} config La requête, au format de `Utils/http`
     * @returns {Promise}
     */
    async request(config) {
        const stamped = this.stampIdempotency(config)

        /*
         * Le compte qui fait l'écriture, pris à son départ : une écriture
         * partie juste avant une déconnexion, et tombée sur un réseau absent
         * juste après, reste à celui qui l'a faite.
         */
        const compte = this.compte

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

            if (this.enAttente() > 0) {
                const queueId = this.addToQueue(stamped, compte)

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
                    return this.queueOrThrow(retryError, stamped, compte)
                }
            }

            return this.queueOrThrow(error, stamped, compte)
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
    queueOrThrow(error, config, compte) {
        // A response means the server answered, so this is its verdict, not a
        // connectivity problem — queueing it would hide a real refusal.
        if (error.code === 'ERR_NETWORK' || (!error.response && error.request)) {
            const queueId = this.addToQueue(config, compte)

            // queueId lets a caller waiting on what this create produces pick its
            // own write out of the drain later — see the `sync:replayed` event.
            if (queueId !== null) {
                return Promise.reject({ isOffline: true, queueId, message: 'Network error: Request queued' })
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
     * sous un autre compte. L'appelant reçoit alors l'échec réseau tel quel, et
     * le dit au lieu de garder la valeur comme si elle allait partir.
     *
     * @param {Object} config
     * @param {string|null} compte le compte connecté quand l'écriture est partie
     * @returns {string|null} the queue entry's id, so a caller that depends on
     *   what this write eventually creates can recognise it when it goes out.
     */
    addToQueue(config, compte = this.compte) {
        // Only queue mutations (POST, PATCH, PUT, DELETE)
        if (!this.isMutation(config) || compte === null) {
            return null
        }

        const id = Date.now() + Math.random().toString(36).substr(2, 9)

        this.queue.push({ ...config, id, timestamp: new Date().toISOString(), compte })
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
     * @param {Object} config la requête, au format de `Utils/http`
     * @returns {string|null} l'entrée créée, ou null quand rien n'entre : pas de
     *   compte connecté, ou une écriture dont elle dépend est sortie de la file
     *   sans rien produire (refusée, retirée).
     */
    mettreEnFile(config) {
        let data = config.data

        for (const [champ, valeur] of Object.entries(data ?? {})) {
            const parent = fileReferencee(valeur)

            if (parent === null) {
                continue
            }

            if (this.produits.has(parent)) {
                data = { ...data, [champ]: this.produits.get(parent) }

                continue
            }

            if (!this.queue.some((entree) => entree.id === parent && entree.compte === this.compte)) {
                return null
            }
        }

        return this.addToQueue(this.stampIdempotency({ ...config, data }))
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
     * Oublie tout ce que la file garde pour ce compte : ses écritures en
     * attente et ses refus.
     *
     * Un compte supprimé ne se reconnectera jamais. Ce qu'il laissait en file
     * ne partirait donc jamais, et resterait sur l'appareil, lisible par qui
     * s'en sert ensuite, alors que la suppression promet d'effacer ses
     * données (#1964). Les écritures des autres comptes restent à eux.
     *
     * @param {number|string|null|undefined} id
     */
    oublierLeCompte(id) {
        const compte = normaliserLeCompte(id)

        if (compte === null) {
            return
        }

        this.queue = this.queue.filter((entree) => entree.compte !== compte)
        this.failed = this.failed.filter((entree) => entree.compte !== compte)
        this.saveQueue()
        this.saveFailed()
    }

    /** Combien d'écritures du compte connecté attendent encore d'être envoyées. */
    enAttente() {
        return this.compte === null ? 0 : this.queue.filter((entree) => entree.compte === this.compte).length
    }

    /** La première écriture du compte connecté encore en file, ou undefined. */
    teteDeFile() {
        return this.compte === null ? undefined : this.queue.find((entree) => entree.compte === this.compte)
    }

    /** Retire une entrée réglée de la file, où qu'elle soit maintenant, et l'écrit. */
    retirerLEntree(config) {
        this.queue = this.queue.filter((entree) => entree !== config)
        this.saveQueue()
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
                this.recordFailure(config, null)
                this.retirerLEntree(config)

                continue
            }

            /*
             * La requête seule, sans ce que la file note pour elle-même : son
             * identifiant, son compte, ses compteurs d'essais.
             */
            const requete = { method: config.method, url: config.url, data: config.data, headers: config.headers }

            /** Ce que l'écriture a produit, annoncé une fois la file réécrite. */
            let rejeu = null

            try {
                // Celle-ci est partie : plus rien ne se fond dans sa charge.
                this.enVol = config.id

                const response = await http(requete)

                if (response?.data?.data?.id !== undefined) {
                    this.noterLeProduit(config.id, response.data.data.id)
                }

                rejeu = {
                    queueId: config.id,
                    url: config.url,
                    data: response?.data?.data ?? null,
                    envoye: requete.data,
                }
            } catch (error) {
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

                this.recordFailure(config, error)
            } finally {
                this.enVol = null
            }

            // Settled — sent, or filed as refused. Only now may it leave, and
            // the queue that survives a reload is written before we move on.
            this.retirerLEntree(config)

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
             * l'appelant le renvoie (#1960). L'annonce suit l'écriture de la
             * file, pour que l'entrée n'y soit plus quand l'appelant la lit.
             */
            if (rejeu !== null) {
                window.dispatchEvent(new CustomEvent('sync:replayed', { detail: rejeu }))
            }
        }
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

        if (entree === undefined || this.enVol === queueId) {
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
     * @param {string} queueId
     * @returns {boolean} false quand l'entrée est déjà partie ou en vol.
     */
    retirerDeLaFile(queueId) {
        const entree = this.queue.find((candidate) => candidate.id === queueId && candidate.compte === this.compte)

        if (entree === undefined || this.enVol === queueId) {
            return false
        }

        const retirees = new Set([queueId])

        // La file est dans l'ordre : une dépendante vient toujours après ce dont elle dépend.
        this.queue.forEach((candidate) => {
            if (filesReferencees(candidate.data).some((parent) => retirees.has(parent))) {
                retirees.add(candidate.id)
            }
        })

        this.queue = this.queue.filter((candidate) => !retirees.has(candidate.id))
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
     * than vanishing. Listeners get told the moment it happens.
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

    /** Oublie les refus du compte connecté ; ceux d'un autre compte l'attendent. */
    clearFailedRequests() {
        this.failed = this.failed.filter((entree) => entree.compte !== this.compte)
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
