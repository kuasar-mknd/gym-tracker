import axios from 'axios'
import { classifySyncError, SYNC_OFFLINE } from '@/Utils/syncErrors'

const QUEUE_KEY = 'offline_sync_queue'
const FAILED_KEY = 'offline_sync_failed'

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
        this.queue = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]')
        this.failed = JSON.parse(localStorage.getItem(FAILED_KEY) || '[]')

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

        try {
            return await api(stamped)
        } catch (error) {
            // Auto-retry once on 429 Too Many Requests (rate limiting)
            if (error.response?.status === 429) {
                const retryAfter = parseInt(error.response.headers?.['retry-after'] || '2', 10)
                await new Promise((resolve) => setTimeout(resolve, retryAfter * 1000))
                return await api(config)
            }

            // A response means the server answered, so this is its verdict, not
            // a connectivity problem — queueing it would hide a real refusal.
            if (error.code === 'ERR_NETWORK' || (!error.response && error.request)) {
                // The stamped config, so the replay carries the same key as the
                // attempt that may already have been written.
                this.addToQueue(stamped)
                return Promise.reject({ isOffline: true, message: 'Network error: Request queued' })
            }
            throw error
        }
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

    addToQueue(config) {
        // Only queue mutations (POST, PATCH, PUT, DELETE)
        if (['post', 'patch', 'put', 'delete'].includes(config.method?.toLowerCase())) {
            this.queue.push({
                ...config,
                id: Date.now() + Math.random().toString(36).substr(2, 9),
                timestamp: new Date().toISOString(),
            })
            this.saveQueue()
        }
    }

    saveQueue() {
        localStorage.setItem(QUEUE_KEY, JSON.stringify(this.queue))
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

    async drainQueue() {
        if (this.queue.length === 0) return

        const tempQueue = [...this.queue]
        this.queue = []
        this.saveQueue()

        const api = window.axios || axios

        for (const config of tempQueue) {
            try {
                // Remove internal queue ID before sending
                const { id, timestamp, ...axiosConfig } = config
                await api(axiosConfig)
            } catch (error) {
                // Anything that was not a network failure used to fall off the end
                // of this block and be lost — a 500, an expired token, a validation
                // error — with a console.error as the only trace. These are edits
                // the user made while offline, so dropping them silently is data
                // loss, not error handling.
                if (classifySyncError(error) === SYNC_OFFLINE) {
                    this.queue.push(config)

                    continue
                }

                this.recordFailure(config, error)
            }
        }
        this.saveQueue()
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

        localStorage.setItem(FAILED_KEY, JSON.stringify(this.failed))

        window.dispatchEvent(
            new CustomEvent('sync:failed', {
                detail: { url: config.url, status: error?.response?.status ?? null },
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
