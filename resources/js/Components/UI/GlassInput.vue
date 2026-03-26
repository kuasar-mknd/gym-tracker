<script setup>
import { computed, useAttrs, getCurrentInstance, ref } from 'vue'
import InputError from '@/Components/Form/InputError.vue'

defineOptions({
    inheritAttrs: false,
})

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
    type: {
        type: String,
        default: 'text',
    },
    label: {
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
    selectOnFocus: {
        type: Boolean,
        default: false,
    },
})

defineEmits(['update:modelValue'])

const attrs = useAttrs()
const instance = getCurrentInstance()
const inputId = computed(() => {
    return attrs.id || `glass-input-${instance?.uid}`
})

const errorId = computed(() => {
    return `${inputId.value}-error`
})

const sizeClasses = {
    sm: 'min-h-[36px] text-sm rounded-lg',
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

const showClearButton = computed(() => {
    return (
        hasClearButton.value &&
        props.modelValue &&
        String(props.modelValue).length > 0 &&
        !attrs.disabled &&
        !attrs.readonly
    )
})

const isRequired = computed(() => {
    // Check for 'required' in attrs (Vue treats presence as empty string usually, or true if bound)
    return 'required' in attrs && attrs.required !== false
})
</script>

<template>
    <div class="w-full">
        <!-- Main Label -->
        <label v-if="label" :for="inputId" class="font-display-label text-text-muted mb-2 block">
            {{ label }}
            <span v-if="isRequired" class="ml-0.5 text-red-500" aria-hidden="true">*</span>
        </label>

        <!-- Standard input -->
        <div class="relative">
            <input
                :id="inputId"
                :type="inputType"
                :value="modelValue"
                @input="$emit('update:modelValue', $event.target.value)"
                @focus="selectOnFocus ? $event.target.select() : null"
                :aria-invalid="!!error"
                :aria-describedby="error ? errorId : undefined"
                :class="[
                    'glass-input',
                    sizeClasses[size],
                    {
                        'border-red-500 focus:border-red-500 focus:ring-red-500/20': error,
                        'pr-10': hasClearButton || isPassword, // Add padding for clear or toggle button
                    },
                ]"
                v-bind="$attrs"
            />

            <!-- Clear Button -->
            <button
                v-if="showClearButton"
                type="button"
                @click="$emit('update:modelValue', '')"
                class="text-text-muted hover:text-text-main absolute top-1/2 right-3 -translate-y-1/2 rounded-full p-1 transition-colors"
                aria-label="Effacer le texte"
                v-press
                tabindex="-1"
            >
                <span class="material-symbols-outlined text-lg leading-none" aria-hidden="true">cancel</span>
            </button>

            <!-- Password Toggle -->
            <button
                v-if="isPassword"
                type="button"
                @click="showPassword = !showPassword"
                class="text-text-muted hover:text-text-main absolute top-1/2 right-3 -translate-y-1/2 rounded-full p-1 transition-colors"
                :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
                :title="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
                v-press
            >
                <span class="material-symbols-outlined text-lg leading-none" aria-hidden="true">
                    {{ showPassword ? 'visibility_off' : 'visibility' }}
                </span>
            </button>
        </div>

        <InputError :message="error" :id="errorId" />
    </div>
</template>
