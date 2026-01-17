import { router } from '@inertiajs/vue3'
import { databaseService } from '../Services/database'
import { syncService } from '../Services/SyncService'

export default {
    install(app) {
        // Intercept all Inertia requests
        router.on('start', async (event) => {
            const { detail } = event
            const { method, url, data, options } = detail.visit

            // We only care about mutations when offline
            if (!navigator.onLine && ['post', 'put', 'patch', 'delete'].includes(method)) {
                console.warn('Offline mutation detected, queueing...', { method, url, data })

                // Add to sync queue
                await databaseService.addToSyncQueue(method.toUpperCase(), url, data)

                // Prevent the actual request from reaching the network/sw
                event.preventDefault()

                // Trigger optimistic success if possible (basic implementation)
                if (options.onSuccess) {
                    options.onSuccess({ props: {} }) // Mocked response
                }

                // We should also update local state here if possible,
                // but that's complex without a real state manager (Pinia/Vuex).
                // For now, we rely on the UI being responsive or reloading from cache.

                return false
            }
        })

        // On successful response, update local cache
        router.on('success', (event) => {
            const { props } = event.detail.page
            if (props.exercises) {
                syncService.cacheEssentials(props.exercises)
            }
            // Add other essential data to cache here if needed
        })
    },
}
