<script setup>
/**
 * Le champ multiligne de la charte, frère de `GlassInput` : même étiquette,
 * même erreur, un compteur quand une longueur est posée, et une hauteur qui
 * suit le texte au lieu d'une barre de défilement dans le champ.
 */
import { computed, nextTick, onMounted, ref, useId, watch } from 'vue'
import { compte } from '@/Utils/nombre'

/**
 * Les caractères restants qui font parler la région annoncée, du plus serré au
 * plus large (#2121). Le compteur visible n'est pas une région annoncée : il
 * changeait à chaque frappe, et un lecteur d'écran mettait en file « 1 / 1000 »,
 * « 2 / 1000 »… par-dessus l'écho de la saisie. Le champ le désigne par
 * `aria-describedby`, qui le fait lire à la prise du focus. Seule l'approche de
 * la limite s'annonce, une fois par palier franchi : à 50 caractères restants,
 * à 25, à 10, puis à la limite, que `maxlength` empêche de dépasser sans rien
 * dire.
 */
const PALIERS = [0, 10, 25, 50]

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
const restant = computed(() => (props.maxlength === null ? null : props.maxlength - longueur.value))
const palier = computed(() =>
    restant.value === null ? null : (PALIERS.find((seuil) => restant.value <= seuil) ?? null),
)

/*
 * Ce que la région annoncée dit : rien au montage (un texte prérempli près de
 * la limite ne parle pas à l'ouverture de la page), puis une phrase à chaque
 * palier franchi, et de nouveau rien quand le texte s'éloigne de la limite.
 */
const annonce = ref('')

watch(palier, (seuil) => {
    if (seuil === null) {
        annonce.value = ''
    } else {
        annonce.value =
            seuil === 0 ? 'Limite atteinte.' : `Plus que ${compte(restant.value, 'caractère', 'caractères')}.`
    }
})
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
                >{{ compteur }}</span
            >
        </div>
        <span v-if="maxlength !== null" class="sr-only" aria-live="polite" aria-atomic="true">{{ annonce }}</span>
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
