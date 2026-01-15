<script setup>
defineProps({
    variant: {
        type: String,
        default: 'default', // default | primary | danger | ghost
    },
    size: {
        type: String,
        default: 'md', // sm | md | lg
    },
    loading: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    type: {
        type: String,
        default: 'button',
    },
})

const sizeClasses = {
    sm: 'min-h-[36px] px-3 py-1.5 text-sm',
    md: 'min-h-[44px] px-5 py-2.5 text-base',
    lg: 'min-h-[52px] px-6 py-3 text-lg',
}
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :class="[
            'glass-button font-semibold transition-all active:scale-95',
            sizeClasses[size],
            {
                'glass-button-primary': variant === 'primary',
                'glass-button-danger': variant === 'danger',
                'border-transparent bg-transparent': variant === 'ghost',
                'cursor-not-allowed opacity-50': disabled,
                'cursor-wait': loading,
            },
        ]"
    >
        <svg
            v-if="loading"
            class="mr-2 h-4 w-4 animate-spin"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
        >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            />
        </svg>
        <slot />
    </button>
</template>
