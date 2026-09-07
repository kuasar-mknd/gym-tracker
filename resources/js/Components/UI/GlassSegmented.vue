<script>
export const TAILLES = ['sm', 'md']
</script>

<script setup>
/**
 * Un choix exclusif entre deux et cinq options : période, onglet, unité, sexe.
 * Une seule apparence pour ce rôle, là où sept écrans en dessinaient cinq.
 * Le clavier passe d'une option à l'autre par les flèches, comme un groupe
 * de boutons radio ; `aria-pressed` dit laquelle est retenue.
 */
import { computed } from 'vue'
import { triggerHaptic } from '@/composables/useHaptics'

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: null,
    },
    /** `[{ value, label, icon? }]` */
    options: {
        type: Array,
        required: true,
        validator: (valeurs) =>
            valeurs.length >= 2 && valeurs.every((option) => 'value' in option && 'label' in option),
    },
    /** Le nom du groupe, pour les lecteurs d'écran. */
    label: {
        type: String,
        required: true,
    },
    size: {
        type: String,
        default: 'md',
        validator: (valeur) => TAILLES.includes(valeur),
    },
    /** Les options se partagent toute la largeur. */
    bloc: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:modelValue'])

const choisir = (valeur) => {
    if (valeur === props.modelValue) return
    triggerHaptic('toggle')
    emit('update:modelValue', valeur)
}

const auClavier = (evenement, index) => {
    const pas = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[evenement.key]
    if (pas === undefined) return
    evenement.preventDefault()
    const suivant = props.options[(index + pas + props.options.length) % props.options.length]
    choisir(suivant.value)
    evenement.currentTarget.parentElement.querySelectorAll('button')[props.options.indexOf(suivant)]?.focus()
}

const classesOption = computed(() => [
    'focus-visible:ring-accent-primary relative flex items-center justify-center gap-1 rounded-lg font-bold whitespace-nowrap transition focus-visible:ring-2 focus-visible:outline-none',
    props.size === 'sm'
        ? // La cible reste à 44 px par le pseudo-élément, la pilule à 36.
          "min-h-9 px-3 text-xs before:absolute before:-inset-1 before:content-['']"
        : 'min-h-touch px-4 text-sm',
    props.bloc ? 'flex-1' : '',
])
</script>

<template>
    <div
        role="group"
        :aria-label="label"
        class="border-glass-border bg-surface-card/50 inline-flex rounded-xl border p-1 shadow-sm backdrop-blur-sm"
        :class="{ 'flex w-full': bloc }"
    >
        <button
            v-for="(option, index) in options"
            :key="option.value"
            type="button"
            :aria-pressed="option.value === modelValue"
            :class="[
                classesOption,
                option.value === modelValue
                    ? 'bg-text-main text-text-on-dark-accent shadow-sm'
                    : 'text-text-muted hover:text-text-main',
            ]"
            @click="choisir(option.value)"
            @keydown="auClavier($event, index)"
        >
            <span v-if="option.icon" class="material-symbols-outlined text-lg" aria-hidden="true">{{
                option.icon
            }}</span>
            {{ option.label }}
        </button>
    </div>
</template>
