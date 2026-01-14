import { precacheAndRoute } from 'workbox-precaching'

// Precache assets
precacheAndRoute(self.__WB_MANIFEST)

// Handle Push notifications
self.addEventListener('push', (event) => {
    if (!(self.Notification && self.Notification.permission === 'granted')) {
        return
    }

    const data = event.data?.json() ?? {}
    const title = data.title || 'Gym Tracker'
    const options = {
        body: data.body || 'Nouvelle notification !',
        icon: data.icon || '/logo.svg',
        badge: '/badge.svg',
        data: data.action_url || '/',
        actions: data.actions || [],
    }

    event.waitUntil(self.registration.showNotification(title, options))
})

// Handle Notification clicks
self.addEventListener('notificationclick', (event) => {
    event.notification.close()

    event.waitUntil(clients.openWindow(event.notification.data))
})
