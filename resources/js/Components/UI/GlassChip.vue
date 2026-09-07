<script>
export const TAILLES = ['sm', 'md']
</script>

<script setup>
/**
 * Une pilule de filtre : catégorie d'exercice, de badge, partie du corps,
 * métrique du journal. Elle dit si elle est retenue par `aria-pressed`, et
 * garde une cible de 44 px même en petite taille.
 */
import { computed } from 'vue'

const props = defineProps({
    active: {
        type: Boolean,
        default: false,
    },
    icon: {
        type: String,
        default: null,
    },
    size: {
        type: String,
        default: 'md',
        validator: (valeur) => TAILLES.includes(valeur),
    },
    /** Les classes de l'état retenu quand la charte donne une couleur au sujet (catégories d'exercice). */
    classeActive: {
        type: String,
        default: null,
    },
})

const classes = computed(() => [
    'focus-visible:ring-accent-primary relative inline-flex shrink-0 items-center gap-2 rounded-full font-bold whitespace-nowrap uppercase transition focus-visible:ring-2 focus-visible:outline-none',
    props.size === 'sm'
        ? "min-h-8 px-3 text-xs tracking-wide before:absolute before:-inset-1.5 before:content-['']"
        : 'min-h-touch px-5 text-sm tracking-wide',
    props.active
        ? (props.classeActive ?? 'bg-text-main text-text-on-dark-accent shadow-cast')
        : 'border-glass-border bg-surface-card/60 text-text-muted hover:text-text-main border backdrop-blur-sm',
])
</script>

<template>
    <button type="button" :aria-pressed="active" :class="classes">
        <span v-if="icon" class="material-symbols-outlined text-lg" aria-hidden="true">{{ icon }}</span>
        <slot />
    </button>
</template>
