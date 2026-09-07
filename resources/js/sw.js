import { clientsClaim } from 'workbox-core'
import { cleanupOutdatedCaches, precacheAndRoute } from 'workbox-precaching'
import { estUnActif, servirDepuisLeCache } from '@/sw/cacheDesActifs'

/**
 * vite.config.js asks for registerType: 'autoUpdate'. With the generateSW
 * strategy the plugin writes these two calls for you; with injectManifest —
 * which this project uses — it does not, and this file never made them. So the
 * config promised automatic updates and the worker did not perform them.
 *
 * A new worker therefore sat in "waiting" until every tab of the app closed.
 * In an installed PWA that effectively never happens: the app is suspended, not
 * closed. Observed on a simulator — a rebuilt page kept serving the old assets
 * through a relaunch, and only reinstalling the app picked up the new build.
 * Users would have sat on a stale version after every deploy, with nothing to
 * tell them.
 *
 * skipWaiting activates the new worker as soon as it is installed;
 * clientsClaim puts the open pages under it without a second reload.
 */
self.skipWaiting()
clientsClaim()

// Drops precaches left by previous versions instead of growing without bound.
cleanupOutdatedCaches()

// Precache assets
precacheAndRoute(self.__WB_MANIFEST)

/**
 * Ce que l'installation n'a pas pris, la première visite le garde.
 *
 * Les morceaux de page ne sont plus préchargés : un compte qui n'ouvre jamais
 * les statistiques n'a pas à télécharger le graphique (#1814). Mais une page
 * ouverte une fois doit se rouvrir sans réseau, donc son morceau entre au cache
 * dès qu'il sert. La décision et la coupe vivent dans un module à part, parce
 * qu'un fichier de worker ne se teste pas.
 */
self.addEventListener('fetch', (event) => {
    if (!estUnActif(event.request, self.location.origin)) {
        return
    }

    event.respondWith(servirDepuisLeCache(event, caches, (requete) => fetch(requete)))
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
