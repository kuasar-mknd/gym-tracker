import axios from 'axios'
import { classifySyncError, SYNC_AUTH, SYNC_OFFLINE } from '@/Utils/syncErrors'

const QUEUE_KEY = 'offline_sync_queue'
const FAILED_KEY = 'offline_sync_failed'

/** How many refused mutations are worth keeping. See recordFailure. */
const MAX_FAILED = 50

/**
 * Combien de portes fermées (401, 419) une même écriture peut rencontrer avant
 * d'être classée refusée plutôt que de bloquer la file pour toujours.
 */
const MAX_AUTH_ATTEMPTS = 3

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
        this.queue = lireLaListe(QUEUE_KEY)
        this.failed = lireLaListe(FAILED_KEY)

        window.addEventListener('online', () => this.processQueue())

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
     * @param {Object} config Axios request config
     * @returns {Promise}
     */
    async request(config) {
        const api = window.axios || axios
        const stamped = this.stampIdempotency(config)

        /*
         * Une écriture directe ne doit pas doubler celles qui attendent : la
         * file rejouait une modification plus ancienne APRÈS celle que
         * l'utilisateur venait de faire en ligne, et l'ancienne valeur écrasait
         * la nouvelle. On vide donc d'abord ; si la file ne se vide pas
         * (toujours hors ligne, ou session à renouveler), la nouvelle écriture
         * prend sa place derrière, dans l'ordre où elle a été faite.
         */
        if (this.isMutation(stamped) && this.queue.length > 0) {
            await this.processQueue()

            if (this.queue.length > 0) {
                const queueId = this.addToQueue(stamped)

                return Promise.reject({ isOffline: true, queueId, message: 'Network error: Request queued' })
            }
        }

        try {
            return await api(stamped)
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
                    return await api(stamped)
                } catch (retryError) {
                    return this.queueOrThrow(retryError, stamped)
                }
            }

            return this.queueOrThrow(error, stamped)
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
    queueOrThrow(error, config) {
        // A response means the server answered, so this is its verdict, not a
        // connectivity problem — queueing it would hide a real refusal.
        if (error.code === 'ERR_NETWORK' || (!error.response && error.request)) {
            const queueId = this.addToQueue(config)

            // queueId lets a caller waiting on what this create produces pick its
            // own write out of the drain later — see the `sync:replayed` event.
            return Promise.reject({ isOffline: true, queueId, message: 'Network error: Request queued' })
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

    /**
     * @returns {string|null} the queue entry's id, so a caller that depends on
     *   what this write eventually creates can recognise it when it goes out.
     */
    isMutation(config) {
        return MUTATIONS.includes(String(config.method ?? '').toLowerCase())
    }

    addToQueue(config) {
        // Only queue mutations (POST, PATCH, PUT, DELETE)
        if (!this.isMutation(config)) {
            return null
        }

        const id = Date.now() + Math.random().toString(36).substr(2, 9)

        this.queue.push({ ...config, id, timestamp: new Date().toISOString() })
        this.saveQueue()

        return id
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
            window.dispatchEvent(new CustomEvent('sync:storage-full', { detail: { pending: this.queue.length } }))
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
        const api = window.axios || axios

        while (this.queue.length > 0) {
            const config = this.queue[0]

            try {
                // Remove internal queue ID before sending
                const { id, timestamp, authAttempts, ...axiosConfig } = config
                const response = await api(axiosConfig)

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
                                pending: this.queue.length,
                            },
                        }),
                    )

                    if (config.authAttempts < MAX_AUTH_ATTEMPTS) {
                        return
                    }
                }

                this.recordFailure(config, error)
            }

            // Settled — sent, or filed as refused. Only now may it leave, and
            // the queue that survives a reload is written before we move on.
            this.queue.shift()
            this.saveQueue()
        }
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

        try {
            localStorage.setItem(FAILED_KEY, JSON.stringify(this.failed))
        } catch {
            // Storage is full or unavailable. Losing the record of a refusal is
            // bad; letting it abort the drain and strand the rest of the queue
            // is worse.
            this.failed = this.failed.slice(-1)
        }

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

    /** Mutations the server refused, still on disk. */
    failedRequests() {
        return [...this.failed]
    }

    clearFailedRequests() {
        this.failed = []
        localStorage.removeItem(FAILED_KEY)
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
