import axios from 'axios'

const QUEUE_KEY = 'offline_sync_queue'

class SyncService {
    constructor() {
        this.queue = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]')
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

        try {
            return await axios(config)
        } catch (error) {
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

        console.log(`Processing sync queue: ${this.queue.length} items`)

        const tempQueue = [...this.queue]
        this.queue = []
        this.saveQueue()

        for (const config of tempQueue) {
            try {
                // Remove internal queue ID before sending
                const { id, timestamp, ...axiosConfig } = config
                await axios(axiosConfig)
                console.log(`Successfully synced: ${config.url}`)
            } catch (error) {
                console.error(`Failed to sync item: ${config.url}`, error)
                // If it's a permanent error (not network), we might want to drop it or notify user
                // For now, if it fails again due to network, re-add to queue
                if (!navigator.onLine || error.code === 'ERR_NETWORK') {
                    this.queue.push(config)
                }
            }
        }
        this.saveQueue()
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
