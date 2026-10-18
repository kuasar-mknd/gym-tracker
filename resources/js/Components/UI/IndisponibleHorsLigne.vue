<script setup>
import GlassIcon from '@/Components/UI/GlassIcon.vue'

/**
 * Ce que montre une partie de page dont les données ne viennent pas sans
 * réseau (#2048, partie E) : le créneau `#rescue` d'un `<Deferred>` dont la
 * requête a échoué faute de réseau, ou dont une copie hors ligne n'a pas la
 * prop. Le reste de la page reste lisible ; au retour du réseau, la page
 * redemande ce qui manquait (`Utils/visitesHorsLigne.js`).
 */
defineProps({
    texte: {
        type: String,
        default: 'Indisponible hors ligne',
    },
})
</script>

<template>
    <div
        class="border-border text-text-muted flex items-center gap-2 rounded-xl border px-4 py-3 text-sm"
        role="status"
        data-indisponible-hors-ligne
    >
        <GlassIcon name="cloud_off" size="sm" />
        <span>{{ texte }}</span>
    </div>
</template>
