<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { useForm, usePage } from '@inertiajs/vue3'

const props = defineProps({
    preferences: {
        type: Object,
        default: () => ({}),
    },
})

const form = useForm({
    preferences: {
        personal_record: props.preferences.personal_record?.is_enabled ?? true,
        training_reminder: props.preferences.training_reminder?.is_enabled ?? true,
    },
    values: {
        training_reminder: props.preferences.training_reminder?.value ?? 3,
    },
})

const updatePreferences = () => {
    form.patch(route('profile.preferences.update'), {
        preserveScroll: true,
        onSuccess: () => {
            // Success logic if needed
        },
    })
}
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-white">Préférences de Notification</h2>
            <p class="mt-1 text-sm text-white/60">Choisis comment tu souhaites être informé de tes progrès.</p>
        </header>

        <form @submit.prevent="updatePreferences" class="mt-6 space-y-6">
            <div class="space-y-4">
                <!-- Personal Record Toggle -->
                <div class="flex items-center justify-between">
                    <div>
                        <h4 class="text-sm font-medium text-white">Records Personnels (PR)</h4>
                        <p class="text-xs text-white/50">Être notifié quand vous battez un record.</p>
                    </div>
                    <label class="relative inline-flex cursor-pointer items-center">
                        <input type="checkbox" v-model="form.preferences.personal_record" class="peer sr-only" />
                        <div
                            class="peer h-6 w-11 rounded-full bg-white/10 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-all after:content-[''] peer-checked:bg-accent-primary peer-checked:after:translate-x-full"
                        ></div>
                    </label>
                </div>

                <!-- Training Reminder Toggle -->
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="text-sm font-medium text-white">Rappels d'Entraînement</h4>
                            <p class="text-xs text-white/50">Rappels automatiques après quelques jours d'inactivité.</p>
                        </div>
                        <label class="relative inline-flex cursor-pointer items-center">
                            <input type="checkbox" v-model="form.preferences.training_reminder" class="peer sr-only" />
                            <div
                                class="peer h-6 w-11 rounded-full bg-white/10 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-all after:content-[''] peer-checked:bg-accent-primary peer-checked:after:translate-x-full"
                            ></div>
                        </label>
                    </div>

                    <Transition
                        enter-active-class="transition duration-200 ease-out"
                        enter-from-class="translate-y-1 opacity-0"
                        enter-to-class="translate-y-0 opacity-100"
                        leave-active-class="transition duration-150 ease-in"
                        leave-from-class="translate-y-0 opacity-100"
                        leave-to-class="translate-y-1 opacity-0"
                    >
                        <div v-if="form.preferences.training_reminder" class="ml-2 border-l-2 border-white/10 pl-4">
                            <GlassInput
                                v-model="form.values.training_reminder"
                                type="number"
                                min="1"
                                max="30"
                                label="Nombre de jours d'inactivité"
                                :error="form.errors['values.training_reminder']"
                            />
                        </div>
                    </Transition>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <GlassButton :disabled="form.processing">Sauvegarder</GlassButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-sm text-emerald-400">Enregistré.</p>
                </Transition>
            </div>
        </form>
    </section>
</template>
