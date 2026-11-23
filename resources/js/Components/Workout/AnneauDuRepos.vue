<script setup>
/**
 * L'anneau du repos (#2434) : un tour rempli de ce qu'il reste sur la durée.
 * Le même dans la barre d'outils de la séance et dans la capsule des autres
 * onglets ; la taille et le placement viennent de l'appelant (`class`). Le
 * trait glisse d'une seconde à l'autre, comme la barre de la feuille.
 */
defineProps({
    /** Ce qu'il reste du repos, de 0 à 1. */
    fraction: { type: Number, required: true },
})
</script>

<template>
    <svg class="shrink-0 -rotate-90" viewBox="0 0 36 36" fill="none" aria-hidden="true">
        <circle cx="18" cy="18" r="15" stroke="var(--color-border)" stroke-width="5" />
        <circle
            v-if="fraction > 0"
            cx="18"
            cy="18"
            r="15"
            stroke="var(--color-accent-primary)"
            stroke-width="5"
            stroke-linecap="round"
            pathLength="100"
            stroke-dasharray="100"
            class="transition-[stroke-dashoffset] duration-1000 ease-linear"
            :style="{ strokeDashoffset: 100 - fraction * 100 }"
            data-testid="trait-de-l-anneau"
        />
    </svg>
</template>
