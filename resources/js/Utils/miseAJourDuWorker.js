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
 */

/** La vérification d'une nouvelle version : le navigateur ne la fait que sur une navigation. */
export const UNE_HEURE_MS = 60 * 60 * 1000

/** Vraie quand un nouveau worker a pris la main : la page ouverte tourne encore sur l'ancienne version. */
export const nouvelleVersionPrete = ref(false)

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
 *   fenetre?: Window,
 * }} options
 */
export const inscrireLeWorker = ({ registerSW, routeur, fenetre = window }) => {
    routeur.on('before', (evenement) => {
        const visite = evenement.detail.visit

        if (!nouvelleVersionPrete.value || !visiteQuiQuitteLaPage(visite)) {
            return undefined
        }

        if (visite.replace) {
            fenetre.location.replace(visite.url.href)
        } else {
            fenetre.location.assign(visite.url.href)
        }

        // `false` annule la visite d'Inertia : la navigation complète la remplace.
        return false
    })

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
