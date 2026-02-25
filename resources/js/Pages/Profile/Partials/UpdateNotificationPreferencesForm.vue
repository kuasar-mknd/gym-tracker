<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
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
    if (!pushSupported) return

    isSubscribing.value = true
    try {
        const permission = await Notification.requestPermission()
        pushPermission.value = permission

        if (permission === 'granted') {
            const registration = await navigator.serviceWorker.ready
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
            })

            // Save subscription to backend
            await axios.post(route('push-subscriptions.update'), subscription)

            // Enable push toggles by default if just activated
            form.push_preferences.personal_record = true
            form.push_preferences.training_reminder = true
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
    <section>
        <header>
            <h2 class="text-text-main text-lg font-medium">Préférences de Notification</h2>
            <p class="text-text-muted mt-1 text-sm">Choisis comment tu souhaites être informé de tes progrès.</p>
        </header>

        <form @submit.prevent="updatePreferences" class="mt-6 space-y-6">
            <div class="space-y-4">
                <!-- Web Push Banner -->
                <div
                    v-if="pushSupported && pushPermission !== 'granted'"
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
                        <GlassButton type="button" size="sm" @click="enablePush" :loading="isSubscribing">
                            Activer
                        </GlassButton>
                    </div>
                </div>

                <div v-else-if="!pushSupported" class="text-text-muted/50 mb-6 text-xs italic">
                    Les notifications push ne sont pas supportées par votre navigateur.
                </div>

                <!-- Personal Record Toggle -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="text-text-main text-sm font-medium">Records Personnels (PR)</h4>
                            <p class="text-text-muted text-xs">Être notifié quand vous battez un record.</p>
                        </div>
                        <label class="relative inline-flex cursor-pointer items-center">
                            <input type="checkbox" v-model="form.preferences.personal_record" class="peer sr-only" />
                            <div
                                class="peer peer-checked:bg-accent-primary h-6 w-11 rounded-full bg-white/10 after:absolute after:top-[2px] after:left-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-all after:content-[''] peer-checked:after:translate-x-full"
                            ></div>
                        </label>
                    </div>

                    <div v-if="pushPermission === 'granted'" class="ml-2 flex items-center gap-2">
                        <input
                            type="checkbox"
                            v-model="form.push_preferences.personal_record"
                            id="push_pr"
                            class="text-accent-primary focus:ring-accent-primary rounded border-white/10 bg-white/5"
                        />
                        <label for="push_pr" class="text-text-muted text-xs">Envoyer aussi en Push</label>
                    </div>
                </div>

                <!-- Training Reminder Toggle -->
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="text-text-main text-sm font-medium">Rappels d'Entraînement</h4>
                            <p class="text-text-muted text-xs">
                                Rappels automatiques après quelques jours d'inactivité.
                            </p>
                        </div>
                        <label class="relative inline-flex cursor-pointer items-center">
                            <input type="checkbox" v-model="form.preferences.training_reminder" class="peer sr-only" />
                            <div
                                class="peer peer-checked:bg-accent-primary h-6 w-11 rounded-full bg-white/10 after:absolute after:top-[2px] after:left-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-all after:content-[''] peer-checked:after:translate-x-full"
                            ></div>
                        </label>
                    </div>

                    <div v-if="pushPermission === 'granted'" class="ml-2 flex items-center gap-2">
                        <input
                            type="checkbox"
                            v-model="form.push_preferences.training_reminder"
                            id="push_reminder"
                            class="text-accent-primary focus:ring-accent-primary rounded border-white/10 bg-white/5"
                        />
                        <label for="push_reminder" class="text-text-muted text-xs">Envoyer aussi en Push</label>
                    </div>

                    <Transition
                        enter-active-class="transition duration-200 ease-out"
                        enter-from-class="translate-y-1 opacity-0"
                        enter-to-class="translate-y-0 opacity-100"
                        leave-active-class="transition duration-150 ease-in"
                        leave-from-class="translate-y-0 opacity-100"
                        leave-to-class="translate-y-1 opacity-0"
                    >
                        <div v-if="form.preferences.training_reminder" class="ml-2 border-l-2 border-slate-200 pl-4">
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
    </section>
</template>
