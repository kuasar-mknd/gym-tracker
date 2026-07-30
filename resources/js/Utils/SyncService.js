import axios from 'axios'
import { classifySyncError, SYNC_OFFLINE } from '@/Utils/syncErrors'

const QUEUE_KEY = 'offline_sync_queue'
const FAILED_KEY = 'offline_sync_failed'

class SyncService {
    constructor() {
        this.queue = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]')
        this.failed = JSON.parse(localStorage.getItem(FAILED_KEY) || '[]')
        this.isOnline = navigator.onLine

        window.addEventListener('online', () => {
            this.isOnline = true
            this.processQueue()
        })

        window.addEventListener('offline', () => {
            this.isOnline = false
        })
    }

    /**
     * Perform an API request, queuing it if offline.
     * @param {Object} config Axios request config
     * @returns {Promise}
     */
    async request(config) {
        if (!this.isOnline) {
            this.addToQueue(config)
            return Promise.reject({ isOffline: true, message: 'Offline: Request queued' })
        }

        const api = window.axios || axios

        try {
            return await api(config)
        } catch (error) {
            // Auto-retry once on 429 Too Many Requests (rate limiting)
            if (error.response?.status === 429) {
                const retryAfter = parseInt(error.response.headers?.['retry-after'] || '2', 10)
                await new Promise((resolve) => setTimeout(resolve, retryAfter * 1000))
                return await api(config)
            }

            if (!navigator.onLine || error.code === 'ERR_NETWORK') {
                this.addToQueue(config)
                return Promise.reject({ isOffline: true, message: 'Network error: Request queued' })
            }
            throw error
        }
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

    async processQueue() {
        if (this.queue.length === 0 || !this.isOnline) return

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
