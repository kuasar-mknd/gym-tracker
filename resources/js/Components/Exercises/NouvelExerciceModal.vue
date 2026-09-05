<script setup>
/**
 * La modale qui crée un exercice dans la bibliothèque : nom, type, catégorie.
 * Elle porte son formulaire Inertia, vibre selon la réponse du serveur et
 * demande à se fermer une fois l'exercice créé.
 */
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/UI/Modal.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { triggerHaptic } from '@/composables/useHaptics'
import { EXERCISE_CATEGORIES, EXERCISE_TYPES } from '@/Utils/constants'

defineProps({
    show: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

const form = useForm({
    name: '',
    type: 'strength',
    category: '',
})

const submit = () => {
    form.post(route('exercises.store'), {
        onSuccess: () => {
            form.reset()
            emit('close')
            triggerHaptic('success')
        },
        onError: () => triggerHaptic('error'),
    })
}
</script>

<template>
    <Modal :show="show" @close="$emit('close')" max-width="sm" aria-labelledby="new-exercise-title">
        <div class="p-6">
            <h3
                id="new-exercise-title"
                class="font-display text-text-main mb-5 text-xl font-black uppercase"
                dusk="exercise-modal-title"
            >
                Nouvel exercice
            </h3>
            <form @submit.prevent="submit" class="space-y-4">
                <GlassInput
                    v-model="form.name"
                    name="name"
                    dusk="exercise-name-input"
                    label="Nom de l'exercice"
                    placeholder="Ex: Développé couché"
                    :error="form.errors.name"
                />
                <div class="grid grid-cols-2 gap-4">
                    <GlassSelect
                        v-model="form.type"
                        name="type"
                        label="Type"
                        :options="EXERCISE_TYPES"
                        :error="form.errors.type"
                        placeholder=""
                    />
                    <GlassSelect
                        v-model="form.category"
                        name="category"
                        label="Catégorie"
                        :options="EXERCISE_CATEGORIES.map((c) => ({ value: c, label: c }))"
                        empty-label="— Aucune —"
                    />
                </div>
                <GlassButton
                    type="submit"
                    variant="primary"
                    class="w-full"
                    :loading="form.processing"
                    data-testid="submit-exercise-button"
                    dusk="submit-exercise-btn"
                >
                    Créer l'exercice
                </GlassButton>
            </form>
        </div>
    </Modal>
</template>
