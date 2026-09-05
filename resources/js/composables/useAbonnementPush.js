import { onMounted, ref } from 'vue'
import axios from 'axios'

/**
 * L'abonnement aux notifications push : ce que le serveur en connait, ce que
 * le navigateur en tient, et l'activation etape par etape avec ses echecs
 * nommes. Le formulaire garde ses preferences ; il ne recoit qu'un rappel une
 * fois l'abonnement enregistre.
 *
 * @param {{
 *   vapidPublicKey: string|null|undefined,
 *   dejaAbonne: boolean,
 *   apresAbonnement: () => void,
 * }} page
 */
export const useAbonnementPush = ({ vapidPublicKey, dejaAbonne, apresAbonnement }) => {
    const pushSupported = 'Notification' in window && 'serviceWorker' in navigator
    const isSubscribing = ref(false)
    const pushError = ref(null)

    /**
     * Étape en cours, affichée sur le bouton et nommée dans le message d'échec :
     * sans elle, une activation qui échoue ne dit pas où, et une étape qui ne
     * répond jamais laisse le bouton tourner sans fin.
     */
    const etapeEnCours = ref(null)

    /**
     * Ni serviceWorker.ready ni pushManager.subscribe() ne promettent de se
     * régler ; passé ce délai on abandonne l'étape et on le dit.
     */
    const DELAI_ETAPE_MS = 20_000

    const avecDelai = (promesse) => {
        let minuteur

        const expiration = new Promise((_, reject) => {
            minuteur = setTimeout(() => reject(new Error('le navigateur n’a pas répondu en 20 s')), DELAI_ETAPE_MS)
        })

        return Promise.race([promesse, expiration]).finally(() => clearTimeout(minuteur))
    }

    const messageDEchec = (err) => {
        const etape = etapeEnCours.value ?? 'activation'
        const statut = err?.response?.status

        if (statut) {
            const detail = err.response.data?.message ?? `HTTP ${statut}`

            return `Étape « ${etape} » refusée par le serveur : ${detail}`
        }

        const detail = err?.message ? ` (${err.message})` : ''
        const conseil =
            etape === 'Abonnement'
                ? ' Sur iPhone, ouvre l’app depuis l’écran d’accueil et vérifie que le réseau laisse passer les notifications.'
                : ''

        return `L’activation a échoué à l’étape « ${etape} »${detail}.${conseil} Réessaie.`
    }

    /**
     * Whether the server holds a subscription for this user — the only thing that
     * decides whether a push can actually be delivered.
     *
     * The interface used to key off Notification.permission instead, which the
     * browser grants *before* the subscription reaches the server. When that
     * request failed, the banner disappeared (its condition wanted permission !==
     * 'granted') and the "Envoyer aussi en Push" checkboxes appeared. The user
     * enabled them, saved, received nothing, and had no way back — the banner that
     * would let them retry was gone.
     */
    const pushRegistered = ref(dejaAbonne)

    /*
     * Le serveur peut garder un abonnement que le navigateur a perdu (PWA
     * réinstallée, données du site effacées) : les envois partent, rien n'arrive,
     * et la bannière qui permettrait de se réabonner reste cachée. On vérifie
     * donc aussi côté navigateur ; en cas de doute, l'état du serveur reste.
     */
    onMounted(async () => {
        if (!pushRegistered.value || !pushSupported) {
            return
        }
        try {
            const registration = await avecDelai(navigator.serviceWorker.ready)
            const abonnement = await avecDelai(registration.pushManager.getSubscription())
            if (!abonnement) {
                pushRegistered.value = false
            }
        } catch {
            // Le worker ne répond pas : on ne contredit pas le serveur.
        }
    })

    const urlBase64ToUint8Array = (base64String) => {
        if (!base64String) return new Uint8Array(0)
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
        const rawData = window.atob(base64)
        const outputArray = new Uint8Array(rawData.length)
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i)
        }
        return outputArray
    }

    /**
     * Tells the server to forget an endpoint the browser has just dropped.
     *
     * Deliberately swallows its own failure: the caller is either subscribing or
     * recovering from a failed subscription, and neither should be derailed by a
     * cleanup request. A missed one leaves the orphan this exists to prevent, which
     * is no worse than the state before.
     */
    const forgetOnServer = async (endpoint) => {
        const api = window.axios || axios

        await api.post(route('push-subscriptions.destroy'), { endpoint }, { timeout: DELAI_ETAPE_MS }).catch(() => {})
    }

    const enablePush = async () => {
        isSubscribing.value = true
        pushError.value = null

        let subscription = null

        try {
            // Pas de délai ici : l'invite du système attend l'utilisateur.
            etapeEnCours.value = 'Permission'
            const permission = await Notification.requestPermission()

            if (permission !== 'granted') {
                // requestPermission() resolves straight to 'denied' once the user has
                // blocked it, so without this the click is another dead end.
                pushError.value =
                    'Ton navigateur a refusé les notifications. Autorise-les dans ses réglages, puis réessaie.'

                return
            }

            etapeEnCours.value = 'Service worker'
            const registration = await avecDelai(navigator.serviceWorker.ready)

            /*
             * Dropped on the server as well as in the browser.
             *
             * Unsubscribing here only tells the browser to stop honouring the
             * endpoint; the row stayed in `push_subscriptions` for good. Every
             * re-activation left another orphan behind, and each one is an endpoint
             * the app keeps pushing to — the provider answers 410 Gone every time,
             * for a subscription nobody can receive.
             */
            etapeEnCours.value = 'Abonnement'
            const existingSub = await avecDelai(registration.pushManager.getSubscription())
            if (existingSub) {
                const staleEndpoint = existingSub.endpoint

                await avecDelai(existingSub.unsubscribe())
                await forgetOnServer(staleEndpoint)
            }

            subscription = await avecDelai(
                registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
                }),
            )

            // Save subscription to backend using window.axios for CSRF/auth
            etapeEnCours.value = 'Enregistrement'
            const api = window.axios || axios
            await api.post(route('push-subscriptions.update'), subscription, { timeout: DELAI_ETAPE_MS })

            pushRegistered.value = true
            apresAbonnement()
        } catch (err) {
            // A browser subscription the server does not hold can never deliver
            // anything, and every later attempt discards it anyway (see the
            // unsubscribe above). Dropping it is the only state that stays true.
            if (subscription) {
                const abandoned = subscription.endpoint

                await subscription.unsubscribe().catch(() => {})
                await forgetOnServer(abandoned)
            }

            pushRegistered.value = false
            pushError.value = messageDEchec(err)
        } finally {
            isSubscribing.value = false
            etapeEnCours.value = null
        }
    }

    return { pushSupported, isSubscribing, pushError, etapeEnCours, pushRegistered, enablePush }
}
