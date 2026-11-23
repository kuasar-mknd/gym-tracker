<script setup>
/**
 * L'anneau du repos (#2434) : un disque rempli de ce qu'il reste sur la durée.
 * Le même dans la barre d'outils de la séance et dans la capsule des autres
 * onglets ; la taille et le placement viennent de l'appelant (`class`).
 */
defineProps({
    /** Ce qu'il reste du repos, de 0 à 1. */
    fraction: { type: Number, required: true },
})
</script>

<template>
    <span
        class="relative shrink-0 rounded-full"
        :style="{
            background: `conic-gradient(var(--color-accent-primary) ${fraction * 360}deg, var(--color-border) 0)`,
        }"
        aria-hidden="true"
    >
        <span class="bg-surface-page absolute inset-1 rounded-full"></span>
    </span>
</template>
