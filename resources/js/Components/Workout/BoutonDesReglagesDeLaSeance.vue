<script setup>
/**
 * Le bouton qui ouvre les réglages de la séance : dans l'en-tête mobile sous
 * 1 024 px, dans l'en-tête de page au-delà (lot 8, #2434), où celui du
 * mobile n'existe pas. Le parent écoute son clic ; désactivé tant que le
 * serveur ne connaît pas la séance, il renvoie à ce que dit son état.
 */
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { ID_DE_L_ETAT_DE_LA_SEANCE } from '@/composables/useSeanceEnAttente'

defineProps({
    /** La séance a été démarrée sans réseau : ses réglages attendent le serveur. */
    desactive: { type: Boolean, default: false },
})
</script>

<template>
    <button
        v-press
        type="button"
        :disabled="desactive"
        :aria-describedby="desactive ? ID_DE_L_ETAT_DE_LA_SEANCE : undefined"
        class="text-text-muted border-surface-card bg-surface-card/60 focus-visible:ring-accent-primary flex size-11 shrink-0 items-center justify-center rounded-xl border transition focus-visible:ring-2 focus-visible:outline-none"
        aria-label="Paramètres de la séance"
    >
        <GlassIcon name="settings" />
    </button>
</template>
