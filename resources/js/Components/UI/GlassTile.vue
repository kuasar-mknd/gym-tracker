<script>
export const TONS = ['primary', 'secondary', 'info']
</script>

<script setup>
/**
 * Une grande tuile à appuyer : un choix (Homme / Femme, `active` vrai ou faux)
 * ou une action rapide (+ 250 ml, `active` absent). Même hauteur, même
 * arrondi, même ton dans tous les outils.
 */
import { computed } from 'vue'

const props = defineProps({
    label: {
        type: String,
        required: true,
    },
    icon: {
        type: String,
        default: null,
    },
    /** Sous le libellé : « 10 kg », « 3 séries »… */
    detail: {
        type: String,
        default: null,
    },
    /** `null` pour une action ; vrai ou faux pour un choix. */
    active: {
        type: Boolean,
        default: null,
    },
    ton: {
        type: String,
        default: 'primary',
        validator: (valeur) => TONS.includes(valeur),
    },
})

const ACTIFS = {
    primary: 'border-accent-primary bg-accent-primary text-text-on-dark-accent shadow-glow-orange',
    secondary: 'border-accent-secondary bg-accent-secondary text-text-on-dark-accent shadow-glow-pink',
    info: 'border-accent-info bg-accent-info text-text-main shadow-glow-cyan',
}

const ANNEAUX = {
    primary: 'focus-visible:ring-accent-primary',
    secondary: 'focus-visible:ring-accent-secondary',
    info: 'focus-visible:ring-accent-info',
}

const classes = computed(() => [
    'flex min-h-16 flex-col items-center justify-center gap-0.5 rounded-2xl border px-3 py-2 backdrop-blur-md transition focus-visible:ring-2 focus-visible:outline-none active:scale-95',
    ANNEAUX[props.ton],
    props.active
        ? ACTIFS[props.ton]
        : 'border-glass-border bg-surface-card/50 text-text-muted hover:bg-surface-card/80 hover:text-text-main',
])
</script>

<template>
    <button type="button" :aria-pressed="active === null ? undefined : active" :class="classes">
        <span v-if="icon" class="material-symbols-outlined text-2xl" aria-hidden="true">{{ icon }}</span>
        <span class="font-display text-sm font-black whitespace-nowrap uppercase">{{ label }}</span>
        <span v-if="detail" class="text-2xs font-bold opacity-80">{{ detail }}</span>
    </button>
</template>
