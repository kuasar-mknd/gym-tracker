import { ref } from 'vue'

/**
 * Une nouvelle version ne recharge plus une page ouverte (#1967).
 *
 * `vite.config.js` demande `registerType: 'autoUpdate'`, et le worker appelle
 * `skipWaiting()` : un déploiement s'active dès que la vérification horaire le
 * trouve. Dans ce mode, le `registerSW` de vite-plugin-pwa recharge la page à
 * l'activation (`window.location.reload()`), sauf si `onNeedReload` lui est
 * passé. Le `onNeedRefresh` qu'on lui passait ne sert qu'au mode « prompt » :
 * il n'était jamais appelé, et chaque déploiement rechargeait les pages
 * ouvertes à n'importe quel moment, en pleine séance comprise. Le minuteur de
 * repos disparaissait, celui d'intervalles repartait de zéro, et le texte en
 * cours dans une modale était perdu.
 *
 * `onNeedReload` ne recharge donc rien : il note que la nouvelle version est
 * prête. Un bandeau propose de recharger, et la prochaine navigation, qui
 * quitte la page de toute façon, se fait en entier, sur la nouvelle version.
 * Rien d'autre n'est rechargé sans un geste : ni une écriture, ni une visite
 * qui garde l'état de la page (filtres, rechargement partiel, interrogation
 * périodique), ni le retour au premier plan, où une saisie peut attendre.
 *
 * La navigation complète ne part que si le serveur répond. La visite reste une
 * visite d'Inertia, qui annonce une version d'actifs périmée, ce que la page
 * est : le serveur répond alors 409 avec l'adresse de la page, et c'est
 * Inertia qui fait la navigation complète, comme à tout changement de version.
 * Sans réseau, la visite échoue comme avant, et la page reste : une navigation
 * complète lancée d'emblée aurait remplacé la séance en cours et ses
 * minuteurs par la page « hors ligne » du worker (#1966), sans retour possible.
 * Le serveur ne repère pas seul la nouvelle version : sa version d'actifs se
 * tire d'`ASSET_URL` en production, la même d'un déploiement à l'autre.
 */

/** La vérification d'une nouvelle version : le navigateur ne la fait que sur une navigation. */
export const UNE_HEURE_MS = 60 * 60 * 1000

/** Vraie quand un nouveau worker a pris la main : la page ouverte tourne encore sur l'ancienne version. */
export const nouvelleVersionPrete = ref(false)

/**
 * La marque d'une visite à faire en entier, posée par `before` et retirée
 * avant l'envoi : elle ne part jamais au serveur.
 */
export const MARQUE_DE_LA_VISITE_EN_ENTIER = 'X-Gym-Visite-En-Entier'

/**
 * La version d'actifs qu'annonce une page qui se sait périmée. Aucune version
 * du serveur ne s'écrit ainsi : il répond toujours par un changement de version.
 */
export const VERSION_PERIMEE = 'perimee'

/**
 * Si la visite quitte la page sans rien en garder, et peut donc se faire en
 * entier sans coûter davantage qu'elle-même.
 *
 * @param {{ method: string, prefetch?: boolean, async?: boolean, preserveState: unknown, only: string[], except: string[], reset?: string[] }} visite
 */
export const visiteQuiQuitteLaPage = (visite) =>
    String(visite.method).toLowerCase() === 'get' &&
    !visite.prefetch &&
    !visite.async &&
    visite.preserveState === false &&
    visite.only.length === 0 &&
    visite.except.length === 0 &&
    (visite.reset ?? []).length === 0

/**
 * Remplace, sur la requête d'une visite marquée, la marque par la version
 * périmée. Les autres requêtes passent telles quelles.
 *
 * @param {{ headers?: Record<string, unknown> }} requete
 */
export const annoncerLaVersionPerimee = (requete) => {
    if (!requete.headers?.[MARQUE_DE_LA_VISITE_EN_ENTIER]) {
        return requete
    }

    const { [MARQUE_DE_LA_VISITE_EN_ENTIER]: _marque, ...entetes } = requete.headers

    return { ...requete, headers: { ...entetes, 'X-Inertia-Version': VERSION_PERIMEE } }
}

/**
 * Recharge tout de suite, sur le geste de l'utilisateur.
 *
 * @param {Window} fenetre
 */
export const rechargerMaintenant = (fenetre = window) => {
    fenetre.location.reload()
}

/**
 * Inscrit le worker, et fait prendre la nouvelle version à la prochaine
 * navigation plutôt qu'à son arrivée.
 *
 * @param {{
 *   registerSW: (options: object) => unknown,
 *   routeur: { on: (evenement: string, rappel: (evenement: CustomEvent) => unknown) => unknown },
 *   http: { onRequest: (gestionnaire: (requete: object) => object) => unknown },
 * }} options
 */
export const inscrireLeWorker = ({ registerSW, routeur, http }) => {
    routeur.on('before', (evenement) => {
        const visite = evenement.detail.visit

        if (nouvelleVersionPrete.value && visiteQuiQuitteLaPage(visite)) {
            visite.headers = { ...visite.headers, [MARQUE_DE_LA_VISITE_EN_ENTIER]: '1' }
        }
    })

    http.onRequest(annoncerLaVersionPerimee)

    return registerSW({
        immediate: true,
        onRegisteredSW(_url, inscription) {
            // Le navigateur ne cherche un nouveau worker que sur une
            // navigation. Une PWA installée est suspendue, pas fermée : sans
            // ceci elle peut ne jamais regarder.
            if (inscription) {
                setInterval(() => inscription.update(), UNE_HEURE_MS)
            }
        },
        onNeedReload() {
            nouvelleVersionPrete.value = true
        },
    })
}
