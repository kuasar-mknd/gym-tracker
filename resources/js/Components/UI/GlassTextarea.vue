<script setup>
/**
 * Le champ multiligne de la charte, frère de `GlassInput` : même étiquette,
 * même erreur, un compteur quand une longueur est posée, et une hauteur qui
 * suit le texte au lieu d'une barre de défilement dans le champ.
 */
import { computed, nextTick, onMounted, ref, useId, watch } from 'vue'

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        required: true,
    },
    hideLabel: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    rows: {
        type: Number,
        default: 3,
    },
    maxlength: {
        type: Number,
        default: null,
    },
    placeholder: {
        type: String,
        default: '',
    },
    dusk: {
        type: String,
        default: null,
    },
})

const emit = defineEmits(['update:modelValue'])

const id = useId()
const champ = ref(null)

const ajusterLaHauteur = () => {
    const element = champ.value
    if (!element) return
    element.style.height = 'auto'
    element.style.height = `${element.scrollHeight}px`
}

onMounted(() => {
    ajusterLaHauteur()
    // La police arrive après le montage et change la hauteur des lignes.
    document.fonts?.ready.then(ajusterLaHauteur)
})
watch(
    () => props.modelValue,
    () => nextTick(ajusterLaHauteur),
)

const longueur = computed(() => (props.modelValue ?? '').length)
const compteur = computed(() => (props.maxlength ? `${longueur.value} / ${props.maxlength}` : null))
const depassement = computed(() => props.maxlength !== null && longueur.value > props.maxlength)
const decritPar = computed(
    () =>
        [compteur.value ? `${id}-compteur` : null, props.error ? `${id}-erreur` : null].filter(Boolean).join(' ') ||
        undefined,
)
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-baseline justify-between">
            <label :for="id" class="sur-titre text-text-main" :class="{ 'sr-only': hideLabel }">{{ label }}</label>
            <span
                v-if="compteur"
                :id="`${id}-compteur`"
                class="text-xs tabular-nums"
                :class="depassement ? 'text-accent-danger-deep' : 'text-text-muted'"
                aria-live="polite"
                >{{ compteur }}</span
            >
        </div>
        <textarea
            :id="id"
            ref="champ"
            :value="modelValue ?? ''"
            :rows="rows"
            :maxlength="maxlength ?? undefined"
            :placeholder="placeholder"
            :dusk="dusk ?? undefined"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="decritPar"
            class="glass-input w-full resize-none overflow-hidden leading-relaxed"
            :class="{ 'border-accent-danger focus-visible:ring-accent-danger': error }"
            @input="emit('update:modelValue', $event.target.value)"
        />
        <p v-if="error" :id="`${id}-erreur`" class="text-accent-danger-deep text-xs font-bold" role="alert">
            {{ error }}
        </p>
    </div>
</template>
