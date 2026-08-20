<script setup>
/**
 * GlassInput.vue
 *
 * A reusable input component implementing the consistent "Liquid Glass"
 * aesthetic. It supports text, email, password (with toggle visibility),
 * and search inputs, among others.
 *
 * The component features a clear button for specific input types,
 * configurable sizes, and accessible error handling.
 */

/**
 * Component Props
 *
 * @property {String|Number} modelValue - The bound value of the input.
 * @property {String} type - The input type (e.g., 'text', 'email', 'password'). Default: 'text'.
 * @property {String} label - The label displayed above the input field. Default: ''.
 * @property {Boolean} hideLabel - Whether to visually hide the label (still accessible via screen readers). Default: false.
 * @property {String} error - The error message to display below the input if validation fails. Default: ''.
 * @property {String} size - The physical size of the input. Accepts 'sm', 'md', 'lg'. Default: 'md'.
 * @property {Boolean} selectOnFocus - If true, selects all text within the input upon gaining focus. Default: false.
 * @property {String} inputClass - Custom classes to apply to the input element.
 */

/**
 * Component Emits
 *
 * @event update:modelValue - Emitted when the input value changes, allowing for v-model two-way binding.
 */

import { computed, useAttrs, getCurrentInstance, ref, useSlots } from 'vue'
import InputError from '@/Components/Form/InputError.vue'

defineOptions({
    inheritAttrs: false,
})

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
    dusk: {
        type: String,
        default: null,
    },
    type: {
        type: String,
        default: 'text',
    },
    label: {
        type: String,
        default: '',
    },
    hideLabel: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    size: {
        type: String,
        default: 'md', // sm | md | lg
    },
    selectOnFocus: {
        type: Boolean,
        default: false,
    },
    inputClass: {
        type: String,
        default: '',
    },
})

defineEmits(['update:modelValue'])

const attrs = useAttrs()
const slots = useSlots()
const instance = getCurrentInstance()
const input = ref(null)

const inputId = computed(() => {
    return attrs.id || `glass-input-${instance?.uid}`
})

const errorId = computed(() => {
    return `${inputId.value}-error`
})

const sizeClasses = {
    // text-base (16px) is deliberate even at this compact height: iOS auto-zooms
    // any focused form control below 16px, which the viewport no longer suppresses.
    sm: 'min-h-[36px] text-base rounded-lg',
    md: 'min-h-touch text-base rounded-xl',
    lg: 'min-h-[56px] text-lg rounded-2xl',
}

// Password toggle logic
const isPassword = computed(() => props.type === 'password')
const showPassword = ref(false)
const inputType = computed(() => {
    if (isPassword.value) {
        return showPassword.value ? 'text' : 'password'
    }
    return props.type
})

// Clear button logic
const hasClearButton = computed(() => {
    return ['text', 'search', 'email', 'url', 'tel'].includes(props.type)
})

/**
 * Un attribut booléen posé nu vaut la chaîne vide, pas `true`.
 *
 * `<GlassInput readonly />` — la forme habituelle en template — donne
 * `attrs.readonly === ''`, et `!''` vaut `true` : le champ en lecture seule
 * proposait donc un bouton pour l'effacer. La garde ne marchait que si
 * l'appelant écrivait `:readonly="true"`. `false` et `undefined` sont les deux
 * seules valeurs qui désactivent réellement l'attribut ; tout le reste, chaîne
 * vide comprise, l'active.
 */
const attributeIsSet = (value) => value !== undefined && value !== false && value !== null

const showClearButton = computed(() => {
    /*
     * La longueur est testée sur la chaîne, pas sur la véracité de la valeur.
     *
     * `props.modelValue &&` court-circuitait sur `0` avant même d'atteindre le
     * test de longueur, si bien qu'un champ numérique valant zéro — un poids,
     * un nombre de répétitions, un temps de repos — ne pouvait pas être vidé
     * d'un clic. `null` et `undefined` deviennent la chaîne vide, ce qui est
     * bien le cas où il n'y a rien à effacer.
     */
    return (
        hasClearButton.value &&
        String(props.modelValue ?? '').length > 0 &&
        !attributeIsSet(attrs.disabled) &&
        !attributeIsSet(attrs.readonly)
    )
})

