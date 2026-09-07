<script>
export const TAILLES = {
    xs: 'text-base',
    sm: 'text-xl',
    md: 'text-2xl',
    lg: 'text-3xl',
    xl: 'text-4xl',
    '2xl': 'text-5xl',
    hero: 'text-6xl',
}
</script>

<script setup>
/**
 * Une icône Material Symbols à une des sept tailles de la charte. Décorative
 * par défaut (`aria-hidden`) ; avec `label`, elle porte un nom pour les
 * lecteurs d'écran.
 */
defineProps({
    name: {
        type: String,
        required: true,
    },
    size: {
        type: String,
        default: 'md',
        validator: (valeur) => valeur in TAILLES,
    },
    fill: {
        type: Boolean,
        default: false,
    },
    label: {
        type: String,
        default: null,
    },
})
</script>

<template>
    <span
        class="material-symbols-outlined"
        :class="[TAILLES[size], { 'icone-pleine': fill }]"
        :role="label ? 'img' : undefined"
        :aria-label="label ?? undefined"
        :aria-hidden="label ? undefined : 'true'"
    >
        {{ name }}
    </span>
</template>
