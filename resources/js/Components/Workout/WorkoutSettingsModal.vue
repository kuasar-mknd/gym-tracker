<script setup>
/* eslint-disable vue/no-mutating-props --
 * The parent owns the Inertia useForm object and hands it down so this component
 * can v-model straight into its fields. Writing to a prop object's properties is
 * legal in Vue — the prop binding itself is never reassigned — and it is the
 * pattern this codebase uses for every shared form. The rule cannot tell that
 * apart from writing through a data prop, which is a real hazard and stays
 * reported everywhere else.
 */
import Modal from '@/Components/UI/Modal.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'

defineProps({
    show: { type: Boolean, required: true },
    form: { type: Object, required: true },
})

const emit = defineEmits(['close', 'submit'])
</script>

<template>
    <Modal :show="show" @close="emit('close')" max-width="lg" aria-labelledby="workout-settings-title">
        <div class="p-6">
            <h2
                id="workout-settings-title"
                class="font-display text-text-main mb-6 text-2xl font-black uppercase italic"
            >
                Paramètres
            </h2>
            <form @submit.prevent="emit('submit')" class="space-y-5">
                <GlassInput v-model="form.name" label="Nom" :error="form.errors.name" dusk="workout-name-input" />
                <GlassInput
                    v-model="form.started_at"
                    type="datetime-local"
                    label="Date"
                    :error="form.errors.started_at"
                />

                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <label for="workout-notes" class="font-display-label text-text-muted block text-sm">
                            Notes
                        </label>
                        <span
                            id="workout-notes-counter"
                            class="text-[10px] font-bold tracking-wider uppercase"
                            :class="form.notes?.length > 1000 ? 'text-accent-danger-deep' : 'text-text-muted/50'"
                        >
                            {{ form.notes?.length || 0 }} / 1000
                        </span>
                    </div>
                    <textarea
                        id="workout-notes"
                        v-model="form.notes"
                        rows="4"
                        maxlength="1000"
                        aria-describedby="workout-notes-counter"
                        class="text-text-main placeholder:text-text-muted/50 border-surface-card/20 bg-surface-card/10 hover:border-surface-card/30 hover:bg-surface-card/15 focus:border-surface-card/50 focus:bg-surface-card/20 w-full rounded-2xl border px-4 py-3 backdrop-blur-md transition-all duration-300 focus:shadow-[0_0_15px_rgb(from_var(--color-surface-card)_r_g_b_/_0.1)] focus:ring-0 focus:outline-none"
                        placeholder="Notes sur la séance..."
                        dusk="workout-notes-input"
                    ></textarea>
                    <p v-if="form.errors.notes" class="text-accent-danger-deep mt-2 text-sm font-medium">
                        {{ form.errors.notes }}
                    </p>
                </div>

                <GlassButton
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    class="w-full"
                    dusk="save-settings-button"
                >
                    Enregistrer
                </GlassButton>
            </form>
        </div>
    </Modal>
</template>