const hasSuffix = computed(() => !!slots.suffix)

const isRequired = computed(() => {
    // Check for 'required' in attrs (Vue treats presence as empty string usually, or true if bound)
    return 'required' in attrs && attrs.required !== false
})

/**
 * `defineExpose` remplace ce qu'une ref de template posée sur ce composant rend :
 * le parent reçoit cet objet, jamais l'élément racine.
 *
 * Un parent qui veut savoir si le champ a le focus ne peut donc pas comparer sa
 * ref à `document.activeElement` — la comparaison est insatisfiable, et la
 * branche qu'elle garde ne s'exécute jamais. C'est ce qui rendait Échap inerte
 * sur la recherche de la bibliothèque. `el` est ce qui rend ce test possible, et
 * `blur` la contrepartie de `focus` dont il a besoin une fois qu'il passe.
 */
defineExpose({
    el: input,
    focus: () => input.value?.focus(),
    blur: () => input.value?.blur(),
    select: () => input.value?.select(),
})
</script>

<template>
    <div class="w-full">
        <!-- Main Label -->
        <label
            v-if="label"
            :for="inputId"
            :class="['font-display-label text-text-muted mb-2 block', { 'sr-only': hideLabel }]"
        >
            {{ label }}
            <span v-if="isRequired" class="ml-0.5 text-red-500" aria-hidden="true">*</span>
        </label>

        <!-- Standard input -->
        <div class="relative">
            <!-- Search Icon -->
            <div
                v-if="type === 'search'"
                class="text-text-muted pointer-events-none absolute top-1/2 left-3 -translate-y-1/2"
                aria-hidden="true"
            >
                <span class="material-symbols-outlined text-[24px]" aria-hidden="true">search</span>
            </div>

            <input
                ref="input"
                :id="inputId"
                :type="inputType"
                :value="modelValue"
                :dusk="dusk"
                @input="$emit('update:modelValue', $event.target.value)"
                @focus="selectOnFocus ? $event.target.select() : null"
                :aria-invalid="!!error"
                :aria-describedby="error ? errorId : undefined"
                :class="[
                    'glass-input dark:placeholder:text-text-muted/50 dark:border-slate-700 dark:bg-slate-800/80 dark:text-white',
                    sizeClasses[size],
                    {
                        'border-red-500 focus:border-red-500 focus:ring-red-500/20': error,
                        'pl-10': type === 'search',
                        'pr-12':
                            (hasClearButton || isPassword || hasSuffix) &&
                            !(hasSuffix && (hasClearButton || isPassword)),
                        'pr-32': hasSuffix && (hasClearButton || isPassword),
                    },
                    inputClass,
                ]"
                v-bind="$attrs"
            />

            <!-- Right Decorations Container -->
            <div class="absolute top-1/2 right-2 flex -translate-y-1/2 items-center gap-0.5">
                <!-- Clear Button -->
                <button
                    v-if="showClearButton"
                    type="button"
                    @click="$emit('update:modelValue', '')"
                    class="text-text-muted hover:text-text-main focus-visible:ring-electric-orange relative rounded-full p-1 transition-colors before:absolute before:-inset-2.5 before:content-[''] focus-visible:ring-2 focus-visible:outline-none"
                    aria-label="Effacer le texte"
                    v-press
                >
                    <span class="material-symbols-outlined text-lg leading-none" aria-hidden="true">cancel</span>
                </button>

                <!-- Password Toggle -->
                <button
                    v-if="isPassword"
                    type="button"
                    @click="showPassword = !showPassword"
                    class="text-text-muted hover:text-text-main focus-visible:ring-electric-orange relative rounded-full p-1 transition-colors before:absolute before:-inset-2.5 before:content-[''] focus-visible:ring-2 focus-visible:outline-none"
                    :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
                    :title="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
                    v-press
                >
                    <span class="material-symbols-outlined text-lg leading-none" aria-hidden="true">
                        {{ showPassword ? 'visibility_off' : 'visibility' }}
                    </span>
                </button>

                <!-- Suffix Slot -->
                <slot name="suffix" />
            </div>
        </div>

        <InputError :message="error" :id="errorId" />
    </div>
</template>
