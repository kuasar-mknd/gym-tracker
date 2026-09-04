<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassToggle from '@/Components/UI/GlassToggle.vue'
import GlassSection from '@/Components/UI/GlassSection.vue'
import Checkbox from '@/Components/Form/Checkbox.vue'
import { usePage } from '@inertiajs/vue3'
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import SyncService from '@/Utils/SyncService'

const props = defineProps({
    preferences: {
        type: Object,
        default: () => ({}),
    },
    hasPushSubscription: {
        type: Boolean,
        default: false,
    },
})

const page = usePage()
const vapidPublicKey = page.props.vapidPublicKey

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
const pushRegistered = ref(props.hasPushSubscription)

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

const form = reactive({
    preferences: {
        personal_record: props.preferences.personal_record?.is_enabled ?? true,
        training_reminder: props.preferences.training_reminder?.is_enabled ?? true,
    },
    push_preferences: {
        personal_record: props.preferences.personal_record?.is_push_enabled ?? false,
        training_reminder: props.preferences.training_reminder?.is_push_enabled ?? false,
    },
    days: {
        training_reminder: [...(props.preferences.training_reminder?.days ?? [1, 2, 3, 4, 5, 6, 7])],
    },
})

/**
 * Numéros ISO : 1 = lundi, 7 = dimanche, comme le serveur les lit.
 */
const JOURS = [
    { iso: 1, libelle: 'Lun' },
    { iso: 2, libelle: 'Mar' },
    { iso: 3, libelle: 'Mer' },
    { iso: 4, libelle: 'Jeu' },
    { iso: 5, libelle: 'Ven' },
    { iso: 6, libelle: 'Sam' },
    { iso: 7, libelle: 'Dim' },
]

const basculerLeJour = (iso, coche) => {
    const jours = form.days.training_reminder.filter((jour) => jour !== iso)
    if (coche) jours.push(iso)
    form.days.training_reminder = jours.sort((a, b) => a - b)
}

const isSaving = ref(false)
const recentlySuccessful = ref(false)
const errors = ref({})

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

        // Enable push toggles by default if just activated
        form.push_preferences.personal_record = true
        form.push_preferences.training_reminder = true

        // Save preferences immediately
        updatePreferences()
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

const updatePreferences = () => {
    isSaving.value = true
    errors.value = {}
    pushError.value = null

    SyncService.patch(route('profile.preferences.update'), form)
        .then(() => {
            recentlySuccessful.value = true
            setTimeout(() => {
                recentlySuccessful.value = false
            }, 2000)
        })
        .catch((err) => {
            if (err.isOffline) {
                recentlySuccessful.value = true
                setTimeout(() => {
                    recentlySuccessful.value = false
                }, 2000)
                return
            }
            if (err.response?.status === 422) {
                errors.value = err.response.data.errors

                return
            }

            // 419 on an expired CSRF token, 403, 500, a dropped connection. This
            // branch did not exist, so the toggles kept values the server never
            // stored and nothing said so.
            pushError.value = "Tes préférences n'ont pas pu être enregistrées. Réessaie."
        })
        .finally(() => {
            isSaving.value = false
        })
}
</script>

<template>
    <GlassSection
        title="Préférences de Notification"
        description="Choisis comment tu souhaites être informé de tes progrès."
    >
        <form @submit.prevent="updatePreferences" class="mt-6 space-y-6">
            <div class="space-y-4">
                <!-- Web Push Banner -->
                <div
                    v-if="pushSupported && !pushRegistered && vapidPublicKey"
                    class="border-accent-primary/20 bg-accent-primary/10 mb-6 rounded-xl border p-4"
                >
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h4 class="text-text-main text-sm font-semibold">Activer les notifications Push</h4>
                            <p class="text-text-muted text-xs">
                                Recevez des alertes en temps réel sur votre appareil, même quand l'application est
                                fermée.
                            </p>
                        </div>
                        <GlassButton
                            type="button"
                            size="sm"
                            dusk="enable-push"
                            @click="enablePush"
                            :loading="isSubscribing"
                        >
                            {{ isSubscribing ? `${etapeEnCours}…` : 'Activer' }}
                        </GlassButton>
                    </div>
                </div>

                <div v-else-if="!pushSupported" class="text-text-muted/50 mb-6 text-xs italic">
                    Les notifications push ne sont pas supportées par votre navigateur.
                </div>

                <div v-else-if="pushSupported && !vapidPublicKey" class="text-accent-warning-deep mb-6 text-xs italic">
                    Le service de notifications n'est pas encore configuré sur le serveur.
                </div>

                <!-- Personal Record Toggle -->
                <div class="space-y-3">
                    <GlassToggle
                        v-model="form.preferences.personal_record"
                        label="Records Personnels (PR)"
                        description="Être notifié quand vous battez un record."
                    />

                    <div v-if="pushRegistered" class="ml-2 flex items-center gap-2">
                        <Checkbox v-model:checked="form.push_preferences.personal_record" />
                        <label class="text-text-muted text-xs">Envoyer aussi en Push</label>
                    </div>
                </div>

                <!-- Training Reminder Toggle -->
                <div class="space-y-4">
                    <GlassToggle
                        v-model="form.preferences.training_reminder"
                        label="Rappels d'Entraînement"
                        description="Un rappel à 18 h, les jours choisis, si aucune séance n'a été faite dans la journée."
                    />

                    <div v-if="pushRegistered" class="ml-2 flex items-center gap-2">
                        <Checkbox v-model:checked="form.push_preferences.training_reminder" />
                        <label class="text-text-muted text-xs">Envoyer aussi en Push</label>
                    </div>

                    <Transition
                        enter-active-class="transition duration-200 ease-out"
                        enter-from-class="translate-y-1 opacity-0"
                        enter-to-class="translate-y-0 opacity-100"
                        leave-active-class="transition duration-150 ease-in"
                        leave-from-class="translate-y-0 opacity-100"
                        leave-to-class="translate-y-1 opacity-0"
                    >
                        <div v-if="form.preferences.training_reminder" class="border-border ml-2 border-l-2 pl-4">
                            <p class="text-text-muted mb-2 text-xs font-semibold tracking-wider uppercase">
                                Jours de rappel
                            </p>
                            <div class="flex flex-wrap gap-x-4 gap-y-1" dusk="reminder-days">
                                <label
                                    v-for="jour in JOURS"
                                    :key="jour.iso"
                                    class="min-h-touch flex items-center gap-2 text-sm"
                                    :dusk="`reminder-day-${jour.iso}`"
                                >
                                    <Checkbox
                                        :checked="form.days.training_reminder.includes(jour.iso)"
                                        @update:checked="basculerLeJour(jour.iso, $event)"
                                    />
                                    <span>{{ jour.libelle }}</span>
                                </label>
                            </div>
                            <p
                                v-if="errors['days.training_reminder']"
                                class="text-accent-danger-deep mt-1 text-xs"
                                role="alert"
                            >
                                {{ errors['days.training_reminder'] }}
                            </p>
                        </div>
                    </Transition>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <!-- GlassButton defaults to type="button", so without this the click
                     never fires the form's @submit and nothing was ever saved. -->
                <GlassButton variant="primary" type="submit" dusk="save-notification-preferences" :loading="isSaving">
                    Enregistrer
                </GlassButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="recentlySuccessful" class="text-accent-state-deep text-sm">Enregistré.</p>
                </Transition>
            </div>

            <p
                v-if="pushError"
                class="text-accent-danger-deep text-sm font-bold"
                role="alert"
                dusk="notification-push-error"
            >
                {{ pushError }}
            </p>
        </form>
    </GlassSection>
</template>
