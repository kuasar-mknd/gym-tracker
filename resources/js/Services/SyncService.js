import { databaseService } from './database'
import axios from 'axios'
import { router } from '@inertiajs/vue3'

class SyncService {
    constructor() {
        this.isOnline = navigator.onLine
        this.isSyncing = false

        window.addEventListener('online', () => this.handleOnlineStatus(true))
        window.addEventListener('offline', () => this.handleOnlineStatus(false))
    }

    async handleOnlineStatus(online) {
        this.isOnline = online
        if (online) {
            console.log('App is online. Starting sync...')
            await this.sync()
        } else {
            console.log('App is offline.')
        }
    }

    /**
     * Replay all pending items in the sync queue
     */
    async sync() {
        if (this.isSyncing || !this.isOnline) return

        const items = await databaseService.getPendingSyncItems()
        if (items.length === 0) return

        this.isSyncing = true
        console.log(`Syncing ${items.length} items...`)

        for (const item of items) {
            try {
                await databaseService.updateSyncStatus(item.id, 'processing')

                // Replay the request
                const response = await axios({
                    method: item.action,
                    url: item.url,
                    data: item.payload,
                    headers: {
                        'X-Inertia': 'true',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })

                if (response.status >= 200 && response.status < 300) {
                    await databaseService.removeSyncItem(item.id)
                    console.log(`Item ${item.id} synced successfully.`)
                } else {
                    await databaseService.updateSyncStatus(item.id, 'failed')
                    console.error(`Failed to sync item ${item.id}:`, response.statusText)
                }
            } catch (error) {
                await databaseService.updateSyncStatus(item.id, 'failed')
                console.error(`Error syncing item ${item.id}:`, error)

                // If it's a network error, stop syncing for now
                if (!navigator.onLine) {
                    this.isOnline = false
                    break
                }
            }
        }

        this.isSyncing = false

        // Refresh the page data after sync to stay consistent
        router.reload({ preserveScroll: true })
    }

    /**
     * Cache essential data for offline use
     */
    async cacheEssentials(exercises) {
        if (exercises) {
            await databaseService.saveExercises(exercises)
        }
    }
}

export const syncService = new SyncService()
