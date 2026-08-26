<script setup>
/**
 * GlassSelect.vue
 *
 * A reusable select component implementing the "Liquid Glass" aesthetic.
 * Consistent styling with GlassInput, supports dark mode, labels, errors.
 *
 * `aria-describedby` is emitted only when there is an error to describe. Sent
 * unconditionally it pointed at a hidden element on every healthy field, so a
 * screen reader followed a reference to nothing — the same defect reported
 * against GlassInput as point 3 of #1385.
 *
 * A caller passing `hide-label` must still pass `label`: the element is
 * rendered `sr-only`, not dropped. Without one there is no accessible name at
 * all, which is how three selects in this app ended up unnamed.
 *
 * Usage:
 *   <GlassSelect v-model="selected" label="Catégorie" :options="categories" />
 *   <GlassSelect v-model="val" :options="[{ value: 'a', label: 'A' }]" />
 */
import { computed, useAttrs, getCurrentInstance } from 'vue'
import InputError from '@/Components/Form/InputError.vue'

defineOptions({
    inheritAttrs: false,
})

defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
    options: {
        type: Array,
        default: () => [],
    },
    label: {
        type: String,
        default: '',
    },
    hideLabel: {
        type: Boolean,
        default: false,
    },
    placeholder: {
        type: String,
        default: 'Sélectionner...',
    },

    /**
     * Le libellé du choix VIDE, quand « rien » est une réponse légitime.
     *
     * `placeholder` est une invite : elle se rend désactivée, et l'utilisateur
     * ne peut pas y revenir. Un champ facultatif a besoin de l'inverse — la
     * catégorie d'un exercice, par exemple, doit pouvoir être retirée après
     * coup.
     *
     * Les deux étaient confondus. Deux écrans passaient `placeholder="— Aucune
     * —"` et fabriquaient donc un « aucune » que personne ne pouvait choisir ;
     * deux autres construisaient l'option à la main dans leur tableau
     * d'`options` pour contourner exactement cela. Quatre écrans, le même
     * besoin, trois façons de l'écrire — dont une qui ne marchait pas.
     */
    emptyLabel: {
        type: String,
        default: '',
    },
    error: {
        type: String,
        default: '',
    },
    size: {
        type: String,
        default: 'md', // sm | md | lg
    },
    disabled: {
        type: Boolean,
        default: false,
    },
})

defineEmits(['update:modelValue'])

const attrs = useAttrs()
const instance = getCurrentInstance()

const selectId = computed(() => {
    return attrs.id || `glass-select-${instance?.uid}`
})

const errorId = computed(() => {
    return `${selectId.value}-error`
})

const sizeClasses = {
    // text-base (16px) is deliberate even at this compact height: iOS auto-zooms
    // any focused form control below 16px, which the viewport no longer suppresses.
    sm: 'min-h-[36px] text-base rounded-lg',
    md: 'min-h-touch text-base rounded-xl',
    lg: 'min-h-[56px] text-lg rounded-2xl',
}

const isRequired = computed(() => {
    return 'required' in attrs && attrs.required !== false
})
</script>

<template>
    <div class="w-full">
        <!-- Label -->
        <label
            v-if="label"
            :for="selectId"
            :class="['font-display-label text-text-muted mb-2 block', { 'sr-only': hideLabel }]"
        >
            {{ label }}
            <span v-if="isRequired" class="text-accent-danger-deep ml-0.5" aria-hidden="true">*</span>
        </label>

        <!-- Select -->
        <div class="relative">
            <select
                :id="selectId"
                :value="modelValue"
                @change="$emit('update:modelValue', $event.target.value)"
                :disabled="disabled"
                :aria-invalid="!!error"
                :aria-describedby="error ? errorId : undefined"
                :class="[
                    'glass-input w-full appearance-none pr-10',
                    sizeClasses[size],
                    {
                        'border-accent-danger focus:border-accent-danger focus:ring-accent-danger/20': error,
                        'cursor-not-allowed opacity-50': disabled,
                    },
                ]"
                v-bind="$attrs"
            >
                <option v-if="emptyLabel" value="">{{ emptyLabel }}</option>
                <option v-else-if="placeholder" value="" disabled>{{ placeholder }}</option>
                <option
                    v-for="option in options"
                    :key="typeof option === 'object' ? option.value : option"
                    :value="typeof option === 'object' ? option.value : option"
                >
                    {{ typeof option === 'object' ? option.label : option }}
                </option>
            </select>

            <!-- Chevron Icon -->
            <div
                class="text-text-muted pointer-events-none absolute top-1/2 right-3 -translate-y-1/2"
                aria-hidden="true"
            >
                <span class="material-symbols-outlined text-[20px]" aria-hidden="true">expand_more</span>
            </div>
        </div>

        <InputError :message="error" :id="errorId" />
    </div>
</template>
