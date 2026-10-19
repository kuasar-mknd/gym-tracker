import { cacheNames, clientsClaim } from 'workbox-core'
import { cleanupOutdatedCaches, getCacheKeyForURL, matchPrecache, precacheAndRoute } from 'workbox-precaching'
import { estUnActif, servirDepuisLeCache } from '@/sw/cacheDesActifs'
import { envelopperLaCoquille } from '@/sw/coquilleServie'
import { basculerLaConstructionPrecedente, mettreDeCoteLaConstructionPrecedente } from '@/sw/constructionPrecedente'
import {
    activerLePrechargementDesNavigations,
    creerLesNavigations,
    estUneNavigation,
    garderLePrechargement,
    URL_DE_LA_PAGE_HORS_LIGNE,
} from '@/sw/navigationsHorsLigne'
import { renouvelerLAbonnement } from '@/sw/renouvellementDAbonnement'

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

/*
 * Le manifeste que la construction injecte. Workbox refuse un worker où son
 * point d'injection apparaît deux fois : il se lit donc une fois, ici, pour le
 * precache et pour la construction précédente.
 */
const MANIFESTE_DU_PRECACHE = self.__WB_MANIFEST

/*
 * La route du precache ne répond qu'à l'adresse exacte de l'un de ses
 * fichiers, fragment ôté (#2048, partie F) : ni adresse « propre » sans
 * `.html`, ni index d'un dossier, ni paramètres de suivi retirés, que Workbox
 * essaie par défaut. L'écouteur des requêtes, posé après elle, sait donc par
 * `getCacheKeyForURL` qu'elle a déjà répondu (`dejaServieParLePrecache`), et
 * la page « hors ligne » ne s'ouvre pas, nue, à une autre adresse que la
 * sienne.
 */
precacheAndRoute(MANIFESTE_DU_PRECACHE, { cleanURLs: false, directoryIndex: null, ignoreURLParametersMatching: [] })

/**
 * Si la route du precache a déjà répondu à cette requête : l'adresse exacte
 * d'un fichier du precache, sans son fragment, que la route ôte aussi. Un
 * second `respondWith` lèverait (`InvalidStateError`).
 *
 * @param {Request} requete
 */
const dejaServieParLePrecache = (requete) => {
    const adresse = new URL(requete.url)

    adresse.hash = ''

    return getCacheKeyForURL(adresse.href) !== undefined
}

/**
 * Un déploiement pendant une séance : la page ouverte reste sur l'ancienne
 * construction, que l'activation retire du precache. L'installation met de
 * côté ce que le nouveau manifeste n'a plus, l'activation le range dans
 * `construction-precedente`, que la route des actifs lit (#2048). Ni l'une ni
 * l'autre ne rejettent : un stockage à court de place ne doit pas faire
 * échouer l'installation, qui laisserait la page sur l'ancien worker. Le
 * détail, et pourquoi pas à l'activation seule, vivent dans le module.
 */
self.addEventListener('install', (event) => {
    event.waitUntil(
        mettreDeCoteLaConstructionPrecedente({
            magasin: caches,
            nomDuPrecache: cacheNames.precache,
            manifeste: MANIFESTE_DU_PRECACHE,
            origine: self.location.origin,
        }),
    )
})

/**
 * Le navigateur lance lui-même la requête d'une navigation pendant que le
 * worker démarre : le passage par le worker ne retarde pas l'ouverture.
 */
self.addEventListener('activate', (event) => {
    event.waitUntil(activerLePrechargementDesNavigations(self.registration))
    event.waitUntil(basculerLaConstructionPrecedente(caches))
})

/**
 * Les navigations : le réseau d'abord ; la page « hors ligne », enveloppée des
 * en-têtes de sécurité de l'application et marquée de sa raison, quand il ne
 * répond pas (#1966), et au bout de huit secondes pour les pages de la salle
 * (#2048, partie F). Le worker ne garde ni ne sert rien d'un compte : la page
 * « hors ligne » est la même pour tous. Le rappel de la connexion sociale, le
 * panneau et les tableaux de bord n'ont ni délai ni page « hors ligne ». Les
 * clients se lisent à l'appel : le worker n'en garde aucun. Le détail vit dans
 * les modules.
 */
const navigations = creerLesNavigations({
    trouverLaPageHorsLigne: async (raison) =>
        envelopperLaCoquille(await matchPrecache(URL_DE_LA_PAGE_HORS_LIGNE).catch(() => undefined), raison),
    allerChercher: (requete) => fetch(requete),
    trouverLeClient: (identifiant) => clients.get(identifiant),
    origine: self.location.origin,
})

/**
 * « Attendre la réponse », depuis la page « hors ligne » servie au délai :
 * l'adresse attend le réseau sans délai pendant une minute.
 */
self.addEventListener('message', (event) => {
    navigations.recevoirUnMessage(event)
})

/**
 * Chaque navigation passe par le worker, même celles qu'il laisse au réseau :
 * la réponse préchargée n'est rendue qu'ainsi, sans seconde requête.
 *
 * Ce que l'installation n'a pas pris, la première visite le garde.
 *
 * Les morceaux de page ne sont plus préchargés : un compte qui n'ouvre jamais
 * les statistiques n'a pas à télécharger le graphique (#1814). Mais une page
 * ouverte une fois doit se rouvrir sans réseau, donc son morceau entre au cache
 * dès qu'il sert. La décision et la coupe vivent dans un module à part, parce
 * qu'un fichier de worker ne se teste pas.
 */
self.addEventListener('fetch', (event) => {
    const navigation = estUneNavigation(event.request)

    if (navigation) {
        garderLePrechargement(event)
    }

    /*
     * Un fichier du precache a déjà sa réponse : la route de Workbox, posée
     * par `precacheAndRoute` avant cet écouteur, l'a donnée, à un actif comme
     * à une navigation vers la page « hors ligne » ou une icône.
     */
    if (dejaServieParLePrecache(event.request)) {
        return
    }

    if (navigation) {
        event.respondWith(navigations.repondre(event))

        return
    }

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
 *
 * L'icône et le badge sont des PNG de public/, que pwa-assets.config.js tire de
 * public/icone.svg (le logo sur sa tuile) et de public/badge.svg (#1850). Le
 * badge, la petite icône de la barre d'état, est blanc sur transparent :
 * Android n'en lit que l'alpha, et un fond plein y ferait un carré.
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
            icon: charge.icon || '/pwa-192x192.png',
            badge: '/badge-96x96.png',
            data: { url: charge.data?.url || '/' },
            actions: charge.actions || [],
        }),
    )
})

/**
 * Un abonnement que le navigateur remplace ou retire se répare sans attendre
 * que l'utilisateur repasse par son profil (#1847).
 *
 * Le worker prévient lui-même le serveur, SANS jeton CSRF : il n'a pas de
 * document où le lire, et c'est l'en-tête `Sec-Fetch-Site: same-origin`, posé
 * par le navigateur, que PreventRequestForgery accepte à sa place — la même
 * vérification que pour toutes les routes web, pas une exemption. Le détail,
 * et ce qui reste au rapprochement fait à l'ouverture de l'application (iOS
 * n'émet pas cet évènement selon MDN), vivent dans le module.
 */
self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil(renouvelerLAbonnement(event, { pushManager: self.registration.pushManager }))
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
