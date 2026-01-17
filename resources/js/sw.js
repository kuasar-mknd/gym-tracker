import { precacheAndRoute, cleanupOutdatedCaches, matchPrecache } from 'workbox-precaching'
import { registerRoute, NavigationRoute, setDefaultHandler } from 'workbox-routing'
import { StaleWhileRevalidate, CacheFirst, NetworkFirst } from 'workbox-strategies'
import { ExpirationPlugin } from 'workbox-expiration'
import { CacheableResponsePlugin } from 'workbox-cacheable-response'

// Cleanup old caches
cleanupOutdatedCaches()

// Precache assets (from Vite build)
precacheAndRoute(self.__WB_MANIFEST)

// Offline fallback page URL
const OFFLINE_URL = '/offline.html'

// Cache the offline page during install
self.addEventListener('install', async (event) => {
    event.waitUntil(
        caches.open('offline-fallback').then((cache) => {
            return cache.add(OFFLINE_URL)
        }),
    )
    self.skipWaiting()
})

self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim())
})

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

// Cache CSS and JS with StaleWhileRevalidate
registerRoute(
    ({ request }) => request.destination === 'style' || request.destination === 'script',
    new StaleWhileRevalidate({
        cacheName: 'static-resources',
    }),
)

// Cache Inertia JSON responses with NetworkFirst (try network, fallback to cache)
registerRoute(
    ({ request }) => request.headers.get('X-Inertia') === 'true',
    new NetworkFirst({
        cacheName: 'inertia-data',
        networkTimeoutSeconds: 5, // Fallback to cache after 5 seconds
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

// Handle navigation requests with NetworkFirst and offline fallback
registerRoute(
    ({ request }) => request.mode === 'navigate',
    new NetworkFirst({
        cacheName: 'navigations',
        networkTimeoutSeconds: 5,
        plugins: [
            new CacheableResponsePlugin({
                statuses: [0, 200],
            }),
        ],
    }),
)

// Fallback for failed navigation requests
setDefaultHandler(new NetworkFirst())

// Handle failed navigations with offline page
self.addEventListener('fetch', (event) => {
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(async () => {
                const cache = await caches.open('offline-fallback')
                const cachedResponse = await cache.match(OFFLINE_URL)
                return cachedResponse || new Response('Offline', { status: 503 })
            }),
        )
    }
})

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
