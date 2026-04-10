<script setup>
/**
 * GlassSelect.vue
 *
 * A reusable select component implementing the "Liquid Glass" aesthetic.
 * Consistent styling with GlassInput, supports dark mode, labels, errors.
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

const props = defineProps({
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
    sm: 'min-h-[36px] text-sm rounded-lg',
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
            <span v-if="isRequired" class="ml-0.5 text-red-500" aria-hidden="true">*</span>
        </label>

        <!-- Select -->
        <div class="relative">
            <select
                :id="selectId"
                :value="modelValue"
                @change="$emit('update:modelValue', $event.target.value)"
                :disabled="disabled"
                :aria-invalid="!!error"
                :aria-describedby="errorId"
                :class="[
                    'glass-input w-full appearance-none pr-10 dark:border-slate-700 dark:bg-slate-800/80 dark:text-white',
                    sizeClasses[size],
                    {
                        'border-red-500 focus:border-red-500 focus:ring-red-500/20': error,
                        'cursor-not-allowed opacity-50': disabled,
                    },
                ]"
                v-bind="$attrs"
            >
                <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
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
                <span class="material-symbols-outlined text-[20px]">expand_more</span>
            </div>
        </div>

        <InputError :message="error" :id="errorId" />
    </div>
</template>
