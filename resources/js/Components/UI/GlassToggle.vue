<script setup>
/**
 * GlassToggle.vue
 *
 * A reusable toggle switch component with the "Liquid Glass" aesthetic.
 * Supports light/dark mode with proper contrast in both states.
 *
 * Usage:
 *   <GlassToggle v-model="isEnabled" label="Feature" description="Enable this feature" />
 *   <GlassToggle v-model="isOn" size="sm" />
 */
import { computed } from 'vue'

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    label: {
        type: String,
        default: '',
    },
    description: {
        type: String,
        default: '',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    size: {
        type: String,
        default: 'md', // sm | md
        validator: (value) => ['sm', 'md'].includes(value),
    },
})

const emit = defineEmits(['update:modelValue'])

const toggle = () => {
    if (!props.disabled) {
        emit('update:modelValue', !props.modelValue)
    }
}

const trackClasses = computed(() => {
    const base =
        'relative inline-flex shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none'

    const sizeMap = {
        sm: 'h-5 w-9',
        md: 'h-6 w-11',
    }

    const stateClass = props.modelValue
        ? 'bg-electric-orange focus-visible:ring-electric-orange/30'
        : 'bg-slate-300 dark:bg-slate-600 focus-visible:ring-slate-400/30'

    const disabledClass = props.disabled ? 'opacity-50 cursor-not-allowed' : ''

    return [base, sizeMap[props.size], stateClass, disabledClass]
})

const thumbClasses = computed(() => {
    const base =
        'pointer-events-none inline-block transform rounded-full bg-white shadow-lg ring-0 transition duration-200 ease-in-out'

    const sizeMap = {
        sm: props.modelValue ? 'h-4 w-4 translate-x-4' : 'h-4 w-4 translate-x-0',
        md: props.modelValue ? 'h-5 w-5 translate-x-5' : 'h-5 w-5 translate-x-0',
    }

    return [base, sizeMap[props.size]]
})
</script>

<template>
    <div
        :class="[
            'flex items-center justify-between',
            { 'gap-4': label || description },
        ]"
    >
        <!-- Label & Description -->
        <div v-if="label || description" class="min-w-0 flex-1">
            <p
                v-if="label"
                class="text-text-main text-sm font-medium dark:text-white"
                :class="{ 'opacity-50': disabled }"
            >
                {{ label }}
            </p>
            <p
                v-if="description"
                class="text-text-muted mt-0.5 text-xs"
                :class="{ 'opacity-50': disabled }"
            >
                {{ description }}
            </p>
        </div>

        <!-- Switch -->
        <button
            type="button"
            role="switch"
            :aria-checked="modelValue"
            :aria-label="label || undefined"
            :disabled="disabled"
            :class="trackClasses"
            @click="toggle"
            v-press="{ haptic: 'toggle' }"
        >
            <span :class="thumbClasses" aria-hidden="true" />
        </button>
    </div>
</template>
