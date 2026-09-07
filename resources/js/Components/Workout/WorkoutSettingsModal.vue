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
import GlassTextarea from '@/Components/UI/GlassTextarea.vue'

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
                    <GlassTextarea
                        v-model="form.notes"
                        label="Notes"
                        :rows="4"
                        :maxlength="1000"
                        placeholder="Notes sur la séance..."
                        dusk="workout-notes-input"
                        :error="form.errors.notes"
                    />
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
