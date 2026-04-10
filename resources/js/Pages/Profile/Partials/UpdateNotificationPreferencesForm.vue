<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassToggle from '@/Components/UI/GlassToggle.vue'
import GlassSection from '@/Components/UI/GlassSection.vue'
import Checkbox from '@/Components/Form/Checkbox.vue'
import { usePage } from '@inertiajs/vue3'
import { ref, reactive } from 'vue'
import axios from 'axios'
import SyncService from '@/Utils/SyncService'

const props = defineProps({
    preferences: {
        type: Object,
        default: () => ({}),
    },
})

const page = usePage()
const vapidPublicKey = page.props.vapidPublicKey

const pushSupported = 'Notification' in window && 'serviceWorker' in navigator
const pushPermission = ref(typeof Notification !== 'undefined' ? Notification.permission : 'default')
const isSubscribing = ref(false)

const form = reactive({
    preferences: {
        personal_record: props.preferences.personal_record?.is_enabled ?? true,
        training_reminder: props.preferences.training_reminder?.is_enabled ?? true,
    },
    push_preferences: {
        personal_record: props.preferences.personal_record?.is_push_enabled ?? false,
        training_reminder: props.preferences.training_reminder?.is_push_enabled ?? false,
    },
    values: {
        training_reminder: props.preferences.training_reminder?.value ?? 3,
    },
})

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

const enablePush = async () => {
    if (!pushSupported || !vapidPublicKey) {
        console.error('Push notifications not supported or VAPID key missing')
        return
    }

    isSubscribing.value = true
    try {
        const permission = await Notification.requestPermission()
        pushPermission.value = permission

        if (permission === 'granted') {
            // Force registration update to ensure we have the latest SW
            const registration = await navigator.serviceWorker.ready

            // Unsubscribe existing if any to avoid stale subscriptions
            const existingSub = await registration.pushManager.getSubscription()
            if (existingSub) {
                await existingSub.unsubscribe()
            }

            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
            })

            // Save subscription to backend using window.axios for CSRF/auth
            const api = window.axios || axios
            await api.post(route('push-subscriptions.update'), subscription)

            // Enable push toggles by default if just activated
            form.push_preferences.personal_record = true
            form.push_preferences.training_reminder = true

            // Save preferences immediately
            updatePreferences()
        }
    } catch (error) {
        console.error('Push subscription failed:', error)
    } finally {
        isSubscribing.value = false
    }
}

const updatePreferences = () => {
    isSaving.value = true
    errors.value = {}

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
            }
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
                    v-if="pushSupported && pushPermission !== 'granted' && vapidPublicKey"
                    class="border-accent-primary/20 bg-accent-primary/10 mb-6 rounded-xl border p-4"
                >
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h4 class="text-text-main text-sm font-semibold dark:text-white">
                                Activer les notifications Push
                            </h4>
                            <p class="text-text-muted text-xs dark:text-slate-400">
                                Recevez des alertes en temps réel sur votre appareil, même quand l'application est
                                fermée.
                            </p>
                        </div>
                        <GlassButton type="button" size="sm" @click="enablePush" :loading="isSubscribing">
                            Activer
                        </GlassButton>
                    </div>
                </div>

                <div v-else-if="!pushSupported" class="text-text-muted/50 mb-6 text-xs italic">
                    Les notifications push ne sont pas supportées par votre navigateur.
                </div>

                <div v-else-if="pushSupported && !vapidPublicKey" class="mb-6 text-xs text-amber-400/80 italic">
                    Le service de notifications n'est pas encore configuré sur le serveur.
                </div>

                <!-- Personal Record Toggle -->
                <div class="space-y-3">
                    <GlassToggle
                        v-model="form.preferences.personal_record"
                        label="Records Personnels (PR)"
                        description="Être notifié quand vous battez un record."
                    />

                    <div v-if="pushPermission === 'granted'" class="ml-2 flex items-center gap-2">
                        <Checkbox v-model:checked="form.push_preferences.personal_record" />
                        <label class="text-text-muted text-xs dark:text-slate-400">Envoyer aussi en Push</label>
                    </div>
                </div>

                <!-- Training Reminder Toggle -->
                <div class="space-y-4">
                    <GlassToggle
                        v-model="form.preferences.training_reminder"
                        label="Rappels d'Entraînement"
                        description="Rappels automatiques après quelques jours d'inactivité."
                    />

                    <div v-if="pushPermission === 'granted'" class="ml-2 flex items-center gap-2">
                        <Checkbox v-model:checked="form.push_preferences.training_reminder" />
                        <label class="text-text-muted text-xs dark:text-slate-400">Envoyer aussi en Push</label>
                    </div>

                    <Transition
                        enter-active-class="transition duration-200 ease-out"
                        enter-from-class="translate-y-1 opacity-0"
                        enter-to-class="translate-y-0 opacity-100"
                        leave-active-class="transition duration-150 ease-in"
                        leave-from-class="translate-y-0 opacity-100"
                        leave-to-class="translate-y-1 opacity-0"
                    >
                        <div
                            v-if="form.preferences.training_reminder"
                            class="ml-2 border-l-2 border-slate-200 pl-4 dark:border-slate-700"
                        >
                            <GlassInput
                                v-model="form.values.training_reminder"
                                type="number"
                                min="1"
                                max="30"
                                label="Nombre de jours d'inactivité"
                                :error="errors['values.training_reminder']"
                            />
                        </div>
                    </Transition>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <GlassButton :loading="isSaving">Sauvegarder</GlassButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="recentlySuccessful" class="text-sm text-emerald-400">Enregistré.</p>
                </Transition>
            </div>
        </form>
    </GlassSection>
</template>

