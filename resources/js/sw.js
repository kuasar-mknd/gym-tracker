import { precacheAndRoute, cleanupOutdatedCaches } from 'workbox-precaching'
import { registerRoute, NavigationRoute } from 'workbox-routing'
import { StaleWhileRevalidate, CacheFirst, NetworkOnly } from 'workbox-strategies'
import { ExpirationPlugin } from 'workbox-expiration'
import { CacheableResponsePlugin } from 'workbox-cacheable-response'

// Cleanup old caches
cleanupOutdatedCaches()

// Precache assets (from Vite build)
precacheAndRoute(self.__WB_MANIFEST)

// Cache images
registerRoute(
    ({ request }) => request.destination === 'image',
    new CacheFirst({
        cacheName: 'images',
        plugins: [
            new ExpirationPlugin({
                maxEntries: 60,
                maxAgeSeconds: 30 * 24 * 60 * 60, // 30 Days
            }),
        ],
    }),
)

// Cache fonts
registerRoute(
    ({ request }) => request.destination === 'font',
    new CacheFirst({
        cacheName: 'fonts',
        plugins: [
            new ExpirationPlugin({
                maxEntries: 20,
                maxAgeSeconds: 365 * 24 * 60 * 60, // Long term
            }),
        ],
    }),
)

// Cache Inertia JSON responses
registerRoute(
    ({ request }) => request.headers.get('X-Inertia') === 'true',
    new StaleWhileRevalidate({
        cacheName: 'inertia-data',
        plugins: [
            new CacheableResponsePlugin({
                statuses: [0, 200],
            }),
            new ExpirationPlugin({
                maxEntries: 50,
                maxAgeSeconds: 24 * 60 * 60, // 24 hours
            }),
        ],
    }),
)

// Handle navigation requests (Inertia app shell)
// When offline, fallback to the root '/' which is precached
const navigationRoute = new NavigationRoute(
    new StaleWhileRevalidate({
        cacheName: 'navigations',
    }),
    {
        // Don't intercept API calls or other non-navigation routes
        denylist: [/^\/api/, /^\/login/, /^\/register/, /^\/logout/],
    },
)
registerRoute(navigationRoute)

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

// Skip waiting to activate new service worker immediately
self.addEventListener('install', () => {
    self.skipWaiting()
})

self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim())
})
