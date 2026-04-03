<script setup>
import Modal from '@/Components/UI/Modal.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'

defineProps({
    show: { type: Boolean, required: true },
})

const emit = defineEmits(['close', 'confirm'])
</script>

<template>
    <Modal :show="show" @close="emit('close')" max-width="sm" aria-labelledby="finish-workout-modal-title">
        <div class="p-6 text-center">
            <h3
                id="finish-workout-modal-title"
                class="font-display text-text-main mb-6 text-xl font-black uppercase italic"
                dusk="finish-workout-modal-title"
            >
                Terminer la séance ?
            </h3>
            <div class="flex gap-3">
                <GlassButton variant="secondary" @click="emit('close')" class="flex-1"> Annuler </GlassButton>
                <GlassButton
                    variant="primary"
                    id="confirm-finish-button"
                    dusk="confirm-finish-button"
                    @click="emit('confirm')"
                    class="flex-1"
                >
                    Confirmer
                </GlassButton>
            </div>
        </div>
    </Modal>
</template>
