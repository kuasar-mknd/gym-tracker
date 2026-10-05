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
                const retryAfter = parseInt(error.response.headers?.['retry-after'] || '2', 10)
                await new Promise((resolve) => setTimeout(resolve, retryAfter * 1000))

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

            try {
                // Remove internal queue ID before sending
                const { id, timestamp, authAttempts, transientAttempts, prochainEssai, ...requete } = config
                const response = await http(requete)

                /**
                 * Says what this write finally produced.
                 *
                 * A create queued offline leaves the caller holding a
                 * placeholder id and no way to learn the real one — the row
                 * exists on the server and the page never finds out. Anything
                 * the user built on top of it, a set added to an exercise that
                 * was still queued, was then stranded for good: the queue drained,
                 * the exercise appeared, and the set was never sent by anyone.
                 */
                window.dispatchEvent(
                    new CustomEvent('sync:replayed', {
                        detail: { queueId: id, url: config.url, data: response?.data?.data ?? null },
                    }),
                )
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
            }

            // Settled — sent, or filed as refused. Only now may it leave, and
            // the queue that survives a reload is written before we move on.
            this.retirerLEntree(config)
        }
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
                detail: { url: config.url, status: error?.response?.status ?? null, data: config.data },
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
