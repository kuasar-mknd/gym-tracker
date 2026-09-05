<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassToggle from '@/Components/UI/GlassToggle.vue'
import GlassSection from '@/Components/UI/GlassSection.vue'
import Checkbox from '@/Components/Form/Checkbox.vue'
import { usePage } from '@inertiajs/vue3'
import { ref, reactive, onUnmounted } from 'vue'
import SyncService from '@/Utils/SyncService'
import { useAbonnementPush } from '@/composables/useAbonnementPush'

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

const vapidPublicKey = usePage().props.vapidPublicKey

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

let minuteurDeSucces

const marquerEnregistre = () => {
    recentlySuccessful.value = true
    clearTimeout(minuteurDeSucces)
    minuteurDeSucces = setTimeout(() => {
        recentlySuccessful.value = false
    }, 2000)
}

onUnmounted(() => clearTimeout(minuteurDeSucces))

const { pushSupported, isSubscribing, pushError, etapeEnCours, pushRegistered, enablePush } = useAbonnementPush({
    vapidPublicKey,
    dejaAbonne: props.hasPushSubscription,
    apresAbonnement: () => {
        // Les envois push s'activent avec l'abonnement, et partent tout de suite.
        form.push_preferences.personal_record = true
        form.push_preferences.training_reminder = true
        updatePreferences()
    },
})

const updatePreferences = () => {
    isSaving.value = true
    errors.value = {}
    pushError.value = null

    SyncService.patch(route('profile.preferences.update'), form)
        .then(marquerEnregistre)
        .catch((err) => {
            if (err.isOffline) {
                marquerEnregistre()

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
