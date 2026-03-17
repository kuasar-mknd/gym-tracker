<script setup>
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
    <Modal :show="show" @close="emit('close')" max-width="lg">
        <div class="p-6">
            <h2 class="font-display text-text-main mb-6 text-2xl font-black uppercase italic">Paramètres</h2>
            <form @submit.prevent="emit('submit')" class="space-y-5">
                <GlassInput v-model="form.name" label="Nom" dusk="workout-name-input" />
                <GlassInput v-model="form.started_at" type="datetime-local" label="Date" />
                <GlassButton
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    class="w-full"
                    dusk="save-settings-button"
                >
                    Sauvegarder
                </GlassButton>
            </form>
        </div>
    </Modal>
</template>
