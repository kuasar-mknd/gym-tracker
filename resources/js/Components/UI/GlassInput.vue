<script setup>
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
})

defineEmits(['update:modelValue'])

const sizeClasses = {
    sm: 'min-h-[36px] text-sm',
    md: 'min-h-[44px] text-base',
    lg: 'min-h-[52px] text-lg',
}
</script>

<template>
    <div class="w-full">
        <label v-if="label" class="mb-1.5 block text-sm font-medium text-white/70">
            {{ label }}
        </label>
        <input
            :type="type"
            :value="modelValue"
            @input="$emit('update:modelValue', $event.target.value)"
            :class="['glass-input', sizeClasses[size], { 'border-accent-danger focus:border-accent-danger': error }]"
            v-bind="$attrs"
        />
        <p v-if="error" class="mt-1.5 text-sm text-red-400">
            {{ error }}
        </p>
    </div>
</template>
