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

/**
 * Chaque push reçu affiche quelque chose. Aucune exception.
 *
 * C'est la contrepartie de `userVisibleOnly: true`, la seule forme d'abonnement
 * que les navigateurs acceptent : recevoir un push sans rien montrer est une
 * rupture de contrat, et WebKit y répond en RÉVOQUANT l'abonnement au bout de
 * quelques manquements. L'appareil cesse alors de recevoir, définitivement,
 * sans que rien côté serveur ne l'apprenne.
 *
 * Ce gestionnaire sortait par deux chemins muets. Une garde de permission
 * d'abord, qui interrogeait `self.Notification` — l'interface globale dans la
 * PORTÉE DU WORKER, qui n'est pas celle de la page. WebKit ne l'y expose pas :
 * `api.Notification.worker_support` vaut `false` pour Safari dans les données de
 * compatibilité de MDN, et iOS en est le miroir. Sur iPhone, la garde était donc
 * fausse à CHAQUE push et rien ne s'affichait, jamais. Elle ne protégeait
 * d'ailleurs de rien : la spécification Push interdit de délivrer un push à un
 * abonnement dont la permission a été retirée, si bien que la condition était
 * soit vraie, soit posée sur un événement impossible. Une charge utile illisible
 * ensuite : `json()` lève sur un corps qui n'est pas du JSON, et l'exception
 * traversait `waitUntil`.
 */
self.addEventListener('push', (event) => {
    let charge = {}

    try {
        charge = event.data?.json() ?? {}
    } catch {
        // Un message qu'on ne sait pas lire reste un message à annoncer.
    }

    event.waitUntil(
        self.registration.showNotification(charge.title || 'Gym Tracker', {
            body: charge.body || 'Nouvelle notification !',
            icon: charge.icon || '/logo.svg',
            badge: '/badge.svg',
            data: { url: charge.data?.url || '/' },
            actions: charge.actions || [],
        }),
    )
})

/**
 * Reprendre la fenêtre ouverte, plutôt que d'en empiler une seconde.
 *
 * `openWindow` seul ouvre un second exemplaire de l'application par-dessus celui
 * que l'utilisateur avait déjà, ce qui se voit surtout en installé : la séance
 * en cours disparaît derrière sa propre copie.
 *
 * La destination voyage dans `data.url`. Elle se lisait auparavant dans un
 * champ `action_url` qu'aucune notification n'a jamais porté — les trois
 * classes d'envoi mettent leur destination ailleurs — donc chaque clic ouvrait
 * l'accueil. `event.action` la prend de vitesse quand l'utilisateur a touché un
 * bouton d'action ; iOS ne les affiche pas, d'où le repli.
 */
self.addEventListener('notificationclick', (event) => {
    event.notification.close()

    const destination = event.action || event.notification.data?.url || '/'

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((fenetres) => {
            const ouverte = fenetres.find((fenetre) => 'focus' in fenetre)

            if (!ouverte) {
                return clients.openWindow(destination)
            }

            // `navigate()` n'est offert qu'aux fenêtres que ce worker contrôle,
            // et refuse une origine étrangère : son échec ne doit pas empêcher
            // la mise au premier plan, qui est le geste attendu.
            return Promise.resolve(ouverte.navigate?.(destination))
                .catch(() => undefined)
                .then(() => ouverte.focus())
        }),
    )
})
