<script setup>
/**
 * La modale qui crée un exercice dans la bibliothèque : nom, type, catégorie.
 * Elle porte son formulaire Inertia, vibre selon la réponse du serveur et
 * demande à se fermer une fois l'exercice créé.
 */
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/UI/Modal.vue'
import ChampsDUnExercice from '@/Components/Exercises/ChampsDUnExercice.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { triggerHaptic } from '@/composables/useHaptics'

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
            <h3 id="new-exercise-title" class="titre-plat mb-5" dusk="exercise-modal-title">Nouvel exercice</h3>
            <form @submit.prevent="submit" class="space-y-4">
                <ChampsDUnExercice
                    v-model:name="form.name"
                    v-model:type="form.type"
                    v-model:category="form.category"
                    :errors="form.errors"
                    dusk-nom="exercise-name-input"
                />
                <GlassButton
                    type="submit"
                    variant="primary"
                    class="w-full"
                    :loading="form.processing"
                    data-testid="submit-exercise-button"
                    dusk="submit-exercise-btn"
                >
                    Créer
                </GlassButton>
            </form>
        </div>
    </Modal>
</template>
