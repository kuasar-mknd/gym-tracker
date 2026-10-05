<script setup>
import { computed } from 'vue'
import Modal from '@/Components/UI/Modal.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'

const props = defineProps({
    show: { type: Boolean, required: true },
    /** Les modifications restées en file à la dernière tentative : la séance ne se ferme pas sans elles (#1961). */
    enAttente: { type: Number, default: 0 },
    /** La clôture attend que les modifications partent : « Confirmer » le montre, et n'est pas rappuyable. */
    enCours: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'confirm'])

const avisDAttente = computed(() =>
    props.enAttente > 1
        ? `${props.enAttente} modifications attendent encore d’être envoyées. La séance reste ouverte : réessaie quand le réseau sera revenu.`
        : 'Une modification attend encore d’être envoyée. La séance reste ouverte : réessaie quand le réseau sera revenu.',
)
</script>

<template>
    <Modal :show="show" @close="emit('close')" max-width="sm" aria-labelledby="finish-workout-modal-title">
        <div class="p-6 text-center">
            <h3 id="finish-workout-modal-title" class="titre-carte mb-6" dusk="finish-workout-modal-title">
                Terminer la séance ?
            </h3>
            <p
                v-if="enCours"
                role="status"
                class="text-text-muted mb-6 text-sm font-medium"
                dusk="finish-workout-sending"
            >
                Envoi des modifications en attente…
            </p>
            <p
                v-else-if="enAttente > 0"
                role="status"
                class="bg-accent-danger/10 text-accent-danger-deep mb-6 rounded-xl p-3 text-sm font-bold"
                dusk="finish-workout-pending"
            >
                {{ avisDAttente }}
            </p>
            <div class="flex gap-3">
                <GlassButton variant="secondary" @click="emit('close')" class="flex-1"> Annuler </GlassButton>
                <GlassButton
                    variant="primary"
                    id="confirm-finish-button"
                    dusk="confirm-finish-button"
                    :loading="enCours"
                    @click="emit('confirm')"
                    class="flex-1"
                >
                    Confirmer
                </GlassButton>
            </div>
        </div>
    </Modal>
</template>
